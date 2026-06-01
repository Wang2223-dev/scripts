<?php

$envFile = __DIR__ . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

$servers = [
    // ["host" => "195.62.32.215", "user" => "root", "pass" => "wang"],
    // ["host" => "192.142.30.130", "user" => "root", "pass" => "wang"],
    // ["host" => "193.35.224.124", "user" => "root", "pass" => "xui"],
    // ["host" => "162.247.154.146", "user" => "root", "pass" => "xui"],
    // ["host" => "188.241.219.133", "user" => "root", "pass" => "4349H(A*Sbd893"],
];

// Wrap command to ensure proper shell exit
$command = 'bash -lc "cd /home/xui && git stash && git stash clear && git clean -fd && git fetch --all && git checkout lb_sk && git pull"';

$logFile = "/home/webhook/xui_repo_worker/".date('Y-m-d H-i-s').".log";

$telegramBotToken = getenv('TG_BOT_TOKEN');
$telegramChatId = getenv('TG_BOT_CHAT_ID');

// Files/folders to delete in xui_lb
$removePaths = [
    'bin/install/*.tar.gz',
    'admin',
    'includes/cli/cache_handler.php',
    'includes/pages',
    'includes/js',
    'includes/lang',
    'includes/styles',
    'crons/backups.php',
    'crons/cache_engine.php',
    'crons/epg.php',
    'crons/plex.php',
    'crons/providers.php',
    'crons/root_mysql.php',
    'crons/series.php',
    'crons/tmdb.php',
    'crons/tmdb_popular.php',
    'www/streams/auth.php',
    'www/playlist.php',
    'reseller',
    'player',
    'ministra',
    'tools',
    'log.txt',
];
// Files/folders to delete in xui_db
$removePaths1 = [
    'bin/install/*.tar.gz',
    'admin',
    'includes/cli/cache_handler.php',
    'includes/pages',
    'includes/js',
    'includes/lang',
    'includes/styles',
    'crons/cache_engine.php',
    'www/streams',
    'www/api.php',
    'www/enigma2.php',
    'www/epg.php',
    'www/player_api.php',
    'www/playlist.php',
    'www/probe.php',
    'www/progress.php',
    'www/xplugin.php',
    'reseller',
    'player',
    'tools',
    'ministra',
    'log.txt',
];

function logMessage($message, $logFile) {
    // return true;
    file_put_contents($logFile, "$message\n", FILE_APPEND | LOCK_EX);
}

