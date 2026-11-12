<?php
declare(strict_types=1);

require_once __DIR__ . '/integrations.php';

function integration_syslog_connector(string $deptId): ?array
{
    return callcenter_rows(
        'SELECT c.*,s.transport,s.facility,s.app_name,s.export_system_alarms,'
        . 's.export_pbx_alarms,s.export_audit_logs '
        . 'FROM integration_connectors c JOIN integration_syslog_settings s '
        . 'ON s.connector_id=c.connector_id '
        . 'WHERE c.dept_id=:dept AND c.provider=:provider ORDER BY c.connector_id LIMIT 1',
        ['dept' => $deptId, 'provider' => 'remote_syslog']
    )[0] ?? null;
}

function integration_syslog_enabled_connectors(): array
{
    return callcenter_rows(
        'SELECT c.*,s.transport,s.facility,s.app_name,s.export_system_alarms,'
        . 's.export_pbx_alarms,s.export_audit_logs '
        . 'FROM integration_connectors c JOIN integration_syslog_settings s '
        . 'ON s.connector_id=c.connector_id '
        . 'WHERE c.provider=:provider AND c.enabled ORDER BY c.connector_id',
        ['provider' => 'remote_syslog']
    );
}

function integration_syslog_bool(mixed $value): bool
{
    return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
}

function integration_syslog_severity(string $severity): int
{
    return match (strtolower($severity)) {
        'emergency', 'emerg' => 0,
        'alert' => 1,
        'critical', 'crit' => 2,
        'error', 'err', 'failed' => 3,
        'warning', 'warn' => 4,
        'notice' => 5,
        'debug' => 7,
        default => 6,
    };
}

function integration_syslog_token(string $value, int $maxLength): string
{
    $value = preg_replace('/[^A-Za-z0-9_.@:-]+/', '_', trim($value)) ?? '';
    return substr($value !== '' ? $value : '-', 0, $maxLength);
}

function integration_syslog_send(
    array $connector,
    string $severity,
    string $messageId,
    string $message
): void {
    $host = trim((string) ($connector['remote_host'] ?? ''));
    $port = (int) ($connector['port'] ?? 514);
    $transport = (string) ($connector['transport'] ?? 'udp');
    if ($host === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('Syslog hedef adresi veya portu geçersiz.');
    }
    $scheme = match ($transport) {
        'tcp' => 'tcp',
        'tls' => 'tls',
        default => 'udp',
    };
    $context = null;
    if ($transport === 'tls') {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);
    }
    $socket = @stream_socket_client(
        $scheme . '://' . $host . ':' . $port,
        $errno,
        $error,
        (int) ($connector['connect_timeout_seconds'] ?? 10),
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!$socket) {
        throw new RuntimeException("Syslog bağlantısı kurulamadı: {$error} ({$errno})");
    }
    try {
        $priority = ((int) ($connector['facility'] ?? 16) * 8)
            + integration_syslog_severity($severity);
        $payload = sprintf(
            '<%d>1 %s %s %s %s %s - %s',
            $priority,
            gmdate('Y-m-d\TH:i:s\Z'),
            integration_syslog_token(gethostname() ?: 'astera-pbx', 255),
            integration_syslog_token((string) ($connector['app_name'] ?? 'astera-pbx'), 48),
            (string) getmypid(),
            integration_syslog_token($messageId, 32),
            str_replace(["\r", "\n", "\0"], [' ', ' ', ''], $message)
        );
        $payload .= $transport === 'udp' ? '' : "\n";
        if (@fwrite($socket, $payload) === false) {
            throw new RuntimeException('Syslog mesajı gönderilemedi.');
        }
    } finally {
        fclose($socket);
    }
}
