#!/usr/bin/env python3
"""
Compare response styles of two API endpoints for ALL actions.
Usage: python3 compare_all_actions.py
"""

import requests
import json
import sys
import re
from typing import Dict, Any, Optional, Tuple

# ============================================================
# API endpoints configuration (UPDATED)
# ============================================================
ENDPOINTS = {
    "A": {
        "url": "http://92.246.87.152/zAmGkTON/",
        "api_key": "EFBFEEAB46BCA89FD66919770E6A75B3",
        "name": "Endpoint A (1.6.0)"
    },
    "B": {
        "url": "http://172.110.221.252/mDicGOIJ/",
        "api_key": "8630B7880F805B89FF629F91A7ED137C",
        "name": "Endpoint B (1.5.13)"
    }
}

# ============================================================
# Full actions list (as provided)
# ============================================================
ACTIONS = [
    'mysql_query', 'user_info', 'get_lines', 'get_mags', 'get_enigmas', 'get_users',
    'get_streams', 'get_provider_streams', 'get_channels', 'get_stations', 'get_movies',
    'get_series_list', 'get_episodes', 'activity_logs', 'credit_logs', 'client_logs',
    'user_logs', 'stream_errors', 'system_logs', 'login_logs', 'restream_logs',
    'live_connections', 'watch_output', 'mag_events', 'get_line', 'create_line',
    'edit_line', 'delete_line', 'disable_line', 'enable_line', 'unban_line', 'ban_line',
    'get_user', 'create_user', 'edit_user', 'delete_user', 'disable_user', 'enable_user',
    'get_mag', 'create_mag', 'edit_mag', 'delete_mag', 'disable_mag', 'enable_mag',
    'unban_mag', 'ban_mag', 'convert_mag', 'get_enigma', 'create_enigma', 'edit_enigma',
    'delete_enigma', 'disable_enigma', 'enable_enigma', 'unban_enigma', 'ban_enigma',
    'convert_enigma', 'get_bouquets', 'get_bouquet', 'create_bouquet', 'edit_bouquet',
    'delete_bouquet', 'get_access_codes', 'get_access_code', 'create_access_code',
    'edit_access_code', 'delete_access_code', 'get_hmacs', 'get_hmac', 'create_hmac',
    'edit_hmac', 'delete_hmac', 'get_epgs', 'get_epg', 'create_epg', 'edit_epg',
    'delete_epg', 'reload_epg', 'get_providers', 'get_provider', 'create_provider',
    'edit_provider', 'delete_provider', 'reload_provider', 'get_groups', 'get_group',
    'create_group', 'edit_group', 'delete_group', 'get_packages', 'get_package',
    'create_package', 'edit_package', 'delete_package', 'get_transcode_profiles',
    'get_transcode_profile', 'create_transcode_profile', 'edit_transcode_profile',
    'delete_transcode_profile', 'get_rtmp_ips', 'get_rtmp_ip', 'create_rtmp_ip',
    'edit_rtmp_ip', 'delete_rtmp_ip', 'get_categories', 'get_category', 'create_category',
    'edit_category', 'delete_category', 'get_watch_folders', 'get_watch_folder',
    'create_watch_folder', 'edit_watch_folder', 'delete_watch_folder', 'reload_watch_folder',
    'get_blocked_isps', 'add_blocked_isp', 'delete_blocked_isp', 'get_blocked_uas',
    'add_blocked_ua', 'delete_blocked_ua', 'get_blocked_ips', 'add_blocked_ip',
    'delete_blocked_ip', 'flush_blocked_ips', 'get_stream', 'create_stream', 'edit_stream',
    'delete_stream', 'start_station', 'start_channel', 'start_stream', 'stop_station',
    'stop_channel', 'stop_stream', 'get_channel', 'create_channel', 'edit_channel',
    'delete_channel', 'get_station', 'create_station', 'edit_station', 'delete_station',
    'get_movie', 'create_movie', 'edit_movie', 'delete_movie', 'start_episode',
    'start_movie', 'stop_episode', 'stop_movie', 'get_episode', 'create_episode',
    'edit_episode', 'delete_episode', 'get_series', 'create_series', 'edit_series',
    'delete_series', 'get_servers', 'get_server', 'install_server', 'install_proxy',
    'edit_server', 'edit_proxy', 'delete_server', 'get_settings', 'edit_settings',
    'get_server_stats', 'get_fpm_status', 'get_rtmp_stats', 'get_free_space', 'get_pids',
    'get_certificate_info', 'reload_nginx', 'clear_temp', 'clear_streams', 'get_directory',
    'kill_pid', 'kill_connection', 'adjust_credits', 'reload_cache',
]

