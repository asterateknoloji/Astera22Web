<?php
declare(strict_types=1);

function store_init(): void
{
    if (!is_dir(DATA_PATH)) {
        mkdir(DATA_PATH, 0777, true);
    }
    if (!is_dir(GEN_PATH)) {
        mkdir(GEN_PATH, 0777, true);
    }

    $defaults = [
        'extensions' => [
            [
                'exten' => '1001',
                'name' => 'Abone 1001',
                'password' => '1001pass',
                'max_contacts' => 1,
                'codecs' => ['alaw', 'ulaw'],
                'callerid' => 'Abone 1001',
            ],
            [
                'exten' => '1002',
                'name' => 'Abone 1002',
                'password' => '1002pass',
                'max_contacts' => 1,
                'codecs' => ['alaw', 'ulaw'],
                'callerid' => 'Abone 1002',
            ],
        ],
        'trunks' => [],
        'outbound' => [],
        'inbound' => [],
        'queues' => [],
        'departments' => [],
        'ringgroups' => [],
        'ivrs' => [],
        'timeconditions' => [],
        'announcements' => [],
        'conferences' => [],
        'blacklist' => [],
        'parking' => [],
        'flows' => [],
        'disas' => [],
        'pagings' => [],
        'speeddials' => [],
        'customs' => [],
        'sounds' => [],
        'media' => [],
        'url_triggers' => [],
        'pending_changes' => [],
    ];

    foreach ($defaults as $name => $value) {
        $file = DATA_PATH . DIRECTORY_SEPARATOR . $name . '.json';
        if (!is_file($file)) {
            store_local_write($name, $value);
        }
    }

    store_database_init(array_keys($defaults));
}

function store_read(string $name): array
{
    store_validate_name($name);
    if (function_exists('pbx_relational_enabled')
        && pbx_relational_enabled()
        && pbx_relational_supports($name)) {
        return pbx_relational_read($name);
    }
    $file = DATA_PATH . DIRECTORY_SEPARATOR . $name . '.json';
    if (!is_file($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

function store_write(string $name, array $data): void
{
    store_validate_name($name);
    if (is_file(DATA_PATH . DIRECTORY_SEPARATOR . '.relational-migration-lock')) {
        throw new RuntimeException('Veritabanı geçişi sürüyor; lütfen işlemi kısa süre sonra yeniden deneyin');
    }
    if (function_exists('pbx_relational_enabled')
        && pbx_relational_enabled()
        && pbx_relational_supports($name)) {
        pbx_relational_write($name, array_values($data));
    } else {
        store_database_write($name, array_values($data));
    }
    store_local_write($name, array_values($data));
    @unlink(DATA_PATH . DIRECTORY_SEPARATOR . '.database-write-pending');
}

function store_local_write(string $name, array $data): void
{
    $file = DATA_PATH . DIRECTORY_SEPARATOR . $name . '.json';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Yapılandırma JSON biçimine çevrilemedi: ' . $name);
    }
    $temp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($temp, $json . PHP_EOL, LOCK_EX) === false || !rename($temp, $file)) {
        @unlink($temp);
        throw new RuntimeException('Yerel yapılandırma önbelleği yazılamadı: ' . $name);
    }
}

function store_upsert(string $name, array $item, string $key): array
{
    $rows = store_read($name);
    $found = false;
    foreach ($rows as $i => $row) {
        if ((string) ($row[$key] ?? '') === (string) $item[$key]) {
            $rows[$i] = array_merge($row, $item);
            $found = true;
            break;
        }
    }
    if (!$found) {
        $rows[] = $item;
    }
    store_write($name, array_values($rows));
    return $rows;
}

function store_delete(string $name, string $key, string $value): array
{
    $rows = array_values(array_filter(
        store_read($name),
        static fn($row) => (string) ($row[$key] ?? '') !== $value
    ));
    store_write($name, $rows);
    return $rows;
}

function find_by(string $name, string $key, string $value): ?array
{
    foreach (store_read($name) as $row) {
        if ((string) ($row[$key] ?? '') === $value) {
            return $row;
        }
    }
    return null;
}

function store_validate_name(string $name): void
{
    if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
        throw new InvalidArgumentException('Geçersiz yapılandırma bölümü');
    }
}

function store_actor(): string
{
    return substr((string) ($_SESSION['username'] ?? $_SESSION['user'] ?? 'system'), 0, 120);
}

