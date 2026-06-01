<?php
try{
$logFile = "/home/webhook/xui_installer_and_xds_repo_worker/".date('Y-m-d H-i-s').".log";

function logMessage($message, $logFile) {
    // return true;
    file_put_contents($logFile, "$message\n", FILE_APPEND | LOCK_EX);
}

logMessage("=== START EXECUTION ===", $logFile);
// 'rm -rf /home/xds_encoded && ' .
// '/home/ioncube/ioncube_encoder.sh -74 /home/xds -o /home/xds_encoded --copy "vendor/" && ' .
// '/home/xds_encoded/build/build.sh && ' .
// 'mv -f /home/xds_encoded/build/xds.tar.gz /home/xds.tar.gz && ' .
// 'rm -rf /home/xds_encoded'

$deployCommand = 'bash -lc ' . escapeshellarg(
    'set -e && ' .
    'cd /home/xui_installer && git pull && ' .
    'cp -R ./install /home/xui_install/ && ' .
    'cp -R ./install /home/xui_install_stage/ && ' .
    'cp -R ./install /home/xui_install_prod/ && ' .
    'cp -R ./database.sql /home/xui_install/ && ' .
    'cp -R ./database.sql /home/xui_install_stage/ && ' .
    'cp -R ./database.sql /home/xui_install_prod/ && ' .
    'cd /home/xds && git pull origin main && ' .
    '/home/xds/build/build.sh && ' .
    'mv -f /home/xds/build/xds.tar.gz /home/xds.tar.gz'
);

exec($deployCommand . ' 2>&1', $output, $returnCode);

foreach ($output as $line) {
    logMessage("[DEPLOY] " . $line, $logFile);
}

if ($returnCode !== 0) {
    logMessage("Deploy command FAILED (code: $returnCode)", $logFile);
    exit(1);
}

logMessage("=== DEPLOY COMPLETED ===", $logFile);

/**
 * Stop any running xui_repo_worker, then start a fresh one
 */
exec("pkill -f '/home/webhook/xui_repo_worker.php' 2>/dev/null", $pkillOut, $pkillCode);
logMessage("pkill xui_repo_worker (code: $pkillCode)", $logFile);

sleep(1);

$repoWorker = "sudo php /home/webhook/xui_repo_worker.php > /dev/null 2>&1 &";
exec($repoWorker, $startOut, $startCode);
logMessage("Started xui_repo_worker (code: $startCode)", $logFile);

logMessage("=== ALL COMPLETED ===", $logFile);
} catch (Exception $e) {
    file_put_contents('/home/webhook/wang-log', date('Y-m-d H-i-s') . ' from xui_installer_and_xds_repo_worker.php' . "\n\tError: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
}