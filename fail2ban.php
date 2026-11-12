<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

if (!is_super()) {
    http_response_code(403);
    exit('Yetkisiz');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
    http_response_code(400);
    exit('Geçersiz istek');
}

$action = (string) ($_POST['action'] ?? '');
try {
    if ($action === 'configure') {
        astera_fail2ban_configure($_POST);
        $message = 'Fail2ban Asterisk koruması kaydedildi ve yeniden yüklendi.';
    } elseif (in_array($action, ['banip', 'unbanip', 'allowip'], true)) {
        astera_fail2ban_ip_action($action, (string) ($_POST['ip'] ?? ''));
        $message = match ($action) {
            'banip' => 'IP adresi engellendi.',
            'allowip' => 'IP adresi kalıcı izin listesine eklendi ve engeli kaldırıldı.',
            default => 'IP engeli kaldırıldı.',
        };
    } else {
        throw new InvalidArgumentException('Geçersiz işlem.');
    }
    $_SESSION['fail2ban_flash'] = ['message' => $message];
} catch (Throwable $exception) {
    $_SESSION['fail2ban_flash'] = [
        'error' => true,
        'message' => $exception->getMessage(),
    ];
}

redirect('index.php?p=fail2ban');
