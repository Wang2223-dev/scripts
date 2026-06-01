<?php
$SECRET_TOKEN = "shikang-0411-test";
$ALLOWED_BRANCH = "refs/heads/ubuntu20-staging"; // change if needed
// $ALLOWED_REPO   = "yourusername/yourrepo"; // change this

$LOG_FILE = "/home/webhook/xui_repo/".date('Y-m-d H-i-s').".log";

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
$signature = $headers['X-Hub-Signature-256'] ?? '';

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
$event = $headers['X-Github-Event'] ?? '';

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

log_msg("Deploy triggered by $pusher on $repo ($branch)");

exec("pkill -f '/home/webhook/xui_repo_worker.php' 2>/dev/null", $pkillOut, $pkillCode);
log_msg("pkill xui_repo_worker (code: $pkillCode)");

/**
 * Run worker safely (non-blocking)
 */
$cmd = "sudo php /home/webhook/xui_repo_worker.php > /dev/null 2>&1 &";
$cmd1 = "php /home/webhook/xui_lb_worker.php > /dev/null 2>&1 &";
exec($cmd);
exec($cmd1);

/**
 * Done
 */
echo json_encode([
    "status" => "ok",
    "message" => "Deployment started"
]);