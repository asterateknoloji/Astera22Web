<?php
declare(strict_types=1);

session_name('ASTERA_CALLCENTER_AGENT');
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/callcenter.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['callcenter_agent'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Oturum gerekli'], JSON_UNESCAPED_UNICODE);
    exit;
}
$agent = (array) $_SESSION['callcenter_agent'];
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'snapshot');
try {
    $extra = [];
    $readActions = ['snapshot', 'queue_status', 'waiting_calls', 'recent_calls'];
    if (!in_array($action, $readActions, true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
            throw new RuntimeException('İstek doğrulanamadı');
        }
        if ($action === 'start_break') {
            $extra = callcenter_start_break(
                (string) $agent['dept_id'],
                (int) $agent['agent_id'],
                (int) ($_SESSION['callcenter_session_id'] ?? 0),
                (int) ($_POST['break_type_id'] ?? 0)
            );
        } elseif ($action === 'end_break') {
            $extra = callcenter_end_break((string) $agent['dept_id'], (int) $agent['agent_id']);
        } elseif ($action === 'originate') {
            $extra['message'] = callcenter_phone_action(
                $agent,
                'originate',
                trim((string) ($_POST['target'] ?? ''))
            );
        } elseif ($action === 'hangup') {
            $extra['message'] = callcenter_phone_action($agent, 'hangup');
        } elseif ($action === 'transfer') {
            $extra['message'] = callcenter_phone_action(
                $agent,
                'transfer',
                trim((string) ($_POST['target'] ?? ''))
            );
        } else {
            throw new InvalidArgumentException('Bilinmeyen işlem');
        }
    }
    $snapshot = callcenter_agent_snapshot($agent);
    $data = match ($action) {
        'queue_status' => ['queues' => $snapshot['queues']],
        'waiting_calls' => ['waiting_calls' => $snapshot['waiting_calls']],
        'recent_calls' => ['recent_calls' => $snapshot['recent_calls']],
        default => $snapshot,
    };
    echo json_encode(['ok' => true, 'data' => $data] + $extra,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException ? 422 : 409);
    $message = $error instanceof PDOException ? 'Veritabanı işlemi tamamlanamadı' : $error->getMessage();
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
}
