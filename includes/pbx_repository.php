<?php
declare(strict_types=1);

function pbx_relational_stores(): array
{
    return [
        'extensions', 'trunks', 'outbound', 'inbound', 'queues', 'ringgroups', 'ivrs',
        'timeconditions', 'announcements', 'conferences', 'blacklist', 'parking',
        'flows', 'disas', 'pagings', 'speeddials', 'customs', 'sounds', 'media',
        'url_triggers',
    ];
}

function pbx_relational_enabled(): bool
{
    return is_file(DATA_PATH . DIRECTORY_SEPARATOR . '.relational-store-v1');
}

function pbx_relational_supports(string $name): bool
{
    return $name === 'departments' || in_array($name, pbx_relational_stores(), true);
}

/**
 * ODBC's default display width truncates long text. Encoding each JSON row in
 * short chunks keeps repository reads lossless without requiring a PDO driver.
 */
function pbx_relational_json_rows(string $sql): array
{
    $ok = false;
    $rows = pbx_odbc_rows(
        "WITH encoded_rows AS (SELECT row_number() OVER () AS row_no, "
        . "encode(convert_to(row_to_json(q)::text, 'UTF8'), 'hex') AS payload FROM ({$sql}) q) "
        . "SELECT row_no, chunk_no, "
        . "substring(payload FROM ((chunk_no - 1) * 120 + 1) FOR 120)::varchar(120) AS row_hex "
        . "FROM encoded_rows CROSS JOIN LATERAL generate_series("
        . "1, GREATEST(1, ceil(length(payload) / 120.0)::integer)"
        . ") AS parts(chunk_no) ORDER BY row_no, chunk_no",
        $ok
    );
    if (!$ok) {
        throw new RuntimeException('PBX yapılandırması veritabanından okunamadı');
    }
    $encoded = [];
    foreach ($rows as $row) {
        $rowNo = (int) ($row['row_no'] ?? 0);
        $chunkNo = (int) ($row['chunk_no'] ?? 0);
        if ($rowNo > 0 && $chunkNo > 0) {
            $encoded[$rowNo][$chunkNo] = trim((string) ($row['row_hex'] ?? ''));
        }
    }
    $result = [];
    ksort($encoded, SORT_NUMERIC);
    foreach ($encoded as $chunks) {
        ksort($chunks, SORT_NUMERIC);
        if (array_keys($chunks) !== range(1, count($chunks))) {
            throw new RuntimeException('PBX veritabanı yanıtında eksik veri parçası var');
        }
        $binary = hex2bin(implode('', $chunks));
        $decoded = $binary === false ? null : json_decode($binary, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('PBX veritabanı yanıtı çözümlenemedi');
        }
        $result[] = $decoded;
    }
    return $result;
}

function pbx_relational_read(string $name): array
{
    if ($name === 'departments') {
        return pbx_relational_json_rows(
            "SELECT dept_id AS id, code, name, record_enabled AS record, "
            . "outbound_cid AS cid, panel_user, panel_password AS panel_pass, extension_limit "
            . "FROM astera_tenants ORDER BY config_position, dept_id"
        );
    }
    if (!in_array($name, pbx_relational_stores(), true)) {
        throw new InvalidArgumentException('İlişkisel olmayan yapılandırma bölümü: ' . $name);
    }

    $entities = pbx_relational_json_rows(
        "SELECT entity_id, dept_id, external_id, parent_entity_id, relation_name, "
        . "map_key, position FROM pbx_config_entities WHERE store_name = "
        . pbx_sql_literal($name)
        . " ORDER BY (parent_entity_id IS NOT NULL), position, map_key, external_id"
    );
    if (!$entities) {
        return [];
    }
    $values = pbx_relational_json_rows(
        "SELECT v.entity_id, v.field_name, v.position, v.map_key, v.value_type, v.text_value, "
        . "v.integer_value, v.number_value, v.boolean_value "
        . "FROM pbx_config_values v JOIN pbx_config_entities e ON e.entity_id = v.entity_id "
        . "WHERE e.store_name = " . pbx_sql_literal($name)
        . " ORDER BY v.entity_id, v.field_name, v.position NULLS FIRST"
    );

    $byId = [];
    $children = [];
    $roots = [];
    foreach ($entities as $entity) {
        $id = (string) ($entity['entity_id'] ?? '');
        if ($id === '') {
            continue;
        }
        $byId[$id] = $entity;
        $parent = (string) ($entity['parent_entity_id'] ?? '');
        if ($parent === '') {
            $roots[] = $id;
        } else {
            $children[$parent][] = $id;
        }
    }
    $valuesByEntity = [];
    foreach ($values as $value) {
        $valuesByEntity[(string) ($value['entity_id'] ?? '')][] = $value;
    }
    $rows = [];
    foreach ($roots as $rootId) {
        $row = pbx_relational_build_entity($rootId, $byId, $children, $valuesByEntity);
        $row = [
            'id' => (string) ($byId[$rootId]['external_id'] ?? ''),
            'dept' => (string) ($byId[$rootId]['dept_id'] ?? ''),
        ] + $row;
        $rows[] = $row;
    }
    return $rows;
}

