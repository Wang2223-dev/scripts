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



// Wrap command to ensure proper shell exit
$command = 'bash -lc "cd /home/xui && git stash && git stash clear && git clean -fd && git fetch --all && git checkout lb_sk && git pull"';

$logFile = "/home/webhook/xui_repo_worker/".date('Y-m-d H-i-s').".log";

$telegramBotToken = getenv('TG_BOT_TOKEN');
$telegramChatId = getenv('TG_BOT_CHAT_ID');

sendTelegramMessage('Building started...', $telegramBotToken, $telegramChatId, $logFile);

function buildDeleteCmd(array $paths): string {
    $rmRf = [];
    $rmF = [];
    foreach ($paths as $path) {
        if (strpos($path, '*') !== false) {
            $rmF[] = escapeshellarg($path);
        } else {
            $rmRf[] = escapeshellarg($path);
        }
    }
    $parts = [];
    if ($rmRf) {
        $parts[] = 'sudo rm -rf ' . implode(' ', $rmRf);
    }
    if ($rmF) {
        $parts[] = 'sudo rm -f ' . implode(' ', $rmF);
    }
    return implode(' && ', $parts);
}

// Files/folders to remove in lb_sk (loadbalancer branch)
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
// Files/folders to remove in db_sk (database branch)
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



logMessage("=== START EXECUTION ===", $logFile);

logMessage("=== RUNNING PRE-COMMAND ===", $logFile);

// Build delete command safely
$deleteCmd = buildDeleteCmd($removePaths);
$deleteCmd1 = buildDeleteCmd($removePaths1);
$commitMsg = date('Y-m-d-H-i-s');

$preCommand = "
cd /home/xui && \
sudo git fetch --all && \
sudo git checkout ubuntu20 && \
sudo git pull && \
if [ ! -d /home/xui_lb/.git ]; then sudo git worktree add /home/xui_lb lb_sk; fi && \
if [ ! -d /home/xui_db/.git ]; then sudo git worktree add /home/xui_db db_sk; fi && \
sudo rsync -a --delete --filter='protect .git/' --exclude='.git' --exclude='bin/install/*.tar.gz' /home/xui/ /home/xui_lb/ && \
sudo rsync -a --delete --filter='protect .git/' --exclude='.git' --exclude='bin/install/*.tar.gz' /home/xui/ /home/xui_db/ && \
cd /home/xui_lb && \
sudo $deleteCmd && \
sudo git add -A && \
(sudo git diff --cached --quiet || sudo git commit -m '$commitMsg') && \
sudo git push origin lb_sk && \
cd /home/xui_db && \
sudo $deleteCmd1 && \
sudo git add -A && \
(sudo git diff --cached --quiet || sudo git commit -m '$commitMsg') && \
sudo git push origin db_sk
";

$mainCommand = <<<'SH'
bash -lc 'set -euo pipefail

SRC=/home/xui
OUT=/home/build/main
WORKDIR=$(mktemp -d)
STAGE="$WORKDIR/stage"
ENCODED="$WORKDIR/encoded"
IONCUBE=/home/ioncube/ioncube_encoder.sh

cleanup() { rm -rf "$WORKDIR"; }
trap cleanup EXIT

mkdir -p "$OUT"

stage_main_tree() {
  local dest="$1"
  mkdir -p "$dest"
  local d
  for d in bin backups content config tmp admin crons includes ministra player reseller www .git; do
    rsync -a "$SRC/$d/" "$dest/$d/"
  done
  cp -a "$SRC/status" "$SRC/tools" "$SRC/service" "$SRC/.gitignore" "$dest/"
}

stage_main_tree "$STAGE"

tar -czf "$OUT/main_dev.tar.gz" -C "$STAGE" .

# Prod encode: separate tree without .git / .gitignore (--ignore is unreliable for .git)
STAGE_ENCODE="$WORKDIR/stage_encode"
rsync -a "$STAGE/" "$STAGE_ENCODE/" --exclude='.git'
rm -f "$STAGE_ENCODE/.gitignore"

"$IONCUBE" -74 "$STAGE_ENCODE" -o "$ENCODED" \
  --copy "bin/" \
  --copy "backups/" \
  --copy "content/" \
  --copy "config/" \
  --copy "tmp/" \
  --copy "service" \
  --copy "includes/libs/" \
  --copy "includes/js/" \
  --copy "includes/styles/" \
  --copy "includes/langs/" \
  --copy "includes/python/" \
  --encode "status" \
  --encode "tools" \
  --ignore "*.sock"

rm -rf "$ENCODED/.git"
rm -f "$ENCODED/.gitignore"

tar -czf "$OUT/main.tar.gz" -C "$ENCODED" .

