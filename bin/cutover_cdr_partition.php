<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$channels = pbx_cli('core show channels count');
if (preg_match('/(\d+)\s+active calls?/i', $channels, $match) && (int) $match[1] > 0) {
    throw new RuntimeException('Aktif çağrı bulunduğu için CDR cutover durduruldu');
}

$backup = pbx_ssh(
    'set -e; sudo -u postgres pg_dump -Fc -d ' . escapeshellarg(PBX_DB_NAME)
    . ' -t public.cdr -f /var/backups/astera/cdr-cutover-$(date +%Y%m%d-%H%M%S).dump'
);
if (!$backup['ok']) {
    throw new RuntimeException('Cutover öncesi CDR yedeği alınamadı: ' . ($backup['output'] ?? ''));
}

$migrationFile = dirname(__DIR__) . '/migrations/004_cdr_partition_cutover.sql';
$sql = file_get_contents($migrationFile);
if ($sql === false || trim($sql) === '') {
    throw new RuntimeException('CDR cutover migration dosyası okunamadı');
}
$cutover = pbx_pg_admin($sql);
if (!$cutover['ok']) {
    throw new RuntimeException('CDR cutover başarısız: ' . ($cutover['output'] ?? ''));
}

$restart = pbx_ssh('sudo systemctl restart asterisk && sudo systemctl is-active asterisk');
if (!$restart['ok'] || !str_contains((string) ($restart['output'] ?? ''), 'active')) {
    throw new RuntimeException(
        'CDR tablosu geçirildi fakat Asterisk yeniden başlatılamadı; eski tablo mirror ile korunuyor: '
        . ($restart['output'] ?? '')
    );
}

$finalize = pbx_pg_admin(
    "DROP TRIGGER IF EXISTS astera_mirror_cdr_partitioned_trigger "
    . "ON cdr_unpartitioned_archive;\nANALYZE cdr;"
);
if (!$finalize['ok']) {
    throw new RuntimeException('Cutover tamamlandı fakat eski mirror trigger kaldırılamadı');
}

$ok = false;
$rows = pbx_odbc_rows(
    "SELECT c.relkind, "
    . "(SELECT count(*) FROM cdr_unpartitioned_archive s WHERE NOT EXISTS ("
    . "SELECT 1 FROM cdr p WHERE p.calldate = s.calldate AND p.id = s.id"
    . ")) AS missing_count "
    . "FROM pg_class c WHERE c.oid = 'cdr'::regclass",
    $ok
);
if (!$ok || ($rows[0]['relkind'] ?? '') !== 'p' || (int) ($rows[0]['missing_count'] ?? -1) !== 0) {
    throw new RuntimeException('Cutover sonrası CDR doğrulaması başarısız');
}

echo json_encode([
    'ok' => true,
    'migration' => '004_cdr_partition_cutover',
    'asterisk' => 'active',
    'partitioned_cdr' => true,
    'missing_rows' => 0,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
