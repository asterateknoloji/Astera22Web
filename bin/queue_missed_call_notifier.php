#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/alarms.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function queue_notification_send_local(array $recipients, string $subject, string $body): void
{
    if (!is_executable('/usr/bin/mail')) {
        throw new RuntimeException('mail komutu bulunamadı');
    }
    $command = array_merge(
        ['/usr/bin/mail', '-r', 'root@astera.com.tr', '-s', $subject, '--'],
        $recipients
    );
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('mail işlemi başlatılamadı');
    }
    fwrite($pipes[0], $body);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(trim($stderr ?: $stdout) ?: 'mail komutu başarısız');
    }
}

function queue_notification_deliver(array $rows, array $queueNames): void
{
    if (!$rows) {
        return;
    }
    $first = $rows[0];
    $deptId = (string) $first['dept_id'];
    $queueId = (string) $first['queue_id'];
    $queueName = trim((string) ($queueNames[$queueId] ?? '')) ?: $queueId;
    $recipients = array_values(array_unique(array_map(
        static fn(array $row): string => (string) $row['recipient_email'],
        $rows
    )));
    $time = new DateTimeImmutable(
        (string) $first['event_time'],
        new DateTimeZone('Europe/Istanbul')
    );
    $caller = trim((string) $first['caller_number']) ?: 'Bilinmiyor';
    $position = $first['exit_position'] === null
        ? 'Bilinmiyor'
        : (string) max(0, (int) $first['exit_position']);
    $waitSeconds = max(0, (int) $first['wait_seconds']);
    $template = callcenter_rows(
        'SELECT missed_call_from_name,missed_call_subject_template,missed_call_body_template '
        . 'FROM callcenter_queue_settings WHERE dept_id=:dept AND queue_id=:queue',
        ['dept' => $deptId, 'queue' => $queueId]
    )[0] ?? [];
    $serverName = mb_convert_case(
        str_replace(['-', '_'], ' ', gethostname() ?: 'Astera Santral'),
        MB_CASE_TITLE,
        'UTF-8'
    );
    $variables = [
        '{kuyruk}' => $queueName,
        '{numara}' => $caller,
        '{tarih}' => $time->format('d.m.Y'),
        '{saat}' => $time->format('H:i'),
        '{sira}' => $position,
        '{bekleme}' => (string) $waitSeconds,
        '{santral}' => $serverName,
    ];
    $fromName = trim((string) (
        $template['missed_call_from_name'] ?? 'Astera Santral ve Callcenter'
    )) ?: 'Astera Santral ve Callcenter';
    $subject = strtr(
        (string) (
            $template['missed_call_subject_template']
            ?? '[Astera Santral ve Callcenter] Kaçan çağrı - {kuyruk}'
        ),
        $variables
    );
    $plain = strtr(
        (string) (
            $template['missed_call_body_template']
            ?? "Astera Santral ve Callcenter\n\nKaçan çağrı bildirimi\n\n"
                . "Kuyruk Adı: {kuyruk}\nNumara: {numara}\nTarih / Saat: {tarih} {saat}\n"
                . "Kaçtığı Sıra: {sira}\nBekleme Süresi: {bekleme} sn\nSantral: {santral}"
        ),
        $variables
    );
    $html = '<div style="font-family:Arial,sans-serif;line-height:1.5">'
        . nl2br(e($plain))
        . '</div>';

    $status = 'sent';
    $error = '';
    try {
        $smtp = callcenter_rows(
            'SELECT * FROM callcenter_alarm_smtp_settings WHERE dept_id=:dept AND enabled',
            ['dept' => $deptId]
        )[0] ?? null;
        if ($smtp) {
            $smtp['from_name'] = $fromName;
            astera_alarm_send_email($smtp, $recipients, $subject, $html);
        } else {
            queue_notification_send_local($recipients, $subject, $plain);
        }
    } catch (Throwable $exception) {
        $status = 'failed';
        $error = mb_substr($exception->getMessage(), 0, 1000);
        error_log("Astera kuyruk e-posta hatası [{$deptId}/{$queueId}]: {$error}");
    }

    foreach ($rows as $row) {
        callcenter_execute(
            'UPDATE callcenter_queue_notification_deliveries SET '
            . 'status=:status,attempt_count=attempt_count+1,error_message=:error,'
            . 'sent_at=CASE WHEN :status_sent=\'sent\' THEN now() ELSE sent_at END,updated_at=now() '
            . 'WHERE delivery_id=:id',
            [
                'status' => $status,
                'status_sent' => $status,
                'error' => $error,
                'id' => (int) $row['delivery_id'],
            ]
        );
    }
}

$pdo = callcenter_pdo();
if (!$pdo) {
    fwrite(STDERR, "PostgreSQL bağlantısı kurulamadı.\n");
    exit(1);
}

