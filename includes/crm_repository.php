<?php
declare(strict_types=1);

function crm_db_json_rows(string $sql): array
{
    $ok = false;
    $rows = pbx_odbc_rows(
        "WITH encoded_rows AS ("
        . "SELECT row_number() OVER () AS row_no, "
        . "encode(convert_to(row_to_json(q)::text, 'UTF8'), 'hex') AS payload "
        . "FROM ({$sql}) q"
        . ") SELECT row_no, chunk_no, "
        . "substring(payload FROM ((chunk_no - 1) * 120 + 1) FOR 120)::varchar(120) AS row_hex "
        . "FROM encoded_rows CROSS JOIN LATERAL generate_series("
        . "1, GREATEST(1, ceil(length(payload) / 120.0)::integer)"
        . ") AS parts(chunk_no) ORDER BY row_no, chunk_no",
        $ok
    );
    if (!$ok) {
        throw new RuntimeException('CRM veritabanı sorgusu başarısız');
    }
    $encoded = [];
    foreach ($rows as $row) {
        $rowNo = (int) ($row['row_no'] ?? 0);
        $chunkNo = (int) ($row['chunk_no'] ?? 0);
        $chunk = trim((string) ($row['row_hex'] ?? ''));
        if ($rowNo < 1 || $chunkNo < 1 || $chunk === '') {
            continue;
        }
        $encoded[$rowNo][$chunkNo] = $chunk;
    }
    ksort($encoded, SORT_NUMERIC);
    $decoded = [];
    foreach ($encoded as $chunks) {
        ksort($chunks, SORT_NUMERIC);
        $hex = implode('', $chunks);
        $json = strlen($hex) % 2 === 0 ? hex2bin($hex) : false;
        $item = $json === false ? null : json_decode($json, true);
        if (is_array($item)) {
            $decoded[] = $item;
        }
    }
    return $decoded;
}

function crm_db_exec(string $sql, string $error): void
{
    $result = pbx_odbc_sql($sql);
    if (!$result['ok'] || str_contains((string) ($result['output'] ?? ''), '[ISQL]ERROR')) {
        throw new RuntimeException($error . ' — ' . trim((string) ($result['output'] ?? '')));
    }
}

function crm_sql_timestamp(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp === false
        ? 'now()'
        : pbx_sql_literal(date(DATE_ATOM, $timestamp)) . '::timestamptz';
}

function crm_ensure_tenant(string $dept): void
{
    $department = dept_by_id($dept);
    if (!$department) {
        throw new RuntimeException('CRM firması bulunamadı: ' . $dept);
    }
    $code = dept_code_of($dept);
    $name = trim((string) ($department['name'] ?? $dept)) ?: $dept;
    crm_db_exec(
        'INSERT INTO astera_tenants (dept_id, code, name, active, updated_at) VALUES ('
        . pbx_sql_literal($dept) . ', ' . pbx_sql_literal($code) . ', '
        . pbx_sql_literal($name) . ', TRUE, now()) '
        . 'ON CONFLICT (dept_id) DO UPDATE SET code = EXCLUDED.code, '
        . 'name = EXCLUDED.name, active = TRUE, updated_at = now()',
        'CRM firma kaydı hazırlanamadı'
    );
}

function crm_tenant_delete_db(string $dept): void
{
    if ($dept === '') {
        return;
    }
    crm_db_exec(
        'DELETE FROM astera_tenants WHERE dept_id = ' . pbx_sql_literal($dept),
        'CRM firma kaydı silinemedi'
    );
}