function pbx_relational_build_entity(
    string $entityId,
    array $entities,
    array $children,
    array $values
): array {
    $result = [];
    foreach ($values[$entityId] ?? [] as $value) {
        $field = (string) ($value['field_name'] ?? '');
        if ($field === '') {
            continue;
        }
        $type = (string) ($value['value_type'] ?? 'null');
        $decoded = match ($type) {
            'string' => (string) ($value['text_value'] ?? ''),
            'integer' => (int) ($value['integer_value'] ?? 0),
            'number' => (float) ($value['number_value'] ?? 0),
            'boolean' => in_array(strtolower((string) ($value['boolean_value'] ?? '')), ['1', 't', 'true'], true),
            'container' => [],
            default => null,
        };
        if ($value['map_key'] !== null) {
            $result[$field][(string) $value['map_key']] = $decoded;
        } elseif ($value['position'] !== null) {
            $result[$field][(int) $value['position']] = $decoded;
        } else {
            $result[$field] = $decoded;
        }
    }
    foreach ($children[$entityId] ?? [] as $childId) {
        $child = $entities[$childId];
        $relation = (string) ($child['relation_name'] ?? '');
        $data = pbx_relational_build_entity($childId, $entities, $children, $values);
        if ($child['map_key'] !== null) {
            $result[$relation][(string) $child['map_key']] = $data;
        } else {
            $result[$relation][(int) ($child['position'] ?? 0)] = $data;
        }
    }
    foreach ($result as &$value) {
        if (is_array($value) && array_is_list($value)) {
            $value = array_values($value);
        }
    }
    unset($value);
    return $result;
}

function pbx_relational_write(string $name, array $rows): void
{
    if ($name === 'departments') {
        pbx_relational_write_departments($rows);
        return;
    }
    if (!in_array($name, pbx_relational_stores(), true)) {
        throw new InvalidArgumentException('İlişkisel olmayan yapılandırma bölümü: ' . $name);
    }
    $sql = ['BEGIN', 'DELETE FROM pbx_config_entities WHERE store_name = ' . pbx_sql_literal($name)];
    foreach (array_values($rows) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $dept = (string) ($row['dept'] ?? 'genel');
        $externalId = (string) ($row['id'] ?? '');
        if ($externalId === '' && $name === 'extensions') {
            $externalId = $dept . '-' . (string) ($row['exten'] ?? $index);
        }
        if ($dept === '' || $externalId === '') {
            throw new RuntimeException("{$name} kaydında firma veya kimlik eksik");
        }
        pbx_relational_append_entity_sql(
            $sql,
            $name,
            $dept,
            $externalId,
            null,
            null,
            null,
            $index,
            array_diff_key($row, ['id' => true, 'dept' => true])
        );
    }
    $sql[] = 'COMMIT';
    store_database_exec(implode(";\n", $sql), 'İlişkisel yapılandırma yazılamadı: ' . $name);
}

