<?php
declare(strict_types=1);

function custom_context_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ok = false;
    $rows = pbx_odbc_rows(
        "SELECT to_regclass('pbx_custom_contexts') IS NOT NULL "
        . "AND to_regclass('pbx_custom_context_steps') IS NOT NULL AS ready",
        $ok
    );
    $value = strtolower((string) ($rows[0]['ready'] ?? ''));
    $ready = $ok && !in_array($value, ['', '0', 'f', 'false'], true);
    return $ready;
}

function custom_context_rows(?string $deptId = null): array
{
    if (!custom_context_schema_ready()) {
        return [];
    }
    $where = $deptId !== null
        ? ' WHERE c.dept_id = ' . pbx_sql_literal($deptId)
        : '';
    $contexts = pbx_relational_json_rows(
        'SELECT c.dept_id, c.context_id, c.context_name, c.description, '
        . "to_char(c.updated_at, 'YYYY-MM-DD HH24:MI:SS') AS updated_at "
        . 'FROM pbx_custom_contexts c' . $where
        . ' ORDER BY c.dept_id, c.context_name'
    );
    if (!$contexts) {
        return [];
    }
    $steps = pbx_relational_json_rows(
        'SELECT s.dept_id, s.context_id, s.position, s.extension_pattern, '
        . 's.priority, s.application, s.application_data '
        . 'FROM pbx_custom_context_steps s'
        . ($deptId !== null ? ' WHERE s.dept_id = ' . pbx_sql_literal($deptId) : '')
        . ' ORDER BY s.dept_id, s.context_id, s.position'
    );
    $byContext = [];
    foreach ($steps as $step) {
        $key = (string) ($step['dept_id'] ?? '') . "\0" . (string) ($step['context_id'] ?? '');
        $byContext[$key][] = $step;
    }
    foreach ($contexts as &$context) {
        $key = (string) $context['dept_id'] . "\0" . (string) $context['context_id'];
        $context['steps'] = $byContext[$key] ?? [];
    }
    unset($context);
    return $contexts;
}

function custom_context_find(string $deptId, string $contextId): ?array
{
    foreach (custom_context_rows($deptId) as $row) {
        if ((string) ($row['context_id'] ?? '') === $contextId) {
            return $row;
        }
    }
    return null;
}

function custom_context_parse_steps(string $input): array
{
    $steps = [];
    foreach (preg_split('/\r?\n/', $input) as $lineNo => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, ';')) {
            continue;
        }
        $parts = array_map('trim', explode('|', $line, 4));
        if (count($parts) !== 4) {
            throw new InvalidArgumentException(($lineNo + 1) . '. satır dört alandan oluşmalı');
        }
        [$extension, $priority, $application, $data] = $parts;
        if (!preg_match('/^[-A-Za-z0-9_+*#!.]+$/', $extension)) {
            throw new InvalidArgumentException(($lineNo + 1) . '. satırda geçersiz extension');
        }
        if (!preg_match('/^(?:n|[1-9][0-9]*)(?:\([A-Za-z0-9_-]+\))?$/', $priority)) {
            throw new InvalidArgumentException(($lineNo + 1) . '. satırda geçersiz priority');
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $application)) {
            throw new InvalidArgumentException(($lineNo + 1) . '. satırda geçersiz application');
        }
        if (str_contains($data, "\r") || str_contains($data, "\n")) {
            throw new InvalidArgumentException(($lineNo + 1) . '. satırda geçersiz application verisi');
        }
        $steps[] = [
            'position' => count($steps),
            'extension_pattern' => $extension,
            'priority' => $priority,
            'application' => $application,
            'application_data' => $data,
        ];
    }
    if (!$steps) {
        throw new InvalidArgumentException('En az bir dialplan adımı girin');
    }
    if (count($steps) > 250) {
        throw new InvalidArgumentException('Bir context en fazla 250 adım içerebilir');
    }
    return $steps;
}

function custom_context_steps_text(array $steps): string
{
    return implode("\n", array_map(
        static fn(array $step): string => implode(' | ', [
            (string) ($step['extension_pattern'] ?? ''),
            (string) ($step['priority'] ?? ''),
            (string) ($step['application'] ?? ''),
            (string) ($step['application_data'] ?? ''),
        ]),
        $steps
    ));
}

function custom_context_save(
    string $deptId,
    string $contextId,
    string $description,
    array $steps
): void {
    $code = dept_code_of($deptId);
    $contextName = 'custom-' . $code . '-' . $contextId;
    $sql = [
        'BEGIN',
        'INSERT INTO pbx_custom_contexts '
        . '(dept_id, context_id, context_name, description, updated_at) VALUES ('
        . pbx_sql_literal($deptId) . ', ' . pbx_sql_literal($contextId) . ', '
        . pbx_sql_literal($contextName) . ', ' . pbx_sql_literal($description) . ', now()) '
        . 'ON CONFLICT (dept_id, context_id) DO UPDATE SET '
        . 'context_name = EXCLUDED.context_name, description = EXCLUDED.description, updated_at = now()',
        'DELETE FROM pbx_custom_context_steps WHERE dept_id = ' . pbx_sql_literal($deptId)
        . ' AND context_id = ' . pbx_sql_literal($contextId),
    ];
    foreach ($steps as $step) {
        $sql[] = 'INSERT INTO pbx_custom_context_steps '
            . '(dept_id, context_id, position, extension_pattern, priority, application, application_data) VALUES ('
            . pbx_sql_literal($deptId) . ', ' . pbx_sql_literal($contextId) . ', '
            . (int) $step['position'] . ', '
            . pbx_sql_literal((string) $step['extension_pattern']) . ', '
            . pbx_sql_literal((string) $step['priority']) . ', '
            . pbx_sql_literal((string) $step['application']) . ', '
            . pbx_sql_literal((string) $step['application_data']) . ')';
    }
    $sql[] = 'COMMIT';
    $result = pbx_odbc_sql(implode(";\n", $sql));
    if (!$result['ok'] || str_contains((string) $result['output'], '[ISQL]ERROR')) {
        throw new RuntimeException('Özel context kaydedilemedi: ' . (string) $result['output']);
    }
}

function custom_context_delete(string $deptId, string $contextId): void
{
    $result = pbx_odbc_sql(
        'DELETE FROM pbx_custom_contexts WHERE dept_id = ' . pbx_sql_literal($deptId)
        . ' AND context_id = ' . pbx_sql_literal($contextId)
    );
    if (!$result['ok'] || str_contains((string) $result['output'], '[ISQL]ERROR')) {
        throw new RuntimeException('Özel context silinemedi: ' . (string) $result['output']);
    }
}

function custom_context_dialplan(): string
{
    $output = '';
    foreach (custom_context_rows() as $context) {
        $output .= "\n;--- Yönetilen özel context ---\n["
            . (string) $context['context_name'] . "]\n";
        foreach ((array) ($context['steps'] ?? []) as $step) {
            $data = (string) ($step['application_data'] ?? '');
            $output .= 'exten => ' . (string) $step['extension_pattern']
                . ',' . (string) $step['priority']
                . ',' . (string) $step['application']
                . '(' . $data . ")\n";
        }
    }
    return $output;
}

function pbx_context_inventory(): array
{
    $output = pbx_cli('dialplan show');
    $contexts = [];
    if (preg_match_all("/\\[ Context '([^']+)' created by '([^']+)' \\]/", $output, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $contexts[$match[1]] = [
                'name' => $match[1],
                'registrar' => $match[2],
                'managed' => str_starts_with($match[1], 'custom-'),
            ];
        }
    }
    ksort($contexts, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($contexts);
}
