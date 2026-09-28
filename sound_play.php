<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$dept = ast_sanitize_id((string) ($_GET['dept'] ?? current_dept_id() ?? ''));
$kind = (string) ($_GET['kind'] ?? 'sound') === 'moh' ? 'moh' : 'sound';
$file = basename((string) ($_GET['file'] ?? ''));
if ($dept === '' || !preg_match('/^[A-Za-z0-9._-]+\.(wav|gsm|ulaw)$/i', $file)) {
    http_response_code(400);
    exit('Geçersiz ses dosyası');
}
if (!is_super() && $dept !== (string) ($_SESSION['user_dept'] ?? '')) {
    http_response_code(403);
    exit('Yetkisiz');
}

$code = dept_code_of($dept);
$directory = $kind === 'moh'
    ? '/var/lib/asterisk/moh/' . $code
    : '/var/lib/asterisk/sounds/custom/' . $code;
$source = $directory . '/' . $file;
$token = sha1($source);
$remotePreview = '/tmp/astera-preview-' . $token . '.wav';
$localPreview = DATA_PATH . DIRECTORY_SEPARATOR . 'previews' . DIRECTORY_SEPARATOR . $token . '.wav';

$convert = pbx_ssh("asterisk -rx 'file convert {$source} {$remotePreview}'");
if (!$convert['ok'] || stripos((string) ($convert['output'] ?? ''), 'error') !== false) {
    http_response_code(404);
    exit('Ses dosyası dönüştürülemedi');
}
$fetched = pbx_fetch_recording($remotePreview, $localPreview);
pbx_ssh('rm -f ' . $remotePreview);
if (!$fetched['ok'] || !is_file($localPreview)) {
    http_response_code(404);
    exit('Ses dosyası alınamadı');
}

header('Content-Type: audio/wav');
header('Content-Length: ' . filesize($localPreview));
header('Content-Disposition: inline; filename="' . pathinfo($file, PATHINFO_FILENAME) . '-preview.wav"');
readfile($localPreview);
exit;
