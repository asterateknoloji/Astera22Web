<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/ssh.php';
require_once __DIR__ . '/includes/ami.php';
require_once __DIR__ . '/includes/crm.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $respond(['ok' => false, 'error' => 'GET gerekli'], 405);
}

$authorization = trim((string) (
    $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? ''
));
if ($authorization === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp((string) $name, 'Authorization') === 0) {
            $authorization = trim((string) $value);
            break;
        }
    }
}
if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{32,})$/', $authorization, $match)) {
    $respond(['ok' => false, 'error' => 'Yetkisiz'], 401);
}
$tokenHash = hash('sha256', $match[1]);

$query = trim((string) ($_GET['q'] ?? ''));
$digits = preg_replace('/\D+/', '', $query) ?? '';
$normalized = crm_normalize_phone($query);
$searches = [$normalized];
if (str_starts_with($digits, '90') && strlen($digits) > 2) {
    $searches[] = substr($digits, 2);
} elseif (str_starts_with($digits, '0') && strlen($digits) > 1) {
    $searches[] = substr($digits, 1);
}
$searches = array_values(array_unique(array_filter(
    $searches,
    static fn (string $value): bool => strlen($value) >= 3
)));
$phonePredicates = array_map(
    static fn (string $value): string =>
        'p.normalized_value LIKE ' . pbx_sql_literal('%' . $value . '%'),
    $searches
);
$predicate = $phonePredicates === [] ? 'FALSE' : implode(' OR ', $phonePredicates);
try {
    $sql = 'WITH valid AS ('
        . 'SELECT t.token_id, t.dept_id FROM softphone_crm_tokens t '
        . 'JOIN astera_tenants d ON d.dept_id = t.dept_id AND d.active '
        . 'WHERE t.token_hash = ' . pbx_sql_literal($tokenHash)
        . ' AND t.active AND (t.expires_at IS NULL OR t.expires_at > now()) LIMIT 1'
        . '), touched AS ('
        . 'UPDATE softphone_crm_tokens t SET last_used_at = now(), updated_at = now() '
        . 'FROM valid v WHERE t.token_id = v.token_id RETURNING v.dept_id'
        . '), matched AS ('
        . 'SELECT c.id, c.company, c.contact, p.display_value AS phone, '
        . 'p.normalized_value AS normalized_phone FROM touched v '
        . 'JOIN crm_customer_phones p ON p.dept_id = v.dept_id '
        . 'JOIN crm_customers c ON c.id = p.customer_id AND c.dept_id = p.dept_id '
        . 'WHERE (' . $predicate . ') ORDER BY '
        . '(p.normalized_value = ' . pbx_sql_literal($normalized) . ') DESC, '
        . 'c.company, p.normalized_value LIMIT 8'
        . ') SELECT TRUE AS authorized, m.* FROM touched '
        . 'LEFT JOIN matched m ON TRUE';
    if (extension_loaded('pdo_pgsql') && PHP_OS_FAMILY !== 'Windows') {
        $pdo = new PDO(
            'pgsql:dbname=' . PBX_DB_NAME,
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $result = pbx_pg_admin(
            'COPY (' . $sql . ') TO STDOUT WITH (FORMAT CSV, HEADER TRUE)'
        );
        if (!$result['ok']) {
            throw new RuntimeException('CRM query failed');
        }
        $lines = preg_split('/\r?\n/', trim((string) ($result['output'] ?? '')));
        $rows = [];
        if ($lines && count($lines) > 1) {
            $headers = str_getcsv((string) array_shift($lines));
            foreach ($lines as $line) {
                $values = str_getcsv($line);
                if (count($values) === count($headers)) {
                    $rows[] = array_combine($headers, $values);
                }
            }
        }
    }
} catch (Throwable) {
    $respond(['ok' => false, 'error' => 'CRM geçici olarak kullanılamıyor'], 503);
}
if ($rows === []) {
    $respond(['ok' => false, 'error' => 'Yetkisiz'], 401);
}
$contacts = array_values(array_filter(
    $rows,
    static fn (array $row): bool => !empty($row['id'])
));
foreach ($contacts as &$contact) {
    unset($contact['authorized']);
}
unset($contact);
$respond(['ok' => true, 'contacts' => $contacts]);
