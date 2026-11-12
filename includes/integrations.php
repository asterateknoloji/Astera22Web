<?php
declare(strict_types=1);

require_once __DIR__ . '/callcenter.php';

function integration_fidelio_connector(string $deptId): ?array
{
    return callcenter_rows(
        'SELECT c.*,f.fias_version,f.hotel_code,f.charset,f.message_terminator,'
        . 'f.keepalive_seconds,f.auto_create_room_mapping '
        . 'FROM integration_connectors c JOIN pms_fidelio_settings f ON f.connector_id=c.connector_id '
        . 'WHERE c.dept_id=:dept AND c.provider=:provider ORDER BY c.connector_id LIMIT 1',
        ['dept' => $deptId, 'provider' => 'fidelio_fias']
    )[0] ?? null;
}

function integration_fidelio_enabled_connectors(): array
{
    return callcenter_rows(
        'SELECT c.*,f.fias_version,f.hotel_code,f.charset,f.message_terminator,'
        . 'f.keepalive_seconds,f.auto_create_room_mapping '
        . 'FROM integration_connectors c JOIN pms_fidelio_settings f ON f.connector_id=c.connector_id '
        . 'WHERE c.provider=:provider AND c.enabled ORDER BY c.connector_id',
        ['provider' => 'fidelio_fias']
    );
}

function integration_fias_parse(string $message): array
{
    $message = trim($message, "\0\x03\r\n ");
    if ($message === '') {
        throw new InvalidArgumentException('Boş FIAS mesajı.');
    }
    $parts = explode('|', $message);
    $recordType = strtoupper(substr(trim((string) array_shift($parts)), 0, 8));
    if (!preg_match('/^[A-Z0-9]{2,8}$/', $recordType)) {
        throw new InvalidArgumentException('FIAS kayıt türü geçersiz.');
    }
    $fields = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if (strlen($part) < 2) continue;
        $code = strtoupper(substr($part, 0, 2));
        if (!preg_match('/^[A-Z0-9]{2}$/', $code)) continue;
        $fields[$code] = substr($part, 2);
    }
    return ['type' => $recordType, 'fields' => $fields, 'raw' => $message];
}

function integration_fias_terminator(array $connector): string
{
    return match ((string) ($connector['message_terminator'] ?? 'CRLF')) {
        'LF' => "\n",
        'ETX' => "\x03",
        default => "\r\n",
    };
}

function integration_fias_to_utf8(string $message, array $connector): string
{
    $charset = (string) ($connector['charset'] ?? 'UTF-8');
    if ($charset === 'UTF-8') return $message;
    $converted = @iconv($charset, 'UTF-8//TRANSLIT', $message);
    return $converted === false ? $message : $converted;
}

function integration_fias_from_utf8(string $message, array $connector): string
{
    $charset = (string) ($connector['charset'] ?? 'UTF-8');
    if ($charset === 'UTF-8') return $message;
    $converted = @iconv('UTF-8', $charset . '//TRANSLIT', $message);
    return $converted === false ? $message : $converted;
}

function integration_fias_message(string $type, array $fields = []): string
{
    $parts = [strtoupper($type)];
    foreach ($fields as $code => $value) {
        $code = strtoupper(substr((string) $code, 0, 2));
        $parts[] = $code . str_replace(["\r", "\n", '|', "\x03"], ' ', (string) $value);
    }
    return implode('|', $parts);
}

function integration_fias_log(
    array $connector,
    string $direction,
    string $message,
    string $status = 'received',
    string $error = ''
): int {
    try {
        $parsed = integration_fias_parse($message);
        $type = $parsed['type'];
        $room = (string) ($parsed['fields']['RN'] ?? '');
        $guest = (string) ($parsed['fields']['GN'] ?? '');
    } catch (Throwable) {
        $type = 'UNKNOWN';
        $room = '';
        $guest = '';
    }
    $row = callcenter_rows(
        'INSERT INTO pms_fias_events '
        . '(dept_id,connector_id,direction,record_type,room_number,guest_name,raw_message,processing_status,error_message) '
        . 'VALUES (:dept,:connector,:direction,:type,:room,:guest,:raw,:status,:error) RETURNING event_id',
        [
            'dept' => (string) $connector['dept_id'],
            'connector' => (int) $connector['connector_id'],
            'direction' => $direction,
            'type' => $type,
            'room' => mb_substr($room, 0, 40),
            'guest' => mb_substr($guest, 0, 255),
            'raw' => mb_substr($message, 0, 16000),
            'status' => $status,
            'error' => mb_substr($error, 0, 2000),
        ]
    )[0] ?? [];
    return (int) ($row['event_id'] ?? 0);
}

