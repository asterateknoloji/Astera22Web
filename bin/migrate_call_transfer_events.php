<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$migrationFile = dirname(__DIR__) . '/migrations/006_call_transfer_events.sql';
$sql = file_get_contents($migrationFile);
if ($sql === false || trim($sql) === '') {
    throw new RuntimeException('Çağrı aktarım migration dosyası okunamadı');
}

$result = pbx_pg_admin($sql);
if (!$result['ok']) {
    throw new RuntimeException(
        'Çağrı aktarım tabloları hazırlanamadı: ' . ($result['output'] ?? '')
    );
}

$ok = false;
$rows = pbx_odbc_rows(
    "SELECT "
    . "to_regclass('call_transfer_events') IS NOT NULL AS transfer_table, "
    . "to_regprocedure('astera_sync_call_transfer_event()') IS NOT NULL AS transfer_sync, "
    . "EXISTS (SELECT 1 FROM pg_trigger "
    . "WHERE tgname = 'astera_sync_call_transfer_event_trigger' "
    . "AND NOT tgisinternal) AS transfer_trigger",
    $ok
);
if (
    !$ok
    || !isset($rows[0])
    || in_array(strtolower((string) ($rows[0]['transfer_table'] ?? '')), ['', '0', 'f', 'false'], true)
    || in_array(strtolower((string) ($rows[0]['transfer_sync'] ?? '')), ['', '0', 'f', 'false'], true)
    || in_array(strtolower((string) ($rows[0]['transfer_trigger'] ?? '')), ['', '0', 'f', 'false'], true)
) {
    throw new RuntimeException('Çağrı aktarım veritabanı nesneleri doğrulanamadı');
}

echo json_encode([
    'ok' => true,
    'migration' => '006_call_transfer_events',
    'transfer_table' => true,
    'cdr_transfer_sync' => true,
    'cdr_transfer_trigger' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
