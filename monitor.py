#!/usr/bin/env python3

# apt update
# apt install -y python3-pip   # if pip3 is not installed
# pip3 install psutil
# python3 -m pip install matplotlib
# python3 -m pip install requests

# nano /etc/systemd/system/nic-monitor.service
# [Unit]
# Description=NIC Monitor Service
# After=network.target

# [Service]
# Type=simple
# ExecStart=/usr/bin/python3 /root/monitor.py
# WorkingDirectory=/root
# Restart=always
# RestartSec=5
# User=root

# # optional hardening
# NoNewPrivileges=true

# [Install]
# WantedBy=multi-user.target

# systemctl daemon-reload
# systemctl enable nic-monitor
# systemctl start nic-monitor
# systemctl status nic-monitor
# journalctl -u nic-monitor -f

import os
import time
import csv
import subprocess
import psutil
import datetime
import matplotlib.pyplot as plt
import requests

# ================================
# CONFIG
# ================================
NIC = "eno49"
CSV_FILE = "/var/log/nic_monitor.csv"
GRAPH_FILE = "/var/log/nic_monitor.png"

BOT_TOKEN = "YOUR_BOT_TOKEN"
CHAT_ID = "YOUR_CHAT_ID"

THRESHOLDS = {
    "CONNTRACK_PCT": 90,
    "SOFTIRQ_PCT": 80,
    "SOFTNET_DROPS": 50,
    "RX_DROPPED_DELTA": 100,
    "RX_MISSED_DELTA": 100,
    "LOAD_AVG": 20,
}

SAMPLE_INTERVAL = 60
GRAPH_INTERVAL = 600

# ================================
def send_telegram(msg):
    try:
        requests.post(
            f"https://api.telegram.org/bot{BOT_TOKEN}/sendMessage",
            data={"chat_id": CHAT_ID, "text": msg},
            timeout=5
        )
    except Exception as e:
        print("Telegram error:", e)

# ================================
# METRICS
# ================================
def get_nic_stats(nic):
    stats = {}
    base = f"/sys/class/net/{nic}/statistics"

    fields = [
        "rx_bytes","tx_bytes",
        "rx_packets","tx_packets",
        "rx_errors","tx_errors",
        "rx_dropped","tx_dropped"
    ]

    for f in fields:
        try:
            with open(f"{base}/{f}") as x:
                stats[f] = int(x.read().strip())
        except:
            stats[f] = 0

    try:
        out = subprocess.check_output(["ethtool", "-S", nic], text=True)
        for line in out.splitlines():
            if ":" in line:
                k, v = line.split(":", 1)
                if v.strip().isdigit():
                    stats[k.strip()] = int(v.strip())
    except:
        pass

    return stats


def get_conntrack():
    try:
        with open("/proc/sys/net/netfilter/nf_conntrack_count") as f:
            c = int(f.read().strip())
        with open("/proc/sys/net/netfilter/nf_conntrack_max") as f:
            m = int(f.read().strip())
        return c, m, (c * 100 // m if m else 0)
    except:
        return 0, 0, 0


def get_softirq():
    vals = psutil.cpu_times_percent(interval=1, percpu=True)
    arr = [v.softirq for v in vals]
    return sum(arr) / len(arr), arr


def get_softnet():
    total = 0
    try:
        with open("/proc/net/softnet_stat") as f:
            for line in f:
                parts = line.split()
                total += int(parts[2], 16)
    except:
        pass
    return total


def get_load():
    return os.getloadavg()[0]

# ================================
# STATE
# ================================
last = {}
last_softnet = 0
last_conn = 0
graph = []

os.makedirs(os.path.dirname(CSV_FILE), exist_ok=True)

if not os.path.exists(CSV_FILE):
    with open(CSV_FILE, "w") as f:
        csv.writer(f).writerow([
            "time","rx_b_d","tx_b_d","rx_p_d","tx_p_d",
            "rx_err","tx_err","rx_drop","tx_drop",
            "softirq","softnet_delta","load1",
            "conn","conn_delta"
        ])

# ================================
# LOOP
# ================================
while True:
    ts = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    nic = get_nic_stats(NIC)

    rx_b_d = nic["rx_bytes"] - last.get("rx_bytes", nic["rx_bytes"])
    tx_b_d = nic["tx_bytes"] - last.get("tx_bytes", nic["tx_bytes"])
    rx_p_d = nic["rx_packets"] - last.get("rx_packets", nic["rx_packets"])
    tx_p_d = nic["tx_packets"] - last.get("tx_packets", nic["tx_packets"])

    rx_drop_d = nic["rx_dropped"] - last.get("rx_dropped", nic["rx_dropped"])
    tx_drop_d = nic["tx_dropped"] - last.get("tx_dropped", nic["tx_dropped"])

    rx_missed_d = nic.get("rx_missed_errors", 0) - last.get("rx_missed_errors", 0)

    last.update(nic)

    conn, conn_max, conn_pct = get_conntrack()
    conn_delta = conn - last_conn
    last_conn = conn

    softirq_avg, softirq_percpu = get_softirq()

    softnet = get_softnet()
    softnet_delta = softnet - last_softnet
    last_softnet = softnet

    load1 = get_load()

    # CSV
    with open(CSV_FILE, "a") as f:
        csv.writer(f).writerow([
            ts, rx_b_d, tx_b_d,
            rx_p_d, tx_p_d,
            nic["rx_errors"], nic["tx_errors"],
            rx_drop_d, tx_drop_d,
            softirq_avg,
            softnet_delta,
            load1,
            conn,
            conn_delta
        ])

    msg = f"""
🖥 SERVER [{ts}]
Load: {load1:.2f}
Conntrack: {conn}/{conn_max} ({conn_pct}%)

SoftIRQ: {softirq_avg:.2f}%
Softnet Δ: {softnet_delta}

RX Δ packets: {rx_p_d}
RX Δ bytes: {rx_b_d}
RX drops Δ: {rx_drop_d}
RX missed Δ: {rx_missed_d}
"""

    alert = (
        conn_pct > THRESHOLDS["CONNTRACK_PCT"] or
        softirq_avg > THRESHOLDS["SOFTIRQ_PCT"] or
        softnet_delta > THRESHOLDS["SOFTNET_DROPS"] or
        rx_drop_d > THRESHOLDS["RX_DROPPED_DELTA"] or
        rx_missed_d > THRESHOLDS["RX_MISSED_DELTA"] or
        load1 > THRESHOLDS["LOAD_AVG"]
    )

    if alert:
        send_telegram(msg)

    graph.append({
        "rx": rx_p_d,
        "drop": rx_drop_d,
        "softirq": softirq_avg,
        "load": load1,
        "conn": conn_pct
    })

    if len(graph) >= (GRAPH_INTERVAL // SAMPLE_INTERVAL):
        plt.figure(figsize=(12,6))
        plt.plot([x["rx"] for x in graph], label="RX packets Δ")
        plt.plot([x["drop"] for x in graph], label="RX drops Δ")
        plt.plot([x["softirq"] for x in graph], label="SoftIRQ %")
        plt.plot([x["load"] for x in graph], label="Load")
        plt.plot([x["conn"] for x in graph], label="Conntrack %")

        plt.legend()
        plt.tight_layout()
        plt.savefig(GRAPH_FILE)
        plt.close()

        graph = []

    time.sleep(SAMPLE_INTERVAL)