function crm_customer_select_sql(
    ?string $dept = null,
    string $query = '',
    int $limit = 1000,
    string $id = ''
): string
{
    $limit = max(1, min(5000, $limit));
    $clauses = [];
    if ($dept !== null) {
        $clauses[] = 'c.dept_id = ' . pbx_sql_literal($dept);
    }
    if ($id !== '') {
        $clauses[] = 'c.id = ' . pbx_sql_literal($id);
    }
    $query = trim($query);
    if ($query !== '') {
        $needle = '%' . mb_strtolower($query, 'UTF-8') . '%';
        $digits = crm_normalize_phone($query);
        $search = [
            'c.search_text ILIKE ' . pbx_sql_literal($needle),
            'EXISTS (SELECT 1 FROM crm_call_notes n WHERE n.dept_id = c.dept_id '
                . 'AND n.customer_id = c.id AND n.note ILIKE ' . pbx_sql_literal($needle) . ')',
        ];
        if ($digits !== '') {
            $search[] = 'EXISTS (SELECT 1 FROM crm_customer_phones sp WHERE sp.dept_id = c.dept_id '
                . 'AND sp.customer_id = c.id AND sp.normalized_value LIKE '
                . pbx_sql_literal('%' . $digits . '%') . ')';
        }
        $clauses[] = '(' . implode(' OR ', $search) . ')';
    }
    $where = $clauses ? ' WHERE ' . implode(' AND ', $clauses) : '';
    return "SELECT c.id, c.dept_id AS dept, c.company, c.contact, c.email, c.address, "
        . "c.notes, c.created_at::text, c.updated_at::text, c.updated_by, "
        . "COALESCE(p.phone, '') AS phone, COALESCE(p.phone_alt, '') AS phone_alt "
        . "FROM crm_customers c "
        . "LEFT JOIN LATERAL ("
        . "SELECT MAX(display_value) FILTER (WHERE phone_type = 'primary') AS phone, "
        . "MAX(display_value) FILTER (WHERE phone_type = 'alternate') AS phone_alt "
        . "FROM crm_customer_phones cp WHERE cp.dept_id = c.dept_id AND cp.customer_id = c.id"
        . ") p ON TRUE{$where} ORDER BY c.company, c.id LIMIT {$limit}";
}

function crm_customers_db(?string $dept = null, string $query = '', int $limit = 1000): array
{
    return crm_db_json_rows(crm_customer_select_sql($dept, $query, $limit));
}

function crm_customer_find_db(string $id): ?array
{
    if ($id === '') {
        return null;
    }
    $rows = crm_db_json_rows(crm_customer_select_sql(null, '', 1, $id));
    return $rows[0] ?? null;
}

function crm_customer_by_phone_db(string $dept, string $phone): ?array
{
    $normalized = crm_normalize_phone($phone);
    if ($normalized === '') {
        return null;
    }
    $ids = crm_db_json_rows(
        'SELECT customer_id FROM crm_customer_phones WHERE dept_id = '
        . pbx_sql_literal($dept) . ' AND normalized_value = '
        . pbx_sql_literal($normalized) . ' LIMIT 1'
    );
    $id = (string) ($ids[0]['customer_id'] ?? '');
    return $id !== '' ? crm_customer_find_db($id) : null;
}

function crm_customer_save_db(array $customer): void
{
    $id = (string) ($customer['id'] ?? '');
    $dept = (string) ($customer['dept'] ?? '');
    if ($id === '' || $dept === '') {
        throw new InvalidArgumentException('CRM müşteri kimliği ve firma zorunlu');
    }
    crm_ensure_tenant($dept);
    $createdAt = crm_sql_timestamp((string) ($customer['created_at'] ?? ''));
    $updatedAt = crm_sql_timestamp((string) ($customer['updated_at'] ?? ''));
    $sql = [
        'BEGIN',
        'INSERT INTO crm_customers '
            . '(id, dept_id, company, contact, email, address, notes, created_at, updated_at, updated_by) VALUES ('
            . pbx_sql_literal($id) . ', ' . pbx_sql_literal($dept) . ', '
            . pbx_sql_literal((string) ($customer['company'] ?? '')) . ', '
            . pbx_sql_literal((string) ($customer['contact'] ?? '')) . ', '
            . pbx_sql_literal((string) ($customer['email'] ?? '')) . ', '
            . pbx_sql_literal((string) ($customer['address'] ?? '')) . ', '
            . pbx_sql_literal((string) ($customer['notes'] ?? '')) . ', '
            . "{$createdAt}, {$updatedAt}, "
            . pbx_sql_literal((string) ($customer['updated_by'] ?? store_actor())) . ') '
            . 'ON CONFLICT (id) DO UPDATE SET company = EXCLUDED.company, '
            . 'contact = EXCLUDED.contact, email = EXCLUDED.email, address = EXCLUDED.address, '
            . 'notes = EXCLUDED.notes, updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by',
        'DELETE FROM crm_customer_phones WHERE customer_id = ' . pbx_sql_literal($id)
            . ' AND dept_id = ' . pbx_sql_literal($dept),
    ];
    foreach (['primary' => 'phone', 'alternate' => 'phone_alt'] as $type => $field) {
        $display = trim((string) ($customer[$field] ?? ''));
        $normalized = crm_normalize_phone($display);
        if ($display === '' || $normalized === '') {
            continue;
        }
        $sql[] = 'INSERT INTO crm_customer_phones '
            . '(dept_id, customer_id, phone_type, display_value, normalized_value, updated_at) VALUES ('
            . pbx_sql_literal($dept) . ', ' . pbx_sql_literal($id) . ', '
            . pbx_sql_literal($type) . ', ' . pbx_sql_literal($display) . ', '
            . pbx_sql_literal($normalized) . ', now())';
    }
    $sql[] = 'COMMIT';
    crm_db_exec(implode(";\n", $sql), 'Müşteri kartı veritabanına kaydedilemedi');
}

