<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/system_power.php';
require_login();

if (!is_super()) {
    http_response_code(403);
    exit('Bu işlem yalnızca santral yöneticisine açıktır.');
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_ok()) {
        throw new RuntimeException('İstek doğrulanamadı. Sayfayı yenileyip tekrar deneyin.');
    }
    $action = (string) ($_POST['action'] ?? '');
    $expectedConfirmation = $action === 'reboot'
        ? 'YENIDEN BASLAT'
        : ($action === 'poweroff' ? 'KAPAT' : '');
    if (
        $expectedConfirmation === ''
        || astera_system_power_normalize_confirmation((string) ($_POST['confirmation'] ?? '')) !== $expectedConfirmation
    ) {
        throw new RuntimeException('İşlem onayı eksik veya hatalı.');
    }
    $message = astera_system_power_run($action);
    $deptId = current_dept_id();
    if ($deptId !== null && dept_by_id($deptId) !== null) {
        callcenter_audit_write([
            'dept_id' => $deptId,
            'username' => substr((string) ($_SESSION['username'] ?? $_SESSION['user'] ?? 'santral-admin'), 0, 160),
        ], 'update', 'sistem_gucu', $message, '', 'system');
    }
    $_SESSION['system_power_flash'] = ['message' => $message, 'error' => false];
} catch (Throwable $exception) {
    $_SESSION['system_power_flash'] = [
        'message' => mb_substr($exception->getMessage(), 0, 2000),
        'error' => true,
    ];
}

redirect('index.php?p=system#system-power');
