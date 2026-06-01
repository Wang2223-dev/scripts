<?php
if (isset($_GET['type']) && $_GET['type'] == 'stage') {
    $file = '/home/xui_install_stage.tar.gz';
} else {
    $file = '/home/xui_install_prod.tar.gz';
}

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