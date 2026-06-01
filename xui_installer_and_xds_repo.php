<?php
try{
$SECRET_TOKEN = "wang-webhook-secret-key";
$ALLOWED_BRANCH = "refs/heads/main"; // change if needed
// $ALLOWED_REPO   = "yourusername/xui_installer"; // change this

$LOG_FILE = "/home/webhook/xui_installer_and_xds_repo/".date('Y-m-d H-i-s').".log";

/**
 * Log helper
 */
function log_msg($msg) {
    // return true;
    global $LOG_FILE;
    file_put_contents($LOG_FILE, $msg . PHP_EOL, FILE_APPEND);
}

/**
 * Only POST allowed
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("Method Not Allowed");
}

/**
 * Get headers
 */
function getHeaders() {
    if (function_exists('getallheaders')) return getallheaders();

    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $key = str_replace('_', '-', substr($k, 5));
            $headers[$key] = $v;
        }
    }
    return $headers;
}

$headers = getHeaders();

/**
 * Read raw body
 */
$raw = file_get_contents('php://input');

/**
 * Validate GitHub signature
 */
$signature = $headers['X-Hub-Signature-256'] ?? $headers['X-HUB-SIGNATURE-256'] ?? '';

if (!$signature) {
    log_msg("Missing signature");
    http_response_code(400);
    exit("Missing signature");
}

$expected = 'sha256=' . hash_hmac('sha256', $raw, $SECRET_TOKEN);

if (!hash_equals($expected, $signature)) {
    log_msg("Invalid signature");
    http_response_code(403);
    exit("Invalid signature");
}

/**
 * Detect content type & parse payload
 */
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($contentType, 'application/json') !== false) {
    $payload = json_decode($raw, true);
} else {
    parse_str($raw, $data);
    $payload = json_decode($data['payload'] ?? '{}', true);
}

if (!$payload) {
    log_msg("Invalid payload");
    http_response_code(400);
    exit("Invalid payload");
}

/**
 * Event type
 */
$event = $headers['X-Github-Event'] ?? $headers['X-GITHUB-EVENT'] ?? '';

if ($event === 'ping') {
    log_msg("Ping received");
    echo json_encode(["status" => "ok"]);
    exit;
}

/**
 * Only allow push events
 */
if ($event !== 'push') {
    log_msg("Ignored event: $event");
    echo json_encode(["status" => "ignored"]);
    exit;
}

/**
 * Extract info
 */
$repo   = $payload['repository']['full_name'] ?? '';
$branch = $payload['ref'] ?? '';
$pusher = $payload['pusher']['name'] ?? '';

/**
 * Filters
 */
// if ($repo !== $ALLOWED_REPO) {
//     log_msg("Ignored repo: $repo");
//     exit;
// }

// if ($branch !== $ALLOWED_BRANCH) {
//     log_msg("Ignored branch: $branch");
//     exit;
// }

log_msg("Installer and XDS deploy triggered by $pusher on $repo ($branch)");

/**
 * Run worker safely (non-blocking)
 */
$cmd = "sudo php /home/webhook/xui_installer_and_xds_repo_worker.php > /dev/null 2>&1 &";
exec($cmd);

/**
 * Done
 */
echo json_encode([
    "status" => "ok",
    "message" => "Installer and XDS deployment started"
]);
} catch (Exception $e) {
    file_put_contents('/home/webhook/wang-log', date('Y-m-d H-i-s') . ' from xui_installer_and_xds_repo.php' . "\n\tError: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
}