build_xui_install_bundle() {
  local bundle_out="$1" main_archive="$2" main_name="$3"
  local tmp f
  tmp=$(mktemp -d)
  for f in /home/build/*; do
    if [ -f "$f" ]; then
      cp -a "$f" "$tmp/"
    fi
  done
  cp -a "$main_archive" "$tmp/$main_name"
  tar -czf "$bundle_out" -C "$tmp" .
  rm -rf "$tmp"
}

build_xui_install_bundle /home/xui_install.tar.gz "$OUT/main.tar.gz" main.tar.gz
build_xui_install_bundle /home/xui_install_dev.tar.gz "$OUT/main_dev.tar.gz" main.tar.gz
'
SH;


exec($preCommand . " 2>&1", $output, $returnCode);

foreach ($output as $line) {
    logMessage("[PRE] " . $line, $logFile);
}

if ($returnCode !== 0) {
    logMessage("Pre-command FAILED (code: $returnCode)", $logFile);
    sendTelegramMessage("Pre-command FAILED (code: $returnCode)", $telegramBotToken, $telegramChatId, $logFile);
    exit;
}

logMessage("=== PRE-COMMAND COMPLETED ===", $logFile);

logMessage("=== RUNNING ENCODING AND BUILDING COMMANDS ===", $logFile);

$output = [];
exec($mainCommand . " 2>&1", $output, $returnCode);

foreach ($output as $line) {
    logMessage("[MAIN] " . $line, $logFile);
}

if ($returnCode !== 0) {
    logMessage("Main command FAILED (code: $returnCode)", $logFile);
    sendTelegramMessage("Main command FAILED (code: $returnCode)", $telegramBotToken, $telegramChatId, $logFile);
    exit;
}

logMessage("=== MAIN COMMAND COMPLETED ===", $logFile);

logMessage("=== ALL BUILDING COMMANDS COMPLETED ===", $logFile);

sendTelegramMessage('Building completed successfully!', $telegramBotToken, $telegramChatId, $logFile);
return;

/**
 * ============================
 * STEP 1: SSH EXECUTION
 * ============================
 */
// $servers = [
//     // ["host" => "195.62.32.215", "user" => "root", "pass" => "wang"],
//     // ["host" => "192.142.30.130", "user" => "root", "pass" => "wang"],
//     // ["host" => "193.35.224.124", "user" => "root", "pass" => "xui"],
//     // ["host" => "162.247.154.146", "user" => "root", "pass" => "xui"],
//     // ["host" => "188.241.219.133", "user" => "root", "pass" => "4349H(A*Sbd893"],
// ];

// $streams = [];

// foreach ($servers as $i => $server) {

//     $conn = @ssh2_connect($server['host'], 22);
//     if (!$conn) {
//         logMessage("[{$server['host']}] Connection failed", $logFile);
//         continue;
//     }

//     if (!@ssh2_auth_password($conn, $server['user'], $server['pass'])) {
//         logMessage("[{$server['host']}] Auth failed", $logFile);
//         continue;
//     }

//     $stdout = @ssh2_exec($conn, $command);
//     if (!$stdout) {
//         logMessage("[{$server['host']}] Command failed", $logFile);
//         continue;
//     }

//     $stderr = ssh2_fetch_stream($stdout, SSH2_STREAM_STDERR);

//     stream_set_blocking($stdout, false);
//     stream_set_blocking($stderr, false);

//     $streams[$i] = [
//         "host"   => $server['host'],
//         "stdout" => $stdout,
//         "stderr" => $stderr,
//         "output" => "",
//     ];

//     logMessage("[{$server['host']}] Command started", $logFile);
// }

// /**
//  * ============================
//  * STEP 2: ASYNC LOOP
//  * ============================
//  */

// $startTime = time();
// $timeout   = 60; // seconds

// while (!empty($streams)) {

//     // Timeout protection
//     if (time() - $startTime > $timeout) {
//         logMessage("GLOBAL TIMEOUT REACHED", $logFile);
//         break;
//     }

//     $read = [];

//     foreach ($streams as $data) {
//         $read[] = $data['stdout'];
//         $read[] = $data['stderr'];
//     }

//     $write = null;
//     $except = null;

//     $numChanged = stream_select($read, $write, $except, 5);

//     if ($numChanged === false) {
//         logMessage("stream_select error", $logFile);
//         break;
//     }

//     if ($numChanged === 0) {
//         continue;
//     }

//     foreach ($read as $rstream) {
//         foreach ($streams as $i => $data) {

//             $type = null;

//             if ($rstream === $data['stdout']) {
//                 $type = 'STDOUT';
//             } elseif ($rstream === $data['stderr']) {
//                 $type = 'STDERR';
//             } else {
//                 continue;
//             }

//             $chunk = fread($rstream, 8192);

//             if ($chunk !== false && strlen($chunk) > 0) {
//                 $streams[$i]['output'] .= $chunk;
//                 logMessage("[{$data['host']}][$type] " . trim($chunk), $logFile);
//             }

//             // Exit condition: BOTH streams finished
//             if (feof($data['stdout']) && feof($data['stderr'])) {

//                 logMessage("==== {$data['host']} DONE ====", $logFile);
//                 logMessage($streams[$i]['output'], $logFile);

//                 fclose($data['stdout']);
//                 fclose($data['stderr']);

//                 unset($streams[$i]);
//             }
//         }
//     }

//     logMessage("Active streams: " . count($streams), $logFile);
// }

// logMessage("=== ALL SERVERS COMPLETED ===", $logFile);
