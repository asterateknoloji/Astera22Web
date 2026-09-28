<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$monthsAhead = isset($argv[1]) ? (int) $argv[1] : 18;
if ($monthsAhead < 2 || $monthsAhead > 36) {
    throw new InvalidArgumentException('Ay sayısı 2 ile 36 arasında olmalı');
}

$ok = false;
$rows = pbx_odbc_rows(
    'SELECT partition_name, created FROM astera_create_cdr_partitions(' . $monthsAhead . ')',
    $ok
);
if (!$ok) {
    throw new RuntimeException('CDR partition bakımı çalıştırılamadı');
}

$checkOk = false;
$check = pbx_odbc_rows(
    'SELECT astera_cdr_default_partition_rows() AS default_count',
    $checkOk
);
if (!$checkOk || (int) ($check[0]['default_count'] ?? -1) !== 0) {
    throw new RuntimeException('Varsayılan CDR partition içinde taşınması gereken satır var');
}

echo json_encode([
    'ok' => true,
    'months_ahead' => $monthsAhead,
    'partitions' => $rows,
    'default_partition_rows' => 0,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