TIMEOUT = 10

# ============================================================
# Helper functions (same as before)
# ============================================================

def fetch_endpoint(endpoint_config: Dict, action: str) -> Tuple[Optional[str], Optional[str]]:
    params = {"api_key": endpoint_config["api_key"], "action": action}
    try:
        resp = requests.get(endpoint_config["url"], params=params, timeout=TIMEOUT)
        resp.raise_for_status()
        return resp.text, resp.headers.get('Content-Type', '')
    except requests.exceptions.RequestException as e:
        return None, str(e)

def detect_format(text: str, content_type: str) -> str:
    if 'application/json' in content_type:
        return 'json'
    try:
        json.loads(text)
        return 'json'
    except (json.JSONDecodeError, TypeError):
        return 'text'

def analyze_json(data: Any) -> Dict:
    if isinstance(data, dict):
        keys_analysis = {}
        for k, v in data.items():
            keys_analysis[k] = {
                "type": type(v).__name__,
                "sample": str(v)[:50] + "..." if len(str(v)) > 50 else str(v)
            }
        return {"type": "object", "keys": keys_analysis, "key_count": len(data)}
    elif isinstance(data, list):
        if not data:
            return {"type": "array", "length": 0, "element_types": []}
        elem_types = [type(elem).__name__ for elem in data[:5]]
        return {
            "type": "array",
            "length": len(data),
            "element_types": elem_types,
            "first_element_sample": str(data[0])[:100] if data else None
        }
    else:
        return {"type": type(data).__name__, "sample": str(data)[:100]}

def analyze_text(text: str) -> Dict:
    lines = text.splitlines()
    non_empty = [l for l in lines if l.strip()]
    if not non_empty:
        return {"type": "empty_text", "line_count": 0}
    separators = []
    for sep in [',', '\t', '|', '  ']:
        if any(sep in line for line in non_empty[:5]):
            separators.append(sep)
    key_value_pattern = any(re.search(r'\w+=\S+', line) for line in non_empty[:5])
    return {
        "type": "plain_text",
        "line_count": len(lines),
        "non_empty_line_count": len(non_empty),
        "detected_separators": separators,
        "looks_like_key_value": key_value_pattern,
        "sample_lines": non_empty[:3]
    }

def analyze_response(text: str, content_type: str) -> Dict:
    fmt = detect_format(text, content_type)
    if fmt == 'json':
        try:
            data = json.loads(text)
            style = analyze_json(data)
            style['_format'] = 'JSON'
            return style
        except Exception:
            return analyze_text(text)
    else:
        style = analyze_text(text)
        style['_format'] = 'Plain text'
        return style