function sendTelegramMessage($message, $botToken, $chatId, $logFile) {
    if ($chatId === '' || $chatId === null) {
        logMessage("Telegram notification skipped: chat ID not configured", $logFile);
        return false;
    }

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $payload = http_build_query([
        'chat_id' => $chatId,
        'text' => $message,
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        logMessage("Telegram notification failed: request error", $logFile);
        return false;
    }

    $data = json_decode($response, true);
    if (empty($data['ok'])) {
        logMessage("Telegram notification failed: " . ($data['description'] ?? $response), $logFile);
        return false;
    }

    logMessage("Telegram notification sent", $logFile);
    return true;
}

logMessage("=== START EXECUTION ===", $logFile);

/**
 * ============================
 * STEP 0: PRE-COMMAND (LOCAL)
 * ============================
 */

logMessage("=== RUNNING PRE-COMMAND ===", $logFile);

// Build delete command safely
$deleteCmd = 'sudo rm -rf ' . implode(' ', array_map('escapeshellarg', $removePaths));
$deleteCmd1 = 'sudo rm -rf ' . implode(' ', array_map('escapeshellarg', $removePaths1));

// sudo git pull && \       
$preCommand = "
cd /home/xui && \
sudo git checkout ubuntu20 && \
sudo git pull && \
sudo rsync -a --delete --filter='protect .git/' --exclude='.git' --exclude='bin/install/*.tar.gz' /home/xui/ /home/xui_lb/ && \
sudo rsync -a --delete --filter='protect .git/' --exclude='.git' --exclude='bin/install/*.tar.gz' /home/xui/ /home/xui_db/ && \
cd /home/xui_lb && \
sudo $deleteCmd && \
sudo git add . && \
sudo git diff --cached --quiet || sudo git commit -m '".date('Y-m-d-H-i-s')."' && \
sudo git push origin lb_sk && \
cd /home/xui_db && \
sudo $deleteCmd1 && \
sudo git add . && \
sudo git diff --cached --quiet || sudo git commit -m '".date('Y-m-d-H-i-s')."' && \
sudo git push origin db_sk
";

$afterCommand = "
rm -f /home/xui_install/*.tar.gz && \
cp /home/xds.tar.gz /home/xui_install && \
cp /home/proxy.tar.gz /home/xui_install && \
tar -czf /home/xui_install/loadbalancer.tar.gz -C /home/xui_lb/. --exclude='.git1' . && \
tar -czf /home/xui_install/db.tar.gz -C /home/xui_db/. --exclude='.git1' . && \
tar -czf /home/xui_install/main.tar.gz -C /home/xui/. --exclude='.git1' . && \
tar -czf /home/xui_install.tar.gz -C /home/xui_install/. . && \
";

$afterCommandProd = "
rm -f /home/xui_install_prod/*.tar.gz && \
cp /home/xds.tar.gz /home/xui_install_prod && \
cp /home/proxy.tar.gz /home/xui_install_prod && \
rm -rf /home/xui_lb_encoded && \
/home/ioncube/ioncube_encoder.sh -74 /home/xui_lb -o /home/xui_lb_encoded --copy \"bin/\" --copy \"config/\" --copy \"content/\" --copy \"tmp/\" --copy \"includes/libs/\" --ignore \"*.sock\" && \
tar -czf /home/xui_install_prod/loadbalancer.tar.gz -C /home/xui_lb_encoded/. --exclude='.git' . && \
rm -rf /home/xui_lb_encoded && \
rm -rf /home/xui_db_encoded && \
/home/ioncube/ioncube_encoder.sh -74 /home/xui_db -o /home/xui_db_encoded --copy \"bin/\" --copy \"config/\" --copy \"content/\" --copy \"tmp/\" --copy \"includes/libs/\" --ignore \"*.sock\" && \
tar -czf /home/xui_install_prod/db.tar.gz -C /home/xui_db_encoded/. --exclude='.git' . && \
rm -rf /home/xui_db_encoded && \
rm -rf /home/xui_encoded && \
/home/ioncube/ioncube_encoder.sh -74 /home/xui -o /home/xui_encoded --copy \"bin/\" --copy \"config/\" --copy \"content/\" --copy \"tmp/\" --copy \"includes/libs/\" --ignore \"*.sock\" && \
tar -czf /home/xui_install_prod/main.tar.gz -C /home/xui_encoded/. --exclude='.git' . && \
rm -rf /home/xui_encoded && \
tar -czf /home/xui_install_prod.tar.gz -C /home/xui_install_prod/. . && \
rm -rf /home/xui_install_prod/*.tar.gz && \
";

$afterCommandStage = "
rm -f /home/xui_install_stage/*.tar.gz && \
cp /home/xds.tar.gz /home/xui_install_stage && \
cp /home/proxy.tar.gz /home/xui_install_stage && \
rm -rf /home/xui_lb_encoded && \
/home/ioncube/ioncube_encoder.sh -74 /home/xui_lb -o /home/xui_lb_encoded --copy \"bin/\" --copy \"config/\" --copy \"content/\" --copy \"tmp/\" --copy \"includes/libs/\" --ignore \"*.sock\" --copy \"admin/\" --copy \"reseller/\" --copy \"player/\" --copy \"ministra/\" --copy \"includes/js/\" --copy \"includes/styles/\" && \
tar -czf /home/xui_install_stage/loadbalancer.tar.gz -C /home/xui_lb_encoded/. --exclude='.git' . && \
rm -rf /home/xui_lb_encoded && \
rm -rf /home/xui_db_encoded && \
/home/ioncube/ioncube_encoder.sh -74 /home/xui_db -o /home/xui_db_encoded --copy \"bin/\" --copy \"config/\" --copy \"content/\" --copy \"tmp/\" --copy \"includes/libs/\" --ignore \"*.sock\" --copy \"admin/\" --copy \"reseller/\" --copy \"player/\" --copy \"ministra/\" --copy \"includes/js/\" --copy \"includes/styles/\" && \
tar -czf /home/xui_install_stage/db.tar.gz -C /home/xui_db_encoded/. --exclude='.git' . && \
rm -rf /home/xui_db_encoded && \
rm -rf /home/xui_encoded && \
/home/ioncube/ioncube_encoder.sh -74 /home/xui -o /home/xui_encoded --copy \"bin/\" --copy \"config/\" --copy \"content/\" --copy \"tmp/\" --copy \"includes/libs/\" --ignore \"*.sock\" --copy \"admin/\" --copy \"reseller/\" --copy \"player/\" --copy \"ministra/\" --copy \"includes/js/\" --copy \"includes/styles/\" && \
tar -czf /home/xui_install_stage/main.tar.gz -C /home/xui_encoded/. --exclude='.git' . && \
rm -rf /home/xui_encoded && \
tar -czf /home/xui_install_stage.tar.gz -C /home/xui_install_stage/. . && \
rm -rf /home/xui_install_stage/*.tar.gz && \
";

exec($preCommand . " 2>&1", $output, $returnCode);

foreach ($output as $line) {
    logMessage("[PRE] " . $line, $logFile);
}

if ($returnCode !== 0) {
    logMessage("Pre-command FAILED (code: $returnCode)", $logFile);
    exit;
}

logMessage("=== PRE-COMMAND COMPLETED ===", $logFile);
logMessage("=== RUNNING AFTER-COMMAND ===", $logFile);
$output = [];
exec($afterCommand . " 2>&1", $output, $returnCode);

foreach ($output as $line) {
    logMessage("[AFTER] " . $line, $logFile);
}

if ($returnCode !== 0) {
    logMessage("After-command FAILED (code: $returnCode)", $logFile);
    exit;
}

logMessage("=== AFTER-COMMAND COMPLETED ===", $logFile);
logMessage("=== RUNNING AFTER-COMMAND PROD ===", $logFile);
$output = [];
exec($afterCommandProd . " 2>&1", $output, $returnCode);

foreach ($output as $line) {
    logMessage("[AFTER PROD] " . $line, $logFile);
}
if ($returnCode !== 0) {
    logMessage("After-command prod FAILED (code: $returnCode)", $logFile);
    exit;
}

logMessage("=== AFTER-COMMAND PROD COMPLETED ===", $logFile);
logMessage("=== RUNNING AFTER-COMMAND STAGE ===", $logFile);
$output = [];
exec($afterCommandStage . " 2>&1", $output, $returnCode);

foreach ($output as $line) {
    logMessage("[AFTER STAGE] " . $line, $logFile);
}
if ($returnCode !== 0) {
    logMessage("After-command stage FAILED (code: $returnCode)", $logFile);
    exit;
}
logMessage("=== AFTER-COMMAND STAGE COMPLETED ===", $logFile);
logMessage("=== ALL COMMANDS COMPLETED ===", $logFile);
sendTelegramMessage('Building completed successfully!', $telegramBotToken, $telegramChatId, $logFile);
return;

/**
 * ============================
 * STEP 1: SSH EXECUTION
 * ============================
 */

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
