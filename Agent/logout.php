<?php
declare(strict_types=1);

session_name('ASTERA_CALLCENTER_AGENT');
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/callcenter.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok() && isset($_SESSION['callcenter_agent'])) {
    $agent = (array) $_SESSION['callcenter_agent'];
    try {
        callcenter_end_session(
            (string) $agent['dept_id'],
            (int) $agent['agent_id'],
            (int) ($_SESSION['callcenter_session_id'] ?? 0)
        );
    } catch (Throwable $exception) {
        error_log('Callcenter agent logout tracking failed: ' . $exception->getMessage());
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
header('Location: login.php');
exit;