$queueNames = [];
foreach (store_read('queues') as $queue) {
    $queueNames[(string) ($queue['id'] ?? '')] = (string) ($queue['name'] ?? '');
}

$departments = callcenter_rows(
    'SELECT DISTINCT dept_id FROM callcenter_queue_notification_recipients '
    . 'WHERE active ORDER BY dept_id'
);
foreach ($departments as $department) {
    $deptId = (string) $department['dept_id'];
    $currentMax = (int) (callcenter_rows(
        'SELECT COALESCE(max(id),0)::bigint AS max_id FROM queue_log'
    )[0]['max_id'] ?? 0);
    $initialized = callcenter_rows(
        'INSERT INTO callcenter_queue_notification_state (dept_id,last_queue_log_id) '
        . 'VALUES (:dept,:last) ON CONFLICT (dept_id) DO NOTHING RETURNING dept_id',
        ['dept' => $deptId, 'last' => $currentMax]
    );
    if ($initialized) {
        continue;
    }
    $state = callcenter_rows(
        'SELECT last_queue_log_id FROM callcenter_queue_notification_state WHERE dept_id=:dept',
        ['dept' => $deptId]
    )[0] ?? ['last_queue_log_id' => $currentMax];
    $lastId = (int) $state['last_queue_log_id'];
    $events = callcenter_rows(
        'SELECT q.id,q.time AS event_time,q.callid,q.queuename,q.event,'
        . "CASE WHEN q.data1~'^[0-9]+$' THEN q.data1::integer END AS exit_position,"
        . "CASE WHEN q.data3~'^[0-9]+$' THEN q.data3::integer ELSE 0 END AS wait_seconds,"
        . "COALESCE(en.data2,'') AS caller_number "
        . 'FROM queue_log q '
        . 'LEFT JOIN LATERAL (SELECT e.data2 FROM queue_log e '
        . "WHERE e.callid=q.callid AND e.queuename=q.queuename AND e.event='ENTERQUEUE' "
        . 'ORDER BY e.id DESC LIMIT 1) en ON TRUE '
        . 'WHERE q.id>:last AND q.id<=:current '
        . "AND q.event IN ('ABANDON','EXITWITHTIMEOUT') "
        . 'AND q.queuename IN (SELECT queue_id FROM callcenter_queue_notification_recipients '
        . 'WHERE dept_id=:dept AND active) ORDER BY q.id LIMIT 500',
        ['last' => $lastId, 'current' => $currentMax, 'dept' => $deptId]
    );
    foreach ($events as $event) {
        $recipients = callcenter_rows(
            'SELECT email FROM callcenter_queue_notification_recipients '
            . 'WHERE dept_id=:dept AND queue_id=:queue AND active ORDER BY email',
            ['dept' => $deptId, 'queue' => (string) $event['queuename']]
        );
        $deliveryRows = [];
        foreach ($recipients as $recipient) {
            $inserted = callcenter_rows(
                'INSERT INTO callcenter_queue_notification_deliveries '
                . '(dept_id,queue_log_id,queue_id,call_id,recipient_email,caller_number,'
                . 'event_time,exit_position,wait_seconds) '
                . 'VALUES (:dept,:log,:queue,:call,:email,:caller,:time,:position,:wait) '
                . 'ON CONFLICT (dept_id,queue_log_id,recipient_email) DO NOTHING RETURNING *',
                [
                    'dept' => $deptId,
                    'log' => (int) $event['id'],
                    'queue' => (string) $event['queuename'],
                    'call' => (string) $event['callid'],
                    'email' => (string) $recipient['email'],
                    'caller' => (string) $event['caller_number'],
                    'time' => (string) $event['event_time'],
                    'position' => $event['exit_position'] === null ? null : (int) $event['exit_position'],
                    'wait' => max(0, (int) $event['wait_seconds']),
                ]
            );
            if ($inserted) {
                $deliveryRows[] = $inserted[0];
            }
        }
        queue_notification_deliver($deliveryRows, $queueNames);
    }
    callcenter_execute(
        'UPDATE callcenter_queue_notification_state '
        . 'SET last_queue_log_id=:last,updated_at=now() WHERE dept_id=:dept',
        ['last' => $currentMax, 'dept' => $deptId]
    );
}

$failed = callcenter_rows(
    "SELECT * FROM callcenter_queue_notification_deliveries WHERE status='failed' "
    . 'AND attempt_count<3 AND updated_at<now()-interval \'1 minute\' '
    . 'ORDER BY queue_log_id,recipient_email LIMIT 200'
);
$failedGroups = [];
foreach ($failed as $row) {
    $failedGroups[(string) $row['dept_id'] . ':' . (string) $row['queue_log_id']][] = $row;
}
foreach ($failedGroups as $rows) {
    queue_notification_deliver($rows, $queueNames);
}
