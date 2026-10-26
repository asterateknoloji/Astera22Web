#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/alarms.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function alarm_bool(mixed $value): bool
{
    return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
}

function alarm_event(PDO $pdo, string $deptId, string $key, string $type, string $severity, ?float $value, string $summary, string $details = ''): int
{
    $statement = $pdo->prepare(
        'INSERT INTO callcenter_alarm_events '
        . '(dept_id,alarm_key,event_type,severity,measured_value,summary,details) '
        . 'VALUES (:dept,:alarm,:type,:severity,:value,:summary,:details) RETURNING event_id'
    );
    $statement->execute([
        'dept' => $deptId,
        'alarm' => $key,
        'type' => $type,
        'severity' => $severity,
        'value' => $value,
        'summary' => $summary,
        'details' => $details,
    ]);
    return (int) $statement->fetchColumn();
}

function alarm_notify(PDO $pdo, string $deptId, int $eventId, string $subject, string $html): bool
{
    $smtp = callcenter_rows(
        'SELECT * FROM callcenter_alarm_smtp_settings WHERE dept_id=:dept AND enabled',
        ['dept' => $deptId]
    )[0] ?? null;
    $recipients = array_column(callcenter_rows(
        'SELECT email FROM callcenter_alarm_recipients WHERE dept_id=:dept AND active ORDER BY email',
        ['dept' => $deptId]
    ), 'email');
    if (!$smtp || !$recipients) return false;
    try {
        astera_alarm_send_email($smtp, $recipients, $subject, $html);
        $status = 'sent';
        $error = '';
    } catch (Throwable $exception) {
        $status = 'failed';
        $error = mb_substr($exception->getMessage(), 0, 1000);
        error_log("Astera alarm e-posta hatası [{$deptId}]: {$error}");
    }
    foreach ($recipients as $recipient) {
        callcenter_execute(
            'INSERT INTO callcenter_alarm_delivery_attempts '
            . '(dept_id,event_id,recipient_email,status,error_message) VALUES (:dept,:event,:email,:status,:error)',
            ['dept' => $deptId, 'event' => $eventId, 'email' => $recipient, 'status' => $status, 'error' => $error]
        );
    }
    return $status === 'sent';
}

$pdo = callcenter_pdo();
if (!$pdo) {
    fwrite(STDERR, "PostgreSQL bağlantısı kurulamadı.\n");
    exit(1);
}

$definitions = astera_alarm_definitions();
$departments = callcenter_rows('SELECT dept_id FROM astera_tenants WHERE active ORDER BY dept_id');
foreach ($departments as $department) {
    $deptId = (string) $department['dept_id'];
    astera_alarm_ensure_rules($deptId);
    $rules = callcenter_rows(
        'SELECT r.*,s.status AS previous_status,s.last_notified_at '
        . 'FROM callcenter_alarm_rules r LEFT JOIN callcenter_alarm_states s '
        . 'ON s.dept_id=r.dept_id AND s.alarm_key=r.alarm_key '
        . 'WHERE r.dept_id=:dept AND r.enabled ORDER BY r.alarm_key',
        ['dept' => $deptId]
    );
    $autoRestart = array_filter($rules, static fn(array $rule): bool => $rule['alarm_key'] === 'auto_restart_services');
    foreach ($rules as $rule) {
        $key = (string) $rule['alarm_key'];
        if ($key === 'auto_restart_services') continue;
        $result = astera_alarm_evaluate($key);
        if ($result === null) continue;
        [$value, $summary] = $result;
        $threshold = $rule['threshold_value'] !== null ? (float) $rule['threshold_value'] : 1.0;
        $warning = $rule['warning_value'] !== null ? (float) $rule['warning_value'] : null;
        $status = $value >= $threshold ? 'alarm' : (($warning !== null && $value >= $warning) ? 'warning' : 'ok');
        $previous = (string) ($rule['previous_status'] ?? 'ok');
        $changed = $previous !== $status;
        $lastNotified = !empty($rule['last_notified_at']) ? strtotime((string) $rule['last_notified_at']) : 0;
        $reminderDue = $status !== 'ok'
            && time() - $lastNotified >= ((int) $rule['cooldown_minutes'] * 60);
        $notify = ($changed && $status !== 'ok') || $reminderDue
            || ($changed && $status === 'ok' && alarm_bool($rule['recovery_notification']));
        $firstTriggered = $status === 'ok' ? null : ($previous === 'ok' ? date('c') : null);

        callcenter_execute(
            'INSERT INTO callcenter_alarm_states '
            . '(dept_id,alarm_key,status,current_value,summary,first_triggered_at,last_checked_at,last_changed_at,updated_at) '
            . 'VALUES (:dept,:alarm,:status,:value,:summary,:first,now(),now(),now()) '
            . 'ON CONFLICT (dept_id,alarm_key) DO UPDATE SET '
            . 'status=EXCLUDED.status,current_value=EXCLUDED.current_value,summary=EXCLUDED.summary,'
            . 'first_triggered_at=CASE WHEN EXCLUDED.status=\'ok\' THEN NULL '
            . 'WHEN callcenter_alarm_states.status=\'ok\' THEN now() ELSE callcenter_alarm_states.first_triggered_at END,'
            . 'last_checked_at=now(),last_changed_at=CASE WHEN callcenter_alarm_states.status<>EXCLUDED.status '
            . 'THEN now() ELSE callcenter_alarm_states.last_changed_at END,updated_at=now()',
            ['dept' => $deptId, 'alarm' => $key, 'status' => $status, 'value' => $value, 'summary' => $summary, 'first' => $firstTriggered]
        );

        if ($key === 'critical_service_down' && $status === 'alarm' && $autoRestart) {
            foreach (['asterisk', 'postgresql', 'nginx'] as $service) {
                if (astera_alarm_shell('systemctl is-active ' . escapeshellarg($service)) !== 'active') {
                    astera_alarm_shell('sudo -n systemctl restart ' . escapeshellarg($service . '.service'));
                }
            }
        }
        if (!$notify) continue;
        $eventType = $status === 'ok' ? 'recovered' : (($changed || $previous === 'ok') ? 'triggered' : 'reminder');
        $severity = $status === 'alarm' ? 'critical' : ($status === 'warning' ? 'warning' : 'info');
        $label = $definitions[$key][1] ?? $key;
        $subject = sprintf('[Astera PBX] %s: %s', $status === 'ok' ? 'Düzeldi' : 'Alarm', $label);
        $eventId = alarm_event($pdo, $deptId, $key, $eventType, $severity, (float) $value, $summary);
        $html = '<h2>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h2>'
            . '<p><strong>Firma:</strong> ' . htmlspecialchars($deptId, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Durum:</strong> ' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Ölçülen değer:</strong> ' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Zaman:</strong> ' . date('d.m.Y H:i:s') . '</p>';
        if (alarm_notify($pdo, $deptId, $eventId, $subject, $html)) {
            callcenter_execute(
                'UPDATE callcenter_alarm_states SET last_notified_at=now(),updated_at=now() '
                . 'WHERE dept_id=:dept AND alarm_key=:alarm',
                ['dept' => $deptId, 'alarm' => $key]
            );
        }
    }
}