function crm_customer_delete_db(string $id, string $dept): void
{
    crm_db_exec(
        'DELETE FROM crm_customers WHERE id = ' . pbx_sql_literal($id)
        . ' AND dept_id = ' . pbx_sql_literal($dept),
        'Müşteri kartı silinemedi'
    );
}

function crm_call_notes_db(?string $dept = null, ?string $customerId = null, string $query = ''): array
{
    $clauses = [];
    if ($dept !== null) {
        $clauses[] = 'dept_id = ' . pbx_sql_literal($dept);
    }
    if ($customerId !== null) {
        $clauses[] = 'customer_id = ' . pbx_sql_literal($customerId);
    }
    if (trim($query) !== '') {
        $clauses[] = 'note ILIKE ' . pbx_sql_literal('%' . mb_strtolower(trim($query), 'UTF-8') . '%');
    }
    $where = $clauses ? ' WHERE ' . implode(' AND ', $clauses) : '';
    return crm_db_json_rows(
        "SELECT id, dept_id AS dept, customer_id, call_key, note, "
        . "created_at::text, updated_at::text, updated_by "
        . "FROM crm_call_notes{$where} ORDER BY updated_at DESC, id LIMIT 5000"
    );
}

function crm_call_note_save_db(array $note): void
{
    $id = (string) ($note['id'] ?? '');
    $dept = (string) ($note['dept'] ?? '');
    $customerId = (string) ($note['customer_id'] ?? '');
    if ($id === '' || $dept === '' || $customerId === '') {
        throw new InvalidArgumentException('CRM görüşme notu kimlikleri eksik');
    }
    crm_ensure_tenant($dept);
    crm_db_exec(
        'INSERT INTO crm_call_notes '
        . '(id, dept_id, customer_id, call_key, note, created_at, updated_at, updated_by) VALUES ('
        . pbx_sql_literal($id) . ', ' . pbx_sql_literal($dept) . ', '
        . pbx_sql_literal($customerId) . ', '
        . pbx_sql_literal((string) ($note['call_key'] ?? '')) . ', '
        . pbx_sql_literal((string) ($note['note'] ?? '')) . ', '
        . crm_sql_timestamp((string) ($note['created_at'] ?? '')) . ', '
        . crm_sql_timestamp((string) ($note['updated_at'] ?? '')) . ', '
        . pbx_sql_literal((string) ($note['updated_by'] ?? store_actor())) . ') '
        . 'ON CONFLICT (id) DO UPDATE SET note = EXCLUDED.note, '
        . 'updated_at = EXCLUDED.updated_at, updated_by = EXCLUDED.updated_by',
        'Görüşme notu veritabanına kaydedilemedi'
    );
}

function crm_call_note_delete_db(string $id, string $dept): void
{
    crm_db_exec(
        'DELETE FROM crm_call_notes WHERE id = ' . pbx_sql_literal($id)
        . ' AND dept_id = ' . pbx_sql_literal($dept),
        'Görüşme notu silinemedi'
    );
}

function crm_import_legacy_stores(): array
{
    $counts = ['customers' => 0, 'notes' => 0];
    foreach (departments() as $department) {
        $dept = (string) ($department['id'] ?? '');
        if ($dept !== '') {
            crm_ensure_tenant($dept);
        }
    }
    foreach (store_read('crm_customers') as $customer) {
        crm_customer_save_db($customer);
        $counts['customers']++;
    }
    foreach (store_read('crm_call_notes') as $note) {
        if (crm_customer_find_db((string) ($note['customer_id'] ?? ''))) {
            $note['created_at'] ??= $note['updated_at'] ?? date(DATE_ATOM);
            crm_call_note_save_db($note);
            $counts['notes']++;
        }
    }
    return $counts;
}
