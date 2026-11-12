<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/system_updates.php';
require_login();

if (!is_super()) {
    http_response_code(403);
    exit('Bu işlem yalnızca santral yöneticisine açıktır.');
}

try {
    if (!csrf_ok()) {
        throw new RuntimeException('İstek doğrulanamadı. Sayfayı yenileyip tekrar deneyin.');
    }
    set_time_limit(900);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'refresh') {
        $message = astera_system_update_run('refresh');
        $message = trim((string) preg_replace('/^OK\t/', '', $message));
    } elseif ($action === 'apply') {
        if ((string) ($_POST['confirmation'] ?? '') !== 'UPDATE') {
            throw new RuntimeException('Güncelleme onayı eksik.');
        }
        $packages = array_values(array_unique(array_map(
            static fn(mixed $value): string => trim((string) $value),
            (array) ($_POST['packages'] ?? [])
        )));
        $message = astera_system_update_run('apply', $packages);
        $message = trim((string) preg_replace('/^OK\t/', '', $message));
    } else {
        throw new InvalidArgumentException('Geçersiz güncelleme işlemi.');
    }
    $deptId = current_dept_id();
    if ($deptId !== null && dept_by_id($deptId) !== null) {
        callcenter_audit_write([
            'dept_id' => $deptId,
            'username' => substr((string) ($_SESSION['username'] ?? $_SESSION['user'] ?? 'santral-admin'), 0, 160),
        ], 'update', 'sistem_guncelleme', $message, '', 'system');
    }
    $_SESSION['system_alarms_flash'] = ['message' => $message, 'error' => false];
} catch (Throwable $exception) {
    $_SESSION['system_alarms_flash'] = [
        'message' => mb_substr($exception->getMessage(), 0, 4000),
        'error' => true,
    ];
}

redirect('index.php?p=alarmlar&alarm_tab=updates');
