<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

function migration_canonical(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    foreach ($value as $key => $item) {
        $value[$key] = migration_canonical($item);
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    return $value;
}

function migration_project(mixed $actual, mixed $shape): mixed
{
    if (!is_array($shape) || !is_array($actual)) {
        return $actual;
    }
    $result = [];
    foreach ($shape as $key => $childShape) {
        if (!array_key_exists($key, $actual)) {
            return ['__missing_key__' => (string) $key];
        }
        $result[$key] = migration_project($actual[$key], $childShape);
    }
    return $result;
}

function migration_hash(array $rows): string
{
    $json = json_encode(
        migration_canonical($rows),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
    );
    if ($json === false) {
        throw new RuntimeException('PBX doğrulama özeti hazırlanamadı');
    }
    return hash('sha256', $json);
}

$marker = DATA_PATH . DIRECTORY_SEPARATOR . '.relational-store-v1';
if (is_file($marker)) {
    echo json_encode([
        'ok' => true,
        'migration' => '005_pbx_config_relational',
        'status' => 'already_applied',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$lock = DATA_PATH . DIRECTORY_SEPARATOR . '.relational-migration-lock';
if (!@touch($lock)) {
    throw new RuntimeException('PBX migration bakım kilidi oluşturulamadı');
}

try {
// Read every legacy store before enabling relational reads.
$legacy = ['departments' => store_read('departments')];
foreach (pbx_relational_stores() as $store) {
    $legacy[$store] = store_read($store);
}

$migrationFile = dirname(__DIR__) . '/migrations/005_pbx_config_relational.sql';
$sql = file_get_contents($migrationFile);
if ($sql === false || trim($sql) === '') {
    throw new RuntimeException('PBX ilişkisel migration dosyası okunamadı');
}
$result = pbx_pg_admin($sql);
if (!$result['ok']) {
    throw new RuntimeException('PBX ilişkisel şeması hazırlanamadı: ' . ($result['output'] ?? ''));
}

// Tenants must exist before tenant-owned PBX rows can be inserted.
pbx_relational_write('departments', $legacy['departments']);
$counts = [];
foreach (pbx_relational_stores() as $store) {
    pbx_relational_write($store, $legacy[$store]);
    $counts[$store] = count($legacy[$store]);
}

$ok = false;
$rows = pbx_odbc_rows(
    "SELECT "
    . "to_regclass('pbx_config_entities') IS NOT NULL AS entities_table, "
    . "to_regclass('pbx_config_values') IS NOT NULL AS values_table, "
    . "(SELECT count(*) FROM pbx_config_entities WHERE parent_entity_id IS NULL) AS root_count",
    $ok
);
if (!$ok || !isset($rows[0])) {
    throw new RuntimeException('PBX ilişkisel veri aktarımı doğrulanamadı');
}
$expectedRoots = array_sum($counts);
$actualRoots = (int) ($rows[0]['root_count'] ?? -1);
if ($actualRoots !== $expectedRoots) {
    throw new RuntimeException(
        "PBX kayıt sayısı uyuşmuyor; beklenen {$expectedRoots}, aktarılan {$actualRoots}"
    );
}

// Validate every field, scalar type, nested key and list position before cutover.
foreach (pbx_relational_stores() as $store) {
    $actual = pbx_relational_read($store);
    if (migration_hash($actual) !== migration_hash($legacy[$store])) {
        throw new RuntimeException("{$store} kayıpsız veri doğrulaması başarısız");
    }
}
$actualDepartments = pbx_relational_read('departments');
if (migration_hash(migration_project($actualDepartments, $legacy['departments']))
    !== migration_hash($legacy['departments'])) {
    throw new RuntimeException('departments kayıpsız veri doğrulaması başarısız');
}

if (file_put_contents($marker, date(DATE_ATOM) . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('İlişkisel veri deposu etkinleştirme işareti yazılamadı');
}
try {
    $versionResult = pbx_pg_admin(
        "INSERT INTO astera_schema_migrations (version) VALUES ('005_pbx_config_relational') "
        . "ON CONFLICT (version) DO NOTHING"
    );
    if (!$versionResult['ok']) {
        throw new RuntimeException(
            'PBX migration sürümü kaydedilemedi: ' . ($versionResult['output'] ?? '')
        );
    }
} catch (Throwable $e) {
    @unlink($marker);
    throw $e;
}

echo json_encode([
    'ok' => true,
    'migration' => '005_pbx_config_relational',
    'root_records' => $actualRoots,
    'stores' => $counts,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    @unlink($lock);
}
