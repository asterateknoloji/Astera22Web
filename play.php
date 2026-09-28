<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$webphoneDept = '';
if (empty($_SESSION['user'])) {
    $webphoneId = (string) ($_SESSION['webphone_extension_id'] ?? '');
    $webphoneExtension = $webphoneId !== '' ? find_by('extensions', 'id', $webphoneId) : null;
    if ($webphoneExtension && !empty($webphoneExtension['webrtc'])) {
        $webphoneDept = (string) ($webphoneExtension['dept'] ?? '');
        $_SESSION['user_dept'] = $webphoneDept;
    }
}
if (empty($_SESSION['user']) && $webphoneDept === '') {
    require_login();
}

$relative = str_replace('\\', '/', trim((string) ($_GET['f'] ?? ''), '/'));
$file = basename($relative);
$dept = ast_sanitize_id((string) ($_GET['dept'] ?? current_dept_id() ?? ''));
if ($file === '' || $dept === ''
    || !preg_match('#^(?:(?:\d{4})/(?:\d{2})/(?:\d{2})/)?[\w.\-]+\.(wav|WAV|mp3|gsm|ulaw)$#', $relative)) {
    http_response_code(400);
    echo 'Geçersiz dosya';
    exit;
}
if ((!is_super() || $webphoneDept !== '')
    && $dept !== (string) ($_SESSION['user_dept'] ?? $webphoneDept)) {
    http_response_code(403);
    echo 'Yetkisiz';
    exit;
}

$code = dept_code_of($dept);
$remote = '/var/spool/asterisk/monitor/' . $code . '/' . $relative;
$localDir = DATA_PATH . DIRECTORY_SEPARATOR . 'recordings';
$local = $localDir . DIRECTORY_SEPARATOR . $dept . '-' . sha1($relative) . '-' . $file;
$got = pbx_fetch_recording($remote, $local);
if (!$got['ok']) {
    http_response_code(404);
    echo 'Kayıt alınamadı';
    exit;
}

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$contentTypes = [
    'wav' => 'audio/wav',
    'mp3' => 'audio/mpeg',
    'gsm' => 'audio/gsm',
    'ulaw' => 'audio/basic',
];
header('Content-Type: ' . ($contentTypes[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($local));
header('Content-Disposition: inline; filename="' . $file . '"');
readfile($local);
exit;
