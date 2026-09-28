<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$panelAccess = !empty($_SESSION['user']);
$extension = null;
if (!$panelAccess) {
    $webphoneId = (string) ($_SESSION['webphone_extension_id'] ?? '');
    $extension = $webphoneId !== '' ? find_by('extensions', 'id', $webphoneId) : null;
    if (!$extension || empty($extension['webrtc']) || empty($extension['dept'])) {
        redirect('webphone.php');
    }
    $_SESSION['user_dept'] = (string) $extension['dept'];
    $_SESSION['username'] = 'WebPhone ' . (string) ($extension['exten'] ?? '');
}

if (!$panelAccess && $extension && empty($_GET['callid'])) {
    $status = pbx_blf_status((string) ($extension['dept'] ?? ''));
    $state = (array) (($status['extensions'] ?? [])[sip_user($extension)] ?? []);
    if (in_array((string) ($state['state'] ?? ''), ['ringing', 'talking'], true)
        && !empty($state['call_id'])) {
        $_GET['callid'] = (string) $state['call_id'];
    }
}

header('Cache-Control: no-store, private');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ASTERA · Müşteri Kartı</title>
    <link rel="stylesheet" href="assets/css/app.css?v=42">
</head>
<body class="crm-popup-body">
<header class="crm-popup-head">
    <div class="crm-popup-brand">
        <div>
            <strong>ASTERA CRM</strong>
            <span><?= e(dept_name((string) current_dept_id())) ?> · Müşteri yönetimi</span>
        </div>
    </div>
    <nav class="crm-popup-nav">
        <a class="btn crm-nav-primary" href="crm_popup.php">Müşteri Kartları</a>
        <a class="btn crm-nav-back" href="<?= $panelAccess ? 'index.php?p=crm' : 'webphone.php' ?>">
            <?= $panelAccess ? 'Panele dön' : 'WebPhone’a dön' ?>
        </a>
    </nav>
</header>
<main class="crm-popup-main">
    <?php include __DIR__ . '/pages/crm.php'; ?>
</main>
<div id="toast" class="toast" hidden></div>
<div id="modal" class="modal" hidden><div class="modal-card" id="modalCard"></div></div>
<script>
window.ASTERA = {
    csrf: <?= json_encode(csrf_token()) ?>,
    dept: <?= json_encode(current_dept_id()) ?>,
    isSuper: false,
    depts: <?= json_encode(array_values(array_filter(
        departments(),
        static fn(array $department): bool =>
            (string) ($department['id'] ?? '') === (string) current_dept_id()
    )), JSON_UNESCAPED_UNICODE) ?>,
    crmPopup: true
};
</script>
<script src="assets/js/app.js?v=55"></script>
</body>
</html>
