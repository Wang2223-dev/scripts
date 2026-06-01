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

$AUTH_KEY = getenv('XUI_DEV_AUTH_KEY');
$AUTH_HEADER = getenv('XUI_DEV_AUTH_HEADER');

$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!$headers) {
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $headers[str_replace('_', '-', substr($key, 5))] = $value;
        }
    }
}

$provided = '';
foreach ($headers as $key => $value) {
    if (strcasecmp($key, $AUTH_HEADER) === 0) {
        $provided = (string) $value;
        break;
    }
}

if ($provided === '' || !hash_equals($AUTH_KEY, $provided)) {
    http_response_code(401);
    exit('Unauthorized');
}

$file = '/home/xui_install.tar.gz';

if (file_exists($file)) {
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.basename($file).'"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
} else {
    echo "File not found.";
}
?>
