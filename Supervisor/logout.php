<?php
declare(strict_types=1);

session_name('ASTERA_CALLCENTER_SUPERVISOR');
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/callcenter.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    callcenter_audit_write(
        (array) ($_SESSION['callcenter_supervisor'] ?? []),
        'logout',
        'logout',
        'Supervisor çıkışı',
        '',
        'auth',
        '/Supervisor/logout.php'
    );
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
header('Location: login.php');
exit;
