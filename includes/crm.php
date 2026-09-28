<?php
declare(strict_types=1);

function crm_normalize_phone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '90')) {
        return substr($digits, 2);
    }
    if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        return substr($digits, 1);
    }
    return $digits;
}

function crm_customer_phones(array $customer): array
{
    $phones = [];
    foreach (['phone', 'phone_alt'] as $field) {
        $phone = crm_normalize_phone((string) ($customer[$field] ?? ''));
        if ($phone !== '') {
            $phones[] = $phone;
        }
    }
    return array_values(array_unique($phones, SORT_STRING));
}

function crm_call_key(array $cdr): string
{
    $key = trim((string) (($cdr['linkedid'] ?? '') ?: ($cdr['uniqueid'] ?? '')));
    if ($key !== '') {
        return $key;
    }
    return hash('sha256', implode('|', [
        (string) ($cdr['start'] ?? ''),
        (string) ($cdr['src'] ?? ''),
        (string) ($cdr['dst'] ?? ''),
        (string) ($cdr['channel'] ?? ''),
    ]));
}

function crm_calls_for_customer(array $customer, int $limit = 250): array
{
    $dept = (string) ($customer['dept'] ?? '');
    $phones = array_fill_keys(crm_customer_phones($customer), true);
    if ($dept === '' || !$phones) {
        return [];
    }

    $grouped = [];
    foreach (pbx_cdr(5000, $dept) as $row) {
        $src = crm_normalize_phone((string) ($row['src'] ?? ''));
        $dst = crm_normalize_phone((string) ($row['dst'] ?? ''));
        $direction = isset($phones[$src]) ? 'incoming' : (isset($phones[$dst]) ? 'outgoing' : '');
        if ($direction === '') {
            continue;
        }
        $row['crm_direction'] = $direction;
        $row['crm_phone'] = $direction === 'incoming' ? $src : $dst;
        $row['crm_call_key'] = crm_call_key($row);
        $key = $row['crm_call_key'];
        $current = $grouped[$key] ?? null;
        if (
            $current === null
            || ((string) ($current['recordingfile'] ?? '') === '' && (string) ($row['recordingfile'] ?? '') !== '')
            || (int) ($row['billsec'] ?? 0) > (int) ($current['billsec'] ?? 0)
        ) {
            $grouped[$key] = $row;
        }
    }

    $calls = array_values($grouped);
    usort($calls, static fn(array $a, array $b): int =>
        strcmp((string) ($b['start'] ?? ''), (string) ($a['start'] ?? ''))
    );
    return array_slice($calls, 0, max(1, min(1000, $limit)));
}

function crm_recording_for_call(array $cdr, array $recordings): ?array
{
    $recordingFile = trim((string) ($cdr['recordingfile'] ?? ''), '/');
    if ($recordingFile !== '') {
        foreach ($recordings as $recording) {
            if ((string) ($recording['relative_path'] ?? '') === $recordingFile) {
                return $recording;
            }
        }
    }

    $ids = array_filter([
        trim((string) ($cdr['uniqueid'] ?? '')),
        trim((string) ($cdr['linkedid'] ?? '')),
    ]);
    foreach ($recordings as $recording) {
        if (in_array((string) ($recording['uniqueid'] ?? ''), $ids, true)) {
            return $recording;
        }
    }
    return null;
}

function crm_call_note_id(string $dept, string $callKey): string
{
    return 'crmnote-' . substr(hash('sha256', $dept . '|' . $callKey), 0, 32);
}

function crm_customer_by_phone(string $dept, string $phone): ?array
{
    return crm_customer_by_phone_db($dept, $phone);
}

function crm_caller_name_cache_path(string $dept): string
{
    $directory = DATA_PATH . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }
    return $directory . DIRECTORY_SEPARATOR
        . 'crm-caller-names-' . hash('sha256', $dept) . '.json';
}

