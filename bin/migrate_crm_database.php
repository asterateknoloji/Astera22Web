<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$migrationFile = dirname(__DIR__) . '/migrations/001_crm_relational.sql';
$sql = file_get_contents($migrationFile);
if ($sql === false || trim($sql) === '') {
    throw new RuntimeException('CRM migration dosyası okunamadı');
}

$result = pbx_pg_admin($sql);
if (!$result['ok']) {
    throw new RuntimeException('CRM şeması oluşturulamadı: ' . ($result['output'] ?? ''));
}

$imported = crm_import_legacy_stores();
$customers = crm_customers_db();
$notes = crm_call_notes_db();
if (count($customers) < $imported['customers'] || count($notes) < $imported['notes']) {
    throw new RuntimeException('CRM veri aktarımı sayım doğrulamasından geçemedi');
}

echo json_encode([
    'ok' => true,
    'migration' => '001_crm_relational',
    'imported' => $imported,
    'database_counts' => [
        'customers' => count($customers),
        'notes' => count($notes),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