function integration_fias_process(array $connector, string $message): array
{
    $eventId = integration_fias_log($connector, 'incoming', $message);
    try {
        $parsed = integration_fias_parse($message);
        $type = (string) $parsed['type'];
        $fields = (array) $parsed['fields'];
        $room = trim((string) ($fields['RN'] ?? ''));
        $guest = trim((string) ($fields['GN'] ?? ''));
        $language = trim((string) ($fields['GL'] ?? ''));
        $status = 'processed';

        if ($room !== '' && $type === 'GI') {
            if (!empty($connector['auto_create_room_mapping'])) {
                callcenter_execute(
                    'INSERT INTO pms_room_mappings '
                    . '(dept_id,connector_id,room_number,extension_number,occupancy_status,guest_name,guest_language,checked_in_at) '
                    . 'VALUES (:dept,:connector,:room,:extension,:occupancy,:guest,:language,now()) '
                    . 'ON CONFLICT (connector_id,room_number) DO UPDATE SET '
                    . 'occupancy_status=EXCLUDED.occupancy_status,guest_name=EXCLUDED.guest_name,'
                    . 'guest_language=EXCLUDED.guest_language,checked_in_at=now(),checked_out_at=NULL,updated_at=now()',
                    [
                        'dept' => (string) $connector['dept_id'],
                        'connector' => (int) $connector['connector_id'],
                        'room' => mb_substr($room, 0, 40),
                        'extension' => mb_substr($room, 0, 40),
                        'occupancy' => 'occupied',
                        'guest' => mb_substr($guest, 0, 255),
                        'language' => mb_substr($language, 0, 20),
                    ]
                );
            } else {
                callcenter_execute(
                    'UPDATE pms_room_mappings SET occupancy_status=:occupancy,guest_name=:guest,'
                    . 'guest_language=:language,checked_in_at=now(),checked_out_at=NULL,updated_at=now() '
                    . 'WHERE connector_id=:connector AND room_number=:room',
                    [
                        'occupancy' => 'occupied', 'guest' => mb_substr($guest, 0, 255),
                        'language' => mb_substr($language, 0, 20),
                        'connector' => (int) $connector['connector_id'], 'room' => $room,
                    ]
                );
            }
        } elseif ($room !== '' && $type === 'GO') {
            callcenter_execute(
                'UPDATE pms_room_mappings SET occupancy_status=:occupancy,guest_name=:guest,'
                . 'guest_language=:language,checked_out_at=now(),updated_at=now() '
                . 'WHERE connector_id=:connector AND room_number=:room',
                [
                    'occupancy' => 'vacant', 'guest' => '', 'language' => '',
                    'connector' => (int) $connector['connector_id'], 'room' => $room,
                ]
            );
        } elseif ($room !== '' && $type === 'RS') {
            callcenter_execute(
                'UPDATE pms_room_mappings SET room_status=:status,updated_at=now() '
                . 'WHERE connector_id=:connector AND room_number=:room',
                [
                    'status' => mb_substr((string) ($fields['RS'] ?? ''), 0, 40),
                    'connector' => (int) $connector['connector_id'], 'room' => $room,
                ]
            );
        } elseif (!in_array($type, ['LS', 'LD', 'LA'], true)) {
            $status = 'ignored';
        }
        callcenter_execute(
            'UPDATE pms_fias_events SET processing_status=:status WHERE event_id=:event',
            ['status' => $status, 'event' => $eventId]
        );
        return $parsed;
    } catch (Throwable $exception) {
        callcenter_execute(
            'UPDATE pms_fias_events SET processing_status=:status,error_message=:error WHERE event_id=:event',
            ['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'event' => $eventId]
        );
        throw $exception;
    }
}

function integration_connector_status(int $connectorId, string $status, string $error = ''): void
{
    $connectedSql = $status === 'connected' ? ',last_connected_at=now()' : '';
    callcenter_execute(
        'UPDATE integration_connectors SET connection_status=:status,last_error=:error,updated_at=now()'
        . $connectedSql . ' WHERE connector_id=:connector',
        ['status' => $status, 'error' => mb_substr($error, 0, 2000), 'connector' => $connectorId]
    );
}