function crm_local_caller_names(string $dept, bool $refresh = false): array
{
    if ($dept === '') {
        return [];
    }
    $path = crm_caller_name_cache_path($dept);
    if (!$refresh && is_file($path) && time() - (int) filemtime($path) <= 300) {
        $cached = json_decode((string) file_get_contents($path), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $rows = crm_db_json_rows(
        'SELECT p.normalized_value AS phone, c.company '
        . 'FROM crm_customer_phones p '
        . 'JOIN crm_customers c ON c.id = p.customer_id AND c.dept_id = p.dept_id '
        . 'WHERE p.dept_id = ' . pbx_sql_literal($dept)
    );
    $names = [];
    foreach ($rows as $row) {
        $phone = crm_normalize_phone((string) ($row['phone'] ?? ''));
        $company = trim((string) ($row['company'] ?? ''));
        if ($phone !== '' && $company !== '') {
            $names[$phone] = $company;
        }
    }
    file_put_contents(
        $path,
        json_encode($names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    @chmod($path, 0600);
    return $names;
}

function crm_update_local_caller_name_cache(?array $previous, ?array $customer): void
{
    $dept = (string) (($customer['dept'] ?? '') ?: ($previous['dept'] ?? ''));
    if ($dept === '') {
        return;
    }
    $path = crm_caller_name_cache_path($dept);
    if (!is_file($path)) {
        return;
    }
    $names = json_decode((string) file_get_contents($path), true);
    if (!is_array($names)) {
        return;
    }
    if ($previous) {
        foreach (crm_customer_phones($previous) as $phone) {
            unset($names[$phone]);
        }
    }
    if ($customer) {
        $company = trim((string) ($customer['company'] ?? ''));
        foreach (crm_customer_phones($customer) as $phone) {
            if ($company !== '') {
                $names[$phone] = $company;
            }
        }
    }
    file_put_contents(
        $path,
        json_encode($names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    @chmod($path, 0600);
}

function crm_caller_id_cache_value(string $value): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    return mb_substr($value, 0, 80, 'UTF-8');
}

function crm_caller_id_cache_command(string $action, string $code, string $phone, string $company = ''): string
{
    $family = 'CRM/' . ast_sanitize_id($code);
    $phone = preg_replace('/\D+/', '', $phone) ?? '';
    if ($family === 'CRM/' || $phone === '') {
        return '';
    }
    if ($action === 'delete') {
        return "asterisk -rx 'database del {$family} {$phone}'";
    }
    $company = crm_caller_id_cache_value($company);
    if ($company === '') {
        return '';
    }
    $cliValue = str_replace(['\\', '"', "'", '$', '`'], [' ', '', '', '', ''], $company);
    return "asterisk -rx 'database put {$family} {$phone} \"{$cliValue}\"'";
}

function crm_sync_customer_caller_id_cache(?array $previous, ?array $customer): array
{
    crm_update_local_caller_name_cache($previous, $customer);
    $commands = [];
    if ($previous) {
        $code = dept_code_of((string) ($previous['dept'] ?? ''));
        foreach (crm_customer_phones($previous) as $phone) {
            $command = crm_caller_id_cache_command('delete', $code, $phone);
            if ($command !== '') {
                $commands[] = $command;
            }
        }
    }
    if ($customer) {
        $code = dept_code_of((string) ($customer['dept'] ?? ''));
        $company = (string) ($customer['company'] ?? '');
        foreach (crm_customer_phones($customer) as $phone) {
            $command = crm_caller_id_cache_command('put', $code, $phone, $company);
            if ($command !== '') {
                $commands[] = $command;
            }
        }
    }
    if (!$commands) {
        return ['ok' => true, 'code' => 0, 'output' => ''];
    }
    return pbx_ssh(implode("\n", array_values(array_unique($commands))));
}

function crm_sync_all_caller_id_cache(): array
{
    $commands = ["asterisk -rx 'database deltree CRM'"];
    $phones = crm_db_json_rows(
        'SELECT c.dept_id AS dept, c.company, p.normalized_value AS phone '
        . 'FROM crm_customer_phones p '
        . 'JOIN crm_customers c ON c.id = p.customer_id AND c.dept_id = p.dept_id '
        . 'ORDER BY c.dept_id, p.normalized_value'
    );
    foreach ($phones as $row) {
        $code = dept_code_of((string) ($row['dept'] ?? ''));
        $command = crm_caller_id_cache_command(
            'put',
            $code,
            (string) ($row['phone'] ?? ''),
            (string) ($row['company'] ?? '')
        );
        if ($command !== '') {
            $commands[] = $command;
        }
    }
    return pbx_ssh(implode("\n", array_values(array_unique($commands))));
}
