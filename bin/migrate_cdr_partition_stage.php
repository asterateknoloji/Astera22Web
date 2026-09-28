<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$migrationFile = dirname(__DIR__) . '/migrations/002_cdr_partition_stage.sql';
$sql = file_get_contents($migrationFile);
if ($sql === false || trim($sql) === '') {
    throw new RuntimeException('CDR partition migration dosyası okunamadı');
}

$result = pbx_pg_admin($sql);
if (!$result['ok']) {
    throw new RuntimeException('CDR partition şeması hazırlanamadı: ' . ($result['output'] ?? ''));
}

$ok = false;
$rows = pbx_odbc_rows(
    "SELECT "
    . "(SELECT count(*) FROM cdr) AS source_count, "
    . "(SELECT count(*) FROM cdr_partitioned) AS partitioned_count, "
    . "(SELECT count(*) FROM cdr s WHERE NOT EXISTS ("
    . "SELECT 1 FROM cdr_partitioned p WHERE p.calldate = s.calldate AND p.id = s.id"
    . ")) AS missing_count, "
    . "(SELECT count(*) FROM cdr_partitioned "
    . "WHERE tableoid = 'cdr_partitioned_default'::regclass) AS default_count, "
    . "(SELECT count(*) FROM cdr_partitioned WHERE dept_id IS NULL) AS tenantless_count",
    $ok
);
if (!$ok || !isset($rows[0])) {
    throw new RuntimeException('CDR partition sayım doğrulaması çalıştırılamadı');
}
$counts = array_map('intval', $rows[0]);
if (
    $counts['source_count'] !== $counts['partitioned_count']
    || $counts['missing_count'] !== 0
    || $counts['default_count'] !== 0
    || $counts['tenantless_count'] !== 0
) {
    throw new RuntimeException('CDR partition veri doğrulaması başarısız: ' . json_encode($counts));
}

$probe = 'partition-probe-' . bin2hex(random_bytes(6));
$probeResult = pbx_odbc_sql(
    "BEGIN;\n"
    . "INSERT INTO cdr (src, dst, dcontext, accountcode, uniqueid, linkedid, disposition, duration, billsec) "
    . "VALUES ('100', '101', 'from-genel', 'genel', " . pbx_sql_literal($probe) . ', '
    . pbx_sql_literal($probe) . ", 'NO ANSWER', 0, 0);\n"
    . "DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM cdr_partitioned WHERE uniqueid = "
    . pbx_sql_literal($probe) . ") THEN RAISE EXCEPTION 'CDR mirror probe failed'; END IF; END \$\$;\n"
    . "ROLLBACK"
);
if (!$probeResult['ok'] || str_contains((string) ($probeResult['output'] ?? ''), '[ISQL]ERROR')) {
    throw new RuntimeException('CDR çift yazım doğrulaması başarısız: ' . ($probeResult['output'] ?? ''));
}

echo json_encode([
    'ok' => true,
    'migration' => '002_cdr_partition_stage',
    'counts' => $counts,
    'mirror_probe' => true,
    'cutover_performed' => false,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