function store_mark_pending(?string $dept = null, ?string $action = null): array
{
    $dept = ast_sanitize_id((string) ($dept ?? '')) ?: '*';
    $action = ast_sanitize_id((string) ($action ?? 'change')) ?: 'change';
    $rows = store_read('pending_changes');
    $found = false;
    foreach ($rows as &$row) {
        if ((string) ($row['dept'] ?? '*') !== $dept) {
            continue;
        }
        $actions = is_array($row['actions'] ?? null) ? $row['actions'] : [];
        $actions[] = $action;
        $row['actions'] = array_values(array_unique($actions));
        $row['count'] = max(0, (int) ($row['count'] ?? 0)) + 1;
        $row['updated_at'] = date(DATE_ATOM);
        $row['updated_by'] = store_actor();
        $found = true;
        break;
    }
    unset($row);
    if (!$found) {
        $rows[] = [
            'id' => $dept,
            'dept' => $dept,
            'actions' => [$action],
            'count' => 1,
            'updated_at' => date(DATE_ATOM),
            'updated_by' => store_actor(),
        ];
    }
    store_write('pending_changes', array_values($rows));
    return $rows;
}

function store_pending_count(?string $dept = null): int
{
    $count = 0;
    foreach (store_read('pending_changes') as $row) {
        if ($dept !== null && (string) ($row['dept'] ?? '*') !== $dept
            && (string) ($row['dept'] ?? '*') !== '*') {
            continue;
        }
        $count += max(0, (int) ($row['count'] ?? 0));
    }
    return $count;
}

function store_clear_pending(): void
{
    store_write('pending_changes', []);
}

function store_database_init(array $stores): void
{
    $marker = DATA_PATH . DIRECTORY_SEPARATOR . '.database-store-v2';
    if (is_file($marker)) {
        $pending = DATA_PATH . DIRECTORY_SEPARATOR . '.database-write-pending';
        if (is_file($pending)) {
            $name = trim((string) file_get_contents($pending));
            if ($name !== '') {
                store_database_sync($name);
            }
            @unlink($pending);
        }
        return;
    }
    $schema = <<<'SQL'
CREATE TABLE IF NOT EXISTS astera_config_store (
    store_name VARCHAR(64) PRIMARY KEY,
    data JSONB NOT NULL DEFAULT '[]'::jsonb,
    revision BIGINT NOT NULL DEFAULT 1,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT now(),
    updated_by VARCHAR(120) NOT NULL DEFAULT 'system'
);
CREATE TABLE IF NOT EXISTS astera_config_audit (
    id BIGSERIAL PRIMARY KEY,
    changed_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT now(),
    store_name VARCHAR(64) NOT NULL,
    action VARCHAR(10) NOT NULL,
    actor VARCHAR(120) NOT NULL DEFAULT 'system',
    old_revision BIGINT,
    new_revision BIGINT,
    old_data JSONB,
    new_data JSONB
);
CREATE INDEX IF NOT EXISTS astera_config_audit_store_time
    ON astera_config_audit (store_name, changed_at DESC);
CREATE TABLE IF NOT EXISTS astera_apply_history (
    apply_id VARCHAR(40) PRIMARY KEY,
    requested_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT now(),
    completed_at TIMESTAMP WITHOUT TIME ZONE,
    requested_by VARCHAR(120) NOT NULL DEFAULT 'system',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    message TEXT NOT NULL DEFAULT ''
);
CREATE OR REPLACE FUNCTION astera_redact_config(source JSONB)
RETURNS JSONB
LANGUAGE sql
IMMUTABLE
AS $$
    SELECT CASE
        WHEN source IS NULL THEN NULL
        WHEN jsonb_typeof(source) = 'array' THEN COALESCE(
            (SELECT jsonb_agg(item - 'password' - 'panel_pass' - 'vm_pin' - 'pin' - 'fcode_pass')
             FROM jsonb_array_elements(source) AS entries(item)),
            '[]'::jsonb
        )
        ELSE source - 'password' - 'panel_pass' - 'vm_pin' - 'pin' - 'fcode_pass'
    END;
$$;
CREATE OR REPLACE FUNCTION astera_config_audit_write()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
BEGIN
    INSERT INTO astera_config_audit
        (store_name, action, actor, old_revision, new_revision, old_data, new_data)
    VALUES
        (COALESCE(NEW.store_name, OLD.store_name), TG_OP,
         COALESCE(NEW.updated_by, OLD.updated_by, 'system'),
         OLD.revision, NEW.revision,
         astera_redact_config(OLD.data), astera_redact_config(NEW.data));
    RETURN COALESCE(NEW, OLD);
END;
$$;
UPDATE astera_config_audit
SET old_data = astera_redact_config(old_data),
    new_data = astera_redact_config(new_data);
DROP TRIGGER IF EXISTS astera_config_store_audit ON astera_config_store;
CREATE TRIGGER astera_config_store_audit
AFTER INSERT OR UPDATE OR DELETE ON astera_config_store
FOR EACH ROW EXECUTE FUNCTION astera_config_audit_write();
GRANT SELECT, INSERT, UPDATE, DELETE ON astera_config_store TO asterisk;
GRANT SELECT, INSERT ON astera_config_audit TO asterisk;
GRANT SELECT, INSERT, UPDATE ON astera_apply_history TO asterisk;
GRANT USAGE, SELECT ON SEQUENCE astera_config_audit_id_seq TO asterisk;
SQL;
    $created = pbx_pg_admin($schema);
    if (!$created['ok']) {
        throw new RuntimeException('Yapılandırma veritabanı hazırlanamadı: ' . ($created['output'] ?? ''));
    }

    $seedSql = [];
    foreach ($stores as $name) {
        store_validate_name($name);
        $rows = store_read($name);
        $json = json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('İlk veri aktarımı hazırlanamadı: ' . $name);
        }
        $seedSql[] = "INSERT INTO astera_config_store (store_name, data, updated_by) VALUES ("
            . pbx_sql_literal($name) . ', ' . pbx_sql_literal($json) . "::jsonb, 'migration') "
            . 'ON CONFLICT (store_name) DO NOTHING';
    }
    store_database_exec(implode(";\n", $seedSql), 'İlk yapılandırma aktarımı başarısız');
    store_database_sync();
    file_put_contents($marker, date(DATE_ATOM) . PHP_EOL, LOCK_EX);
}

