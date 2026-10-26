<?php
declare(strict_types=1);

session_name('ASTERA_CALLCENTER_SUPERVISOR');
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/callcenter.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['callcenter_supervisor'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Oturum gerekli'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $user = (array) $_SESSION['callcenter_supervisor'];
    if (!empty($user['must_change_password'])) {
        http_response_code(403);
        echo json_encode(
            ['ok' => false, 'error' => 'Önce varsayılan parolanızı değiştirin'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
    $target = (string) ($_SESSION['callcenter_supervisor_dept'] ?? $user['dept_id']);
    if (!callcenter_supervisor_can_access_department($user, $target)) {
        $target = (string) $user['dept_id'];
        $_SESSION['callcenter_supervisor_dept'] = $target;
    }
    $user['owner_dept_id'] = (string) $user['dept_id'];
    $user['dept_id'] = $target;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_ok()) {
            http_response_code(403);
            echo json_encode(
                ['ok' => false, 'error' => 'İstek doğrulanamadı. Sayfayı yenileyin.'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action !== 'monitor_start') {
            throw new InvalidArgumentException('Bilinmeyen işlem');
        }
        if (!callcenter_supervisor_has_permission($user, 'canli_izleme')) {
            http_response_code(403);
            echo json_encode(
                ['ok' => false, 'error' => 'Canlı izleme yetkisi gerekli'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }
        $mode = trim((string) ($_POST['mode'] ?? ''));
        $observer = trim((string) ($_POST['observer_extension'] ?? ''));
        $monitored = trim((string) ($_POST['target_extension'] ?? ''));
        $message = callcenter_supervisor_monitor_start(
            $user,
            $observer,
            $monitored,
            $mode
        );
        callcenter_audit_write(
            $user,
            'update',
            'fop_abone',
            'monitor_' . $mode,
            'Supervisor dahili: ' . $observer . '; hedef dahili: ' . $monitored,
            'callcenter',
            '/Supervisor/fop_abone.php'
        );
        echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $detailed = ($_GET['view'] ?? '') === 'fop';
    echo json_encode(
        ['ok' => true, 'data' => callcenter_supervisor_snapshot($user, $detailed)],
        JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $error) {
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    http_response_code($isPost ? 422 : 500);
    echo json_encode(
        ['ok' => false, 'error' => $isPost ? $error->getMessage() : 'Veriler alınamadı'],
        JSON_UNESCAPED_UNICODE
    );
}
