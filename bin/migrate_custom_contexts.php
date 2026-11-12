<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$migrationFile = dirname(__DIR__) . '/migrations/009_custom_contexts.sql';
$sql = file_get_contents($migrationFile);
if ($sql === false || trim($sql) === '') {
    throw new RuntimeException('Özel context migration dosyası okunamadı');
}

$result = pbx_pg_admin($sql);
if (!$result['ok']) {
    throw new RuntimeException('Özel context tabloları hazırlanamadı: ' . ($result['output'] ?? ''));
}

$ok = false;
$rows = pbx_odbc_rows(
    "SELECT to_regclass('pbx_custom_contexts') IS NOT NULL AS contexts_table, "
    . "to_regclass('pbx_custom_context_steps') IS NOT NULL AS steps_table",
    $ok
);
if (
    !$ok
    || !isset($rows[0])
    || in_array(strtolower((string) ($rows[0]['contexts_table'] ?? '')), ['', '0', 'f', 'false'], true)
    || in_array(strtolower((string) ($rows[0]['steps_table'] ?? '')), ['', '0', 'f', 'false'], true)
) {
    throw new RuntimeException('Özel context tabloları doğrulanamadı');
}

echo json_encode([
    'ok' => true,
    'migration' => '009_custom_contexts',
    'contexts_table' => true,
    'steps_table' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
