<?php
declare(strict_types=1);

function departments(): array
{
    return store_read('departments');
}

function dept_by_id(string $id): ?array
{
    return find_by('departments', 'id', $id);
}

function is_super(): bool
{
    return empty($_SESSION['user_dept']);
}

function current_dept_id(): ?string
{
    if (!empty($_SESSION['user_dept'])) {
        return (string) $_SESSION['user_dept'];
    }
    $sel = (string) ($_SESSION['dept'] ?? '');
    if ($sel === '' || $sel === '*') {
        return null;
    }
    return $sel;
}

function current_dept_row(): ?array
{
    $id = current_dept_id();
    return $id ? dept_by_id($id) : null;
}

function department_extension_count(string $deptId): int
{
    return count(array_filter(
        store_read('extensions'),
        static fn($extension) => (string) ($extension['dept'] ?? '') === $deptId
    ));
}

function department_extension_limit(string $deptId): int
{
    $department = dept_by_id($deptId);
    return max(0, (int) ($department['extension_limit'] ?? 0));
}

function assert_extension_capacity(string $deptId, int $additional = 1): void
{
    $limit = department_extension_limit($deptId);
    if ($limit <= 0 || $additional <= 0) {
        return;
    }
    $current = department_extension_count($deptId);
    if ($current + $additional > $limit) {
        json_out([
            'ok' => false,
            'error' => "Lisansınız yetersiz. Abone limiti: {$limit}; mevcut: {$current}; eklenmek istenen: {$additional}.",
        ], 422);
    }
}

function dept_code_of(string $id): string
{
    $row = dept_by_id($id);
    $code = ast_sanitize_id((string) ($row['code'] ?? $row['id'] ?? $id));
    return $code !== '' ? $code : $id;
}

function ctx(string $deptId, string $kind = 'from'): string
{
    $c = dept_code_of($deptId);
    return match ($kind) {
        'int' => 'int-' . $c,
        'out' => 'out-' . $c,
        'in' => 'in-' . $c,
        'feat' => 'feat-' . $c,
        'sub' => 'sub-' . $c,
        default => 'from-' . $c,
    };
}

function scoped(string $name): array
{
    $rows = store_read($name);
    $dept = current_dept_id();
    if ($dept === null) {
        return $rows;
    }
    return array_values(array_filter($rows, static fn($row) => (string) ($row['dept'] ?? '') === $dept));
}

function dept_name(string $id): string
{
    $row = dept_by_id($id);
    return (string) ($row['name'] ?? $id);
}

function sip_user(array $ext): string
{
    return ast_sanitize_id((string) ($ext['sipuser'] ?? $ext['exten'] ?? ''));
}

function auth_user(array $ext): string
{
    $auth = ast_sanitize_id((string) ($ext['authuser'] ?? ''));
    return $auth !== '' ? $auth : sip_user($ext);
}

function ext_id(string $dept, string $exten): string
{
    return $dept . '-' . $exten;
}

function find_ext(string $dept, string $exten): ?array
{
    foreach (store_read('extensions') as $row) {
        if ((string) ($row['dept'] ?? '') === $dept && (string) ($row['exten'] ?? '') === $exten) {
            return $row;
        }
    }
    return null;
}

function find_sip_user(string $sip): ?array
{
    foreach (store_read('extensions') as $row) {
        if (sip_user($row) === $sip) {
            return $row;
        }
    }
    return null;
}

function pick_sip_user(string $dept, string $exten, ?string $preferred = null): string
{
    $want = ast_sanitize_id($preferred ?: $exten);
    $existing = find_sip_user($want);
    if ($existing === null || ((string) ($existing['dept'] ?? '') === $dept && (string) ($existing['exten'] ?? '') === $exten)) {
        return $want;
    }
    return ast_sanitize_id(dept_code_of($dept) . '_' . $exten);
}

function require_dept_id(?string $posted = null): string
{
    $id = ast_sanitize_id($posted ?: (string) ($_POST['dept'] ?? current_dept_id() ?? ''));
    if ($id === '' || $id === '*') {
        json_out(['ok' => false, 'error' => 'Önce bir firma / departman seçin']);
    }
    if (!is_super() && $id !== (string) ($_SESSION['user_dept'] ?? '')) {
        json_out(['ok' => false, 'error' => 'Bu firmaya yetkiniz yok']);
    }
    if (!dept_by_id($id)) {
        json_out(['ok' => false, 'error' => 'Firma bulunamadı']);
    }
    return $id;
}

function assert_row_scope(array $row): void
{
    if (!is_super() && (string) ($row['dept'] ?? '') !== (string) ($_SESSION['user_dept'] ?? '')) {
        json_out(['ok' => false, 'error' => 'Yetkisiz işlem']);
    }
}