function store_database_write(string $name, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Yapılandırma verisi hazırlanamadı: ' . $name);
    }
    $pending = DATA_PATH . DIRECTORY_SEPARATOR . '.database-write-pending';
    file_put_contents($pending, $name . PHP_EOL, LOCK_EX);
    $sql = "INSERT INTO astera_config_store (store_name, data, revision, updated_at, updated_by) VALUES ("
        . pbx_sql_literal($name) . ', ' . pbx_sql_literal($json) . '::jsonb, 1, now(), '
        . pbx_sql_literal(store_actor()) . ') '
        . 'ON CONFLICT (store_name) DO UPDATE SET data = EXCLUDED.data, '
        . 'revision = astera_config_store.revision + 1, updated_at = now(), '
        . 'updated_by = EXCLUDED.updated_by';
    try {
        store_database_exec($sql, 'Veritabanına yazılamadı: ' . $name);
    } catch (Throwable $e) {
        @unlink($pending);
        throw $e;
    }
}

function store_database_exec(string $sql, string $error): void
{
    $result = pbx_odbc_sql($sql);
    if (!$result['ok'] || str_contains((string) ($result['output'] ?? ''), '[ISQL]ERROR')) {
        throw new RuntimeException($error . ' — ' . trim((string) ($result['output'] ?? '')));
    }
}

function store_database_sync(?string $only = null): void
{
    $where = '';
    if ($only !== null) {
        store_validate_name($only);
        $where = ' WHERE store_name = ' . pbx_sql_literal($only);
    }
    $ok = false;
    $rows = pbx_odbc_rows(
        "SELECT store_name, encode(convert_to(data::text, 'UTF8'), 'hex') AS data_hex "
        . "FROM astera_config_store{$where} ORDER BY store_name",
        $ok
    );
    if (!$ok) {
        throw new RuntimeException('Yapılandırma veritabanından okunamadı');
    }
    foreach ($rows as $row) {
        $name = (string) ($row['store_name'] ?? '');
        $binary = hex2bin((string) ($row['data_hex'] ?? ''));
        $data = $binary === false ? null : json_decode($binary, true);
        if ($name !== '' && is_array($data)) {
            store_local_write($name, $data);
        }
    }
}

function store_apply_begin(): string
{
    $id = bin2hex(random_bytes(16));
    $sql = 'INSERT INTO astera_apply_history (apply_id, requested_by) VALUES ('
        . pbx_sql_literal($id) . ', ' . pbx_sql_literal(store_actor()) . ')';
    store_database_exec($sql, 'Santrale uygulama işlemi veritabanına kaydedilemedi');
    return $id;
}

function store_apply_finish(string $id, bool $ok, string $message): void
{
    $message = mb_substr($message, 0, 10000, 'UTF-8');
    $encoded = base64_encode($message);
    $sql = 'UPDATE astera_apply_history SET completed_at = now(), status = '
        . pbx_sql_literal($ok ? 'success' : 'failed')
        . ", message = convert_from(decode(" . pbx_sql_literal($encoded) . ", 'base64'), 'UTF8')"
        . ' WHERE apply_id = ' . pbx_sql_literal($id);
    store_database_exec($sql, 'Santrale uygulama sonucu veritabanına kaydedilemedi');
}
