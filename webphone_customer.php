<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Cache-Control: no-store, private');

$extensionId = (string) ($_SESSION['webphone_extension_id'] ?? '');
if ($extensionId === '') {
    json_out(['ok' => false, 'error' => 'WebPhone oturumu gerekli'], 401);
}
$extension = find_by('extensions', 'id', $extensionId);
if (!$extension || empty($extension['webrtc'])) {
    unset($_SESSION['webphone_extension_id']);
    json_out(['ok' => false, 'error' => 'WebPhone erişimi kapalı'], 401);
}
$dept = ast_sanitize_id((string) ($extension['dept'] ?? ''));
$phone = crm_normalize_phone(substr((string) ($_GET['phone'] ?? ''), 0, 32));
if ($dept === '' || strlen($phone) < 7 || strlen($phone) > 15) {
    json_out(['ok' => true, 'customer' => null]);
}

$company = '';
try {
    $names = crm_local_caller_names($dept);
    $company = trim((string) ($names[$phone] ?? ''));
} catch (Throwable) {
    // Çağrı ekranını CRM bağlantı hatasıyla geciktirme.
}
json_out([
    'ok' => true,
    'customer' => $company !== '' ? ['company' => $company] : null,
]);