function can_page(string $page): bool
{
    if (is_super()) {
        return true;
    }
    return !in_array($page, ['departments', 'contexts', 'system', 'logs', 'security', 'ssl'], true);
}

function migrate_tenants(): void
{
    if (!store_read('departments')) {
        store_write('departments', [[
            'id' => 'genel',
            'code' => 'genel',
            'name' => 'Varsayılan firma',
            'record' => true,
            'cid' => '',
            'panel_user' => '',
            'panel_pass' => '',
        ]]);
    }

    $changed = false;
    $exts = store_read('extensions');
    foreach ($exts as $i => $row) {
        if (empty($row['dept'])) {
            $exts[$i]['dept'] = 'genel';
            $changed = true;
        }
        if (empty($row['id'])) {
            $exts[$i]['id'] = ext_id((string) $exts[$i]['dept'], (string) ($row['exten'] ?? ''));
            $changed = true;
        }
        if (empty($row['sipuser'])) {
            $exts[$i]['sipuser'] = (string) ($row['exten'] ?? '');
            $changed = true;
        }
        if (empty($exts[$i]['authuser'])) {
            $exts[$i]['authuser'] = (string) ($exts[$i]['sipuser'] ?? $row['exten'] ?? '');
            $changed = true;
        }
    }
    if ($changed) {
        store_write('extensions', $exts);
    }

    foreach (tenant_store_names() as $name) {
        $rows = store_read($name);
        $dirty = false;
        foreach ($rows as $i => $row) {
            if (empty($row['dept'])) {
                $rows[$i]['dept'] = 'genel';
                $dirty = true;
            }
        }
        if ($dirty) {
            store_write($name, $rows);
        }
    }
}

function dept_group_no(string $deptId): int
{
    return (abs(crc32($deptId)) % 63) + 1;
}

function ensure_default_outbound(string $dept, string $trunkId): void
{
    $dept = ast_sanitize_id($dept);
    $trunkId = ast_sanitize_id($trunkId);
    if ($dept === '' || $trunkId === '') {
        return;
    }
    foreach (store_read('outbound') as $row) {
        if ((string) ($row['dept'] ?? '') === $dept) {
            return;
        }
    }
    $defaults = [
        ['name' => 'Türkiye 0…', 'pattern' => '_0X.', 'strip' => 1, 'prefix' => '90'],
        ['name' => '90 ile', 'pattern' => '_90X.', 'strip' => 0, 'prefix' => ''],
        ['name' => 'Uluslararası 00', 'pattern' => '_00X.', 'strip' => 2, 'prefix' => ''],
    ];
    foreach ($defaults as $i => $d) {
        store_upsert('outbound', [
            'id' => $dept . 'out' . ($i + 1),
            'dept' => $dept,
            'name' => $d['name'],
            'pattern' => $d['pattern'],
            'strip' => $d['strip'],
            'prefix' => $d['prefix'],
            'trunk' => $trunkId,
            'pin' => '',
        ], 'id');
    }
}

function pstn_digits(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if ($d === '') {
        return '';
    }
    if (str_starts_with($d, '00')) {
        $d = substr($d, 2);
    }
    if (str_starts_with($d, '0')) {
        $d = '90' . substr($d, 1);
    }
    return $d;
}

function dest_local_exten(string $dept, string $type, string $dest): string
{
    if ($type === 'queue') {
        foreach (store_read('queues') as $q) {
            if ((string) ($q['dept'] ?? '') === $dept && (string) ($q['id'] ?? '') === $dest) {
                return (string) ($q['exten'] ?? '');
            }
        }
    }
    if ($type === 'ringgroup') {
        foreach (store_read('ringgroups') as $g) {
            if ((string) ($g['dept'] ?? '') === $dept && (string) ($g['id'] ?? '') === $dest) {
                return (string) ($g['exten'] ?? '');
            }
        }
    }
    if ($type === 'conference') {
        foreach (store_read('conferences') as $c) {
            if ((string) ($c['dept'] ?? '') === $dept && (string) ($c['id'] ?? '') === $dest) {
                return (string) ($c['exten'] ?? '');
            }
        }
    }
    if ($type === 'paging') {
        foreach (store_read('pagings') as $p) {
            if ((string) ($p['dept'] ?? '') === $dept && (string) ($p['id'] ?? '') === $dest) {
                return (string) ($p['exten'] ?? '');
            }
        }
    }
    if ($type === 'disa') {
        foreach (store_read('disas') as $d) {
            if ((string) ($d['dept'] ?? '') === $dept && (string) ($d['id'] ?? '') === $dest) {
                return (string) ($d['exten'] ?? '');
            }
        }
    }
    return $dest;
}

function member_sip(string $dept, string $exten): string
{
    $row = find_ext($dept, $exten);
    return $row ? sip_user($row) : ast_sanitize_id($exten);
}
