<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$options = getopt('', ['dept:', 'name::']);
$dept = trim((string) ($options['dept'] ?? ''));
$name = trim((string) ($options['name'] ?? 'Astera Softphone'));
if ($dept === '' || !dept_by_id($dept)) {
    throw new InvalidArgumentException('Geçerli --dept zorunlu');
}

crm_ensure_tenant($dept);
$token = 'asc_' . bin2hex(random_bytes(32));
$tokenId = bin2hex(random_bytes(16));
$hash = hash('sha256', $token);
$prefix = substr($token, 0, 12);

$insert = pbx_pg_admin(
    'INSERT INTO softphone_crm_tokens '
    . '(token_id, dept_id, token_hash, token_prefix, name) VALUES ('
    . pbx_sql_literal($tokenId) . ', '
    . pbx_sql_literal($dept) . ', '
    . pbx_sql_literal($hash) . ', '
    . pbx_sql_literal($prefix) . ', '
    . pbx_sql_literal($name) . ')'
);
if (!$insert['ok']) {
    throw new RuntimeException(
        'Softphone CRM token oluşturulamadı: ' . ($insert['output'] ?? '')
    );
}

echo json_encode([
    'ok' => true,
    'dept_id' => $dept,
    'token_id' => $tokenId,
    'token' => $token,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
