<?php

$allowed_ips = [
    '92.246.87.152',
    '172.110.221.246',
];

function ip_in_cidr(string $ip, string $cidr): bool
{
    [$subnet, $mask] = explode('/', $cidr, 2);
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet);
    if ($ip_long === false || $subnet_long === false) {
        return false;
    }
    $mask_long = -1 << (32 - (int) $mask);
    return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
}

function is_cloudflare_ip(string $ip): bool
{
    static $ranges = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    ];
    foreach ($ranges as $cidr) {
        if (ip_in_cidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

function get_client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (is_cloudflare_ip($remote)) {
        $cf_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if (filter_var($cf_ip, FILTER_VALIDATE_IP)) {
            return $cf_ip;
        }
    }
    return $remote;
}

$client_ip = get_client_ip();

if (!in_array($client_ip, $allowed_ips, true)) {
    http_response_code(403);
    exit('Forbidden');
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