def compare_styles(style_a: Dict, style_b: Dict, name_a: str, name_b: str) -> str:
    report = []
    report.append(f"=== STYLE COMPARISON: {name_a}  vs  {name_b} ===\n")

    fmt_a = style_a.get('_format', 'unknown')
    fmt_b = style_b.get('_format', 'unknown')
    report.append(f"Response format: {fmt_a}  vs  {fmt_b}")
    report.append("  -> " + ("Same format" if fmt_a == fmt_b else "DIFFERENT formats"))

    if fmt_a == 'JSON' and fmt_b == 'JSON':
        if style_a['type'] == 'object' and style_b['type'] == 'object':
            keys_a = set(style_a['keys'].keys())
            keys_b = set(style_b['keys'].keys())
            common = keys_a & keys_b
            only_a = keys_a - keys_b
            only_b = keys_b - keys_a
            report.append(f"\nTop-level keys: {len(keys_a)} vs {len(keys_b)}")
            if only_a:
                report.append(f"  Keys only in {name_a}: {sorted(only_a)}")
            if only_b:
                report.append(f"  Keys only in {name_b}: {sorted(only_b)}")
            if common:
                report.append("  Common keys - value type comparison:")
                for k in common:
                    t_a = style_a['keys'][k]['type']
                    t_b = style_b['keys'][k]['type']
                    same = "OK" if t_a == t_b else "DIFFERENT"
                    report.append(f"    {k}: {t_a} vs {t_b} ({same})")

        elif style_a['type'] == 'array' and style_b['type'] == 'array':
            report.append(f"\nArray length: {style_a['length']} vs {style_b['length']}")
            if style_a['element_types'] and style_b['element_types']:
                report.append(f"  First element types: {style_a['element_types'][0]} vs {style_b['element_types'][0]}")
            else:
                report.append("  One or both arrays are empty.")
        else:
            report.append(f"\nTop-level JSON type mismatch: {style_a['type']} vs {style_b['type']}")

    elif fmt_a == 'Plain text' and fmt_b == 'Plain text':
        report.append(f"\nLine counts: {style_a['line_count']} vs {style_b['line_count']}")
        report.append(f"Non-empty lines: {style_a['non_empty_line_count']} vs {style_b['non_empty_line_count']}")
        report.append(f"Detected separators: {style_a['detected_separators']} vs {style_b['detected_separators']}")
        report.append(f"Key-value pattern: {style_a['looks_like_key_value']} vs {style_b['looks_like_key_value']}")
        report.append("\nSample lines (first 3 non-empty):")
        report.append(f"  {name_a}:")
        for line in style_a['sample_lines']:
            report.append(f"    {line[:100]}")
        report.append(f"  {name_b}:")
        for line in style_b['sample_lines']:
            report.append(f"    {line[:100]}")
    else:
        report.append(f"\nDifferent formats - cannot compare internal structure in detail.")

    return "\n".join(report)

# ============================================================
# Main: iterate over all actions
# ============================================================

def main():
    total = len(ACTIONS)
    print(f"Starting comparison for all {total} actions...\n")
    print("=" * 80)

    for idx, action in enumerate(ACTIONS, 1):
        print(f"\n[{idx}/{total}] ACTION: {action}")
        print("-" * 60)

        # Fetch from endpoint A
        text_a, ct_a = fetch_endpoint(ENDPOINTS['A'], action)
        if text_a is None:
            print(f"  Endpoint A FAILED: {ct_a}")
            style_a = None
        else:
            print(f"  Endpoint A: {len(text_a)} bytes, Content-Type: {ct_a}")
            style_a = analyze_response(text_a, ct_a)

        # Fetch from endpoint B
        text_b, ct_b = fetch_endpoint(ENDPOINTS['B'], action)
        if text_b is None:
            print(f"  Endpoint B FAILED: {ct_b}")
            style_b = None
        else:
            print(f"  Endpoint B: {len(text_b)} bytes, Content-Type: {ct_b}")
            style_b = analyze_response(text_b, ct_b)

        # Compare if both succeeded
        if style_a is not None and style_b is not None:
            comparison = compare_styles(
                style_a, style_b,
                f"{ENDPOINTS['A']['name']} (action={action})",
                f"{ENDPOINTS['B']['name']} (action={action})"
            )
            print(comparison)
        elif style_a is None and style_b is None:
            print("  Both endpoints failed for this action.")
        elif style_a is None:
            print("  Endpoint A failed, cannot compare.")
        else:
            print("  Endpoint B failed, cannot compare.")

        print("=" * 80)

    print("\nAll actions processed.")

if __name__ == "__main__":
    main()
