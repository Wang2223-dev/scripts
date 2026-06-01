<?php

$servers = [
    ["host" => "195.62.32.147", "user" => "root", "pass" => "Ba5F:^=! |#?*l^-"],
];

// Wrap command to ensure proper shell exit
$command = 'bash -lc "php /home/build_lb_dev.php > /dev/null 2>&1 &"';

$logFile = "/home/webhook/xui_lb_worker/".date('Y-m-d H-i-s').".log";

function logMessage($message, $logFile) {
    return true;
    file_put_contents($logFile, "$message\n", FILE_APPEND | LOCK_EX);
}

logMessage("=== START EXECUTION ===", $logFile);

$streams = [];

foreach ($servers as $i => $server) {
    $conn = @ssh2_connect($server['host'], 22);
    if (!$conn) {
        logMessage("[{$server['host']}] Connection failed", $logFile);
        continue;
    }

    if (!@ssh2_auth_password($conn, $server['user'], $server['pass'])) {
        logMessage("[{$server['host']}] Auth failed", $logFile);
        continue;
    }

    $stdout = @ssh2_exec($conn, $command);
    if (!$stdout) {
        logMessage("[{$server['host']}] Command failed", $logFile);
        continue;
    }

    $stderr = ssh2_fetch_stream($stdout, SSH2_STREAM_STDERR);

    stream_set_blocking($stdout, false);
    stream_set_blocking($stderr, false);

    $streams[$i] = [
        "host"   => $server['host'],
        "stdout" => $stdout,
        "stderr" => $stderr,
        "output" => "",
    ];

    logMessage("[{$server['host']}] Command started", $logFile);
}

/**
 * ============================
 * STEP 2: ASYNC LOOP
 * ============================
 */

$startTime = time();
$timeout   = 60; // seconds

while (!empty($streams)) {

    // Timeout protection
    if (time() - $startTime > $timeout) {
        logMessage("GLOBAL TIMEOUT REACHED", $logFile);
        break;
    }

    $read = [];

    foreach ($streams as $data) {
        $read[] = $data['stdout'];
        $read[] = $data['stderr'];
    }

    $write = null;
    $except = null;

    $numChanged = stream_select($read, $write, $except, 5);

    if ($numChanged === false) {
        logMessage("stream_select error", $logFile);
        break;
    }

    if ($numChanged === 0) {
        continue;
    }

    foreach ($read as $rstream) {
        foreach ($streams as $i => $data) {

            $type = null;

            if ($rstream === $data['stdout']) {
                $type = 'STDOUT';
            } elseif ($rstream === $data['stderr']) {
                $type = 'STDERR';
            } else {
                continue;
            }

            $chunk = fread($rstream, 8192);

            if ($chunk !== false && strlen($chunk) > 0) {
                $streams[$i]['output'] .= $chunk;
                logMessage("[{$data['host']}][$type] " . trim($chunk), $logFile);
            }

            // Exit condition: BOTH streams finished
            if (feof($data['stdout']) && feof($data['stderr'])) {

                logMessage("==== {$data['host']} DONE ====", $logFile);
                logMessage($streams[$i]['output'], $logFile);

                fclose($data['stdout']);
                fclose($data['stderr']);

                unset($streams[$i]);
            }
        }
    }

    logMessage("Active streams: " . count($streams), $logFile);
}

logMessage("=== ALL SERVERS COMPLETED ===", $logFile);
