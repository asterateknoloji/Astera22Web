<?php
declare(strict_types=1);

$package = __DIR__ . '/Astera_Softphone.zip';
if (!is_file($package) || !is_readable($package)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Softphone paketi bulunamadı.';
    exit;
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="Astera_Softphone.zip"');
header('Content-Length: ' . (string) filesize($package));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
readfile($package);