function pbx_relational_append_entity_sql(
    array &$sql,
    string $store,
    string $dept,
    ?string $externalId,
    ?string $parentId,
    ?string $relation,
    ?string $mapKey,
    ?int $position,
    array $data
): string {
    if ($externalId !== null && strlen($externalId) > 128) {
        throw new RuntimeException('PBX kayıt kimliği 128 karakteri aşamaz');
    }
    foreach ([$relation, $mapKey] as $key) {
        if ($key !== null && strlen($key) > 64) {
            throw new RuntimeException('PBX yapılandırma anahtarı 64 karakteri aşamaz');
        }
    }
    $entityId = bin2hex(random_bytes(16));
    $sql[] = 'INSERT INTO pbx_config_entities '
        . '(entity_id, dept_id, store_name, external_id, parent_entity_id, relation_name, map_key, position, updated_by) VALUES ('
        . pbx_sql_literal($entityId) . ', ' . pbx_sql_literal($dept) . ', '
        . pbx_sql_literal($store) . ', '
        . ($externalId === null ? 'NULL' : pbx_sql_literal($externalId)) . ', '
        . ($parentId === null ? 'NULL' : pbx_sql_literal($parentId)) . ', '
        . ($relation === null ? 'NULL' : pbx_sql_literal($relation)) . ', '
        . ($mapKey === null ? 'NULL' : pbx_sql_literal($mapKey)) . ', '
        . ($position === null ? 'NULL' : (string) $position) . ', ' . pbx_sql_literal(store_actor()) . ')';

    foreach ($data as $field => $value) {
        $field = (string) $field;
        if ($field === '' || strlen($field) > 64) {
            throw new RuntimeException('PBX alan adı boş olamaz ve 64 karakteri aşamaz');
        }
        if (is_array($value)) {
            $sql[] = pbx_relational_value_sql($dept, $entityId, $field, null, null, 'container', null);
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    pbx_relational_append_entity_sql(
                        $sql,
                        $store,
                        $dept,
                        null,
                        $entityId,
                        $field,
                        is_string($key) ? $key : null,
                        is_int($key) ? $key : null,
                        $item
                    );
                } else {
                    $sql[] = pbx_relational_value_sql(
                        $dept,
                        $entityId,
                        $field,
                        is_int($key) ? $key : null,
                        is_string($key) ? $key : null,
                        pbx_relational_value_type($item),
                        $item
                    );
                }
            }
            continue;
        }
        $sql[] = pbx_relational_value_sql(
            $dept,
            $entityId,
            $field,
            null,
            null,
            pbx_relational_value_type($value),
            $value
        );
    }
    return $entityId;
}

function pbx_relational_value_type(mixed $value): string
{
    return match (true) {
        $value === null => 'null',
        is_bool($value) => 'boolean',
        is_int($value) => 'integer',
        is_float($value) => 'number',
        default => 'string',
    };
}

function pbx_relational_value_sql(
    string $dept,
    string $entityId,
    string $field,
    ?int $position,
    ?string $mapKey,
    string $type,
    mixed $value
): string {
    $text = $type === 'string' ? pbx_sql_literal((string) $value) : 'NULL';
    $integer = $type === 'integer' ? (string) (int) $value : 'NULL';
    $number = $type === 'number' ? (string) (float) $value : 'NULL';
    $boolean = $type === 'boolean' ? ($value ? 'TRUE' : 'FALSE') : 'NULL';
    return 'INSERT INTO pbx_config_values '
        . '(dept_id, entity_id, field_name, position, map_key, value_type, text_value, integer_value, number_value, boolean_value) VALUES ('
        . pbx_sql_literal($dept) . ', ' . pbx_sql_literal($entityId) . ', '
        . pbx_sql_literal($field) . ', ' . ($position === null ? 'NULL' : (string) $position) . ', '
        . ($mapKey === null ? 'NULL' : pbx_sql_literal($mapKey)) . ', '
        . pbx_sql_literal($type) . ", {$text}, {$integer}, {$number}, {$boolean})";
}

function pbx_relational_write_departments(array $rows): void
{
    $sql = ['BEGIN'];
    foreach (array_values($rows) as $position => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $sql[] = 'INSERT INTO astera_tenants '
            . '(dept_id, code, name, active, record_enabled, outbound_cid, panel_user, panel_password, config_position, extension_limit, updated_at) VALUES ('
            . pbx_sql_literal($id) . ', ' . pbx_sql_literal((string) ($row['code'] ?? $id)) . ', '
            . pbx_sql_literal((string) ($row['name'] ?? $id)) . ', TRUE, '
            . (!empty($row['record']) ? 'TRUE' : 'FALSE') . ', '
            . pbx_sql_literal((string) ($row['cid'] ?? '')) . ', '
            . pbx_sql_literal((string) ($row['panel_user'] ?? '')) . ', '
            . pbx_sql_literal((string) ($row['panel_pass'] ?? '')) . ', '
            . $position . ', ' . max(0, (int) ($row['extension_limit'] ?? 0)) . ', now()) '
            . 'ON CONFLICT (dept_id) DO UPDATE SET code = EXCLUDED.code, name = EXCLUDED.name, '
            . 'active = TRUE, record_enabled = EXCLUDED.record_enabled, outbound_cid = EXCLUDED.outbound_cid, '
            . 'panel_user = EXCLUDED.panel_user, panel_password = EXCLUDED.panel_password, '
            . 'config_position = EXCLUDED.config_position, extension_limit = EXCLUDED.extension_limit, updated_at = now()';
    }
    $sql[] = 'COMMIT';
    store_database_exec(implode(";\n", $sql), 'Firma kayıtları veritabanına yazılamadı');
}
