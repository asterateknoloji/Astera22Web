<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$migrationFile = dirname(__DIR__) . '/migrations/007_softphone_crm_tokens.sql';
$sql = file_get_contents($migrationFile);
if ($sql === false || trim($sql) === '') {
    throw new RuntimeException('Softphone CRM token migration dosyası okunamadı');
}

$result = pbx_pg_admin($sql);
if (!$result['ok']) {
    throw new RuntimeException(
        'Softphone CRM token tablosu hazırlanamadı: ' . ($result['output'] ?? '')
    );
}

$ok = false;
$rows = pbx_odbc_rows(
    "SELECT to_regclass('softphone_crm_tokens') IS NOT NULL AS token_table",
    $ok
);
if (
    !$ok
    || !isset($rows[0])
    || in_array(strtolower((string) ($rows[0]['token_table'] ?? '')), ['', '0', 'f', 'false'], true)
) {
    throw new RuntimeException('Softphone CRM token tablosu doğrulanamadı');
}

echo json_encode([
    'ok' => true,
    'migration' => '007_softphone_crm_tokens',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
