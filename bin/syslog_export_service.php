#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/syslog_integration.php';
require_once dirname(__DIR__) . '/includes/alarms.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

function syslog_cursor(int $connectorId, string $source): int
{
    return (int) (callcenter_rows(
        'SELECT last_id FROM integration_syslog_cursors '
        . 'WHERE connector_id=:connector AND source_name=:source',
        ['connector' => $connectorId, 'source' => $source]
    )[0]['last_id'] ?? 0);
}

function syslog_advance_cursor(int $connectorId, string $source, int $lastId): void
{
    callcenter_execute(
        'INSERT INTO integration_syslog_cursors (connector_id,source_name,last_id) '
        . 'VALUES (:connector,:source,:last) ON CONFLICT (connector_id,source_name) '
        . 'DO UPDATE SET last_id=GREATEST(integration_syslog_cursors.last_id,EXCLUDED.last_id),updated_at=now()',
        ['connector' => $connectorId, 'source' => $source, 'last' => $lastId]
    );
}

$definitions = astera_alarm_definitions();
while ($running) {
    try {
        $connectors = integration_syslog_enabled_connectors();
    } catch (Throwable $exception) {
        error_log('Syslog yapılandırması okunamadı: ' . $exception->getMessage());
        sleep(2);
        continue;
    }
    foreach ($connectors as $connector) {
        $connectorId = (int) $connector['connector_id'];
        try {
            $deptId = (string) $connector['dept_id'];
            $lastAlarmId = syslog_cursor($connectorId, 'alarm_events');
            $alarmRows = callcenter_rows(
                'SELECT event_id,alarm_key,event_type,severity,summary,occurred_at '
                . 'FROM callcenter_alarm_events WHERE dept_id=:dept AND event_id>:last '
                . 'ORDER BY event_id LIMIT 100',
                ['dept' => $deptId, 'last' => $lastAlarmId]
            );
            foreach ($alarmRows as $row) {
                $eventId = (int) $row['event_id'];
                $group = (string) ($definitions[(string) $row['alarm_key']][0] ?? 'pbx');
                $shouldSend = ($group === 'system' && integration_syslog_bool($connector['export_system_alarms']))
                    || ($group !== 'system' && integration_syslog_bool($connector['export_pbx_alarms']));
                if ($shouldSend) {
                    integration_syslog_send(
                        $connector,
                        (string) $row['severity'],
                        'alarm_' . (string) $row['alarm_key'],
                        sprintf(
                            'dept=%s alarm=%s event=%s summary="%s"',
                            $deptId,
                            (string) $row['alarm_key'],
                            (string) $row['event_type'],
                            str_replace('"', "'", (string) $row['summary'])
                        )
                    );
                }
                syslog_advance_cursor($connectorId, 'alarm_events', $eventId);
            }

            if (integration_syslog_bool($connector['export_audit_logs'])) {
                $lastAuditId = syslog_cursor($connectorId, 'audit_logs');
                $auditRows = callcenter_rows(
                    'SELECT audit_id,username,event_type,category,page,action,description,ip_address,created_at '
                    . 'FROM callcenter_audit_logs WHERE dept_id=:dept AND audit_id>:last '
                    . 'ORDER BY audit_id LIMIT 100',
                    ['dept' => $deptId, 'last' => $lastAuditId]
                );
                foreach ($auditRows as $row) {
                    $auditId = (int) $row['audit_id'];
                    integration_syslog_send(
                        $connector,
                        (string) $row['event_type'] === 'access_denied' ? 'warning' : 'info',
                        'audit_' . (string) $row['event_type'],
                        sprintf(
                            'dept=%s user=%s category=%s page=%s action="%s" ip=%s description="%s"',
                            $deptId,
                            (string) $row['username'],
                            (string) $row['category'],
                            (string) $row['page'],
                            str_replace('"', "'", (string) $row['action']),
                            (string) ($row['ip_address'] ?? '-'),
                            str_replace('"', "'", (string) $row['description'])
                        )
                    );
                    syslog_advance_cursor($connectorId, 'audit_logs', $auditId);
                }
            }
            if ($alarmRows || (!empty($auditRows) && integration_syslog_bool($connector['export_audit_logs']))) {
                integration_connector_status($connectorId, 'connected');
            }
        } catch (Throwable $exception) {
            error_log("Syslog aktarım hatası [{$connectorId}]: " . $exception->getMessage());
            integration_connector_status($connectorId, 'error', $exception->getMessage());
        }
    }
    sleep(2);
}
