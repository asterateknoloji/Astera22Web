<?php
declare(strict_types=1);

$agentScope = (string) ($_GET['scope'] ?? '') === 'agent';
if ($agentScope) {
    session_name('ASTERA_CALLCENTER_AGENT');
}
require_once __DIR__ . '/includes/bootstrap.php';

header('Cache-Control: no-store, private');

$extension = null;
if ($agentScope) {
    $agent = (array) ($_SESSION['callcenter_agent'] ?? []);
    $agentDept = (string) ($agent['dept_id'] ?? '');
    $agentExtension = (string) ($agent['extension'] ?? '');
    if ($agentDept !== '' && $agentExtension !== '') {
        $extension = find_ext($agentDept, $agentExtension);
    }
} else {
    $extensionId = (string) ($_SESSION['webphone_extension_id'] ?? '');
    if ($extensionId !== '') {
        $extension = find_by('extensions', 'id', $extensionId);
    }
}
if (!$extension || empty($extension['webrtc'])) {
    if (!$agentScope) {
        unset($_SESSION['webphone_extension_id']);
    }
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
