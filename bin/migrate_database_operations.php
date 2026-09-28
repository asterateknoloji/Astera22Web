<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$migrationFile = dirname(__DIR__) . '/migrations/003_database_operations.sql';
$sql = file_get_contents($migrationFile);
if ($sql === false || trim($sql) === '') {
    throw new RuntimeException('Veritabanı operasyon migration dosyası okunamadı');
}

$result = pbx_pg_admin($sql);
if (!$result['ok']) {
    throw new RuntimeException('Veritabanı operasyonları hazırlanamadı: ' . ($result['output'] ?? ''));
}

$ok = false;
$rows = pbx_odbc_rows(
    "SELECT "
    . "to_regclass('crm_customer_phones_normalized_trgm_idx') IS NOT NULL AS phone_search_index, "
    . "to_regprocedure('astera_create_cdr_partitions(integer)') IS NOT NULL AS partition_maintenance, "
    . "to_regprocedure('astera_cdr_default_partition_rows()') IS NOT NULL AS partition_check, "
    . "to_regprocedure('astera_sync_call_recording_metadata()') IS NOT NULL AS recording_sync",
    $ok
);
if (
    !$ok
    || !isset($rows[0])
    || in_array(strtolower((string) ($rows[0]['phone_search_index'] ?? '')), ['', '0', 'f', 'false'], true)
    || in_array(strtolower((string) ($rows[0]['partition_maintenance'] ?? '')), ['', '0', 'f', 'false'], true)
    || in_array(strtolower((string) ($rows[0]['partition_check'] ?? '')), ['', '0', 'f', 'false'], true)
    || in_array(strtolower((string) ($rows[0]['recording_sync'] ?? '')), ['', '0', 'f', 'false'], true)
) {
    throw new RuntimeException('Veritabanı operasyon nesneleri doğrulanamadı');
}

echo json_encode([
    'ok' => true,
    'migration' => '003_database_operations',
    'phone_search_index' => true,
    'partition_maintenance' => true,
    'recording_metadata_sync' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
