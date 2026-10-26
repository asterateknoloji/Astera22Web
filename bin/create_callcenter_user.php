<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/callcenter.php';

function callcenter_cli_usage(): never
{
    fwrite(STDERR, <<<TXT
Kullanım:
  php bin/create_callcenter_user.php --type=agent --dept=DEPT --username=USER --name="Ad Soyad" --extension=1001 --queues=Q1,Q2
  php bin/create_callcenter_user.php --type=supervisor --dept=DEPT --username=USER --name="Ad Soyad" --role=supervisor [--queues=Q1,Q2]
  php bin/create_callcenter_user.php --type=supervisor --dept=DEPT --username=ADMIN --name="Ad Soyad" --role=admin --can-choose-tenant --tenants=DEPT2,DEPT3

Parola gizli etkileşimli istemden okunur. Otomasyon için ASTERA_CALLCENTER_PASSWORD
ortam değişkenini kullanın; parolayı komut satırına yazmayın.
TXT);
    exit(2);
}

function callcenter_cli_password(): string
{
    $password = getenv('ASTERA_CALLCENTER_PASSWORD');
    if ($password !== false) {
        return (string) $password;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        throw new RuntimeException('Windows üzerinde ASTERA_CALLCENTER_PASSWORD ortam değişkenini kullanın');
    }
    fwrite(STDERR, 'Parola: ');
    $stty = shell_exec('stty -g 2>/dev/null');
    if (!is_string($stty) || trim($stty) === '') {
        throw new RuntimeException('Gizli parola istemi açılamadı; ASTERA_CALLCENTER_PASSWORD kullanın');
    }
    shell_exec('stty -echo');
    try {
        $value = fgets(STDIN);
    } finally {
        shell_exec('stty ' . escapeshellarg(trim($stty)));
        fwrite(STDERR, PHP_EOL);
    }
    return trim((string) $value);
}

$options = getopt('', [
    'type:', 'dept:', 'username:', 'name:', 'extension:', 'queues:', 'role:',
    'can-choose-tenant', 'tenants:', 'help',
]);
if (isset($options['help'])) {
    callcenter_cli_usage();
}
$type = trim((string) ($options['type'] ?? ''));
$dept = trim((string) ($options['dept'] ?? ''));
$username = trim((string) ($options['username'] ?? ''));
$name = trim((string) ($options['name'] ?? ''));
if (!in_array($type, ['agent', 'supervisor'], true) || $dept === '' || $username === '' || $name === '') {
    callcenter_cli_usage();
}
if (!callcenter_dept_exists($dept)) {
    throw new InvalidArgumentException('Aktif tenant bulunamadı');
}
$password = callcenter_cli_password();
if (strlen($password) < 10) {
    throw new InvalidArgumentException('Parola en az 10 karakter olmalıdır');
}
$hash = password_hash($password, PASSWORD_DEFAULT);
if (!is_string($hash)) {
    throw new RuntimeException('Parola hash üretilemedi');
}
unset($password);
$configured = callcenter_config_queues($dept);
$queueIds = array_values(array_filter(array_unique(array_map('trim', explode(',', (string) ($options['queues'] ?? ''))))));
foreach ($queueIds as $queueId) {
    if (!isset($configured[$queueId])) {
        throw new InvalidArgumentException("Tenant yapılandırmasında kuyruk bulunamadı: {$queueId}");
    }
}

if ($type === 'agent') {
    $extension = trim((string) ($options['extension'] ?? ''));
    $extensionRow = $extension !== '' ? find_ext($dept, $extension) : null;
    if ($extensionRow === null) {
        throw new InvalidArgumentException('Dahili bu tenant yapılandırmasına ait değil');
    }
    if (!$queueIds) {
        throw new InvalidArgumentException('Agent için en az bir --queues ataması zorunludur');
    }
    $endpoint = sip_user($extensionRow);
    foreach ($queueIds as $queueId) {
        $memberEndpoints = [];
        foreach ((array) ($configured[$queueId]['members'] ?? []) as $member) {
            $memberEndpoint = member_sip($dept, (string) $member);
            if ($memberEndpoint !== '') {
                $memberEndpoints[] = $memberEndpoint;
            }
        }
        if ($endpoint === '' || !in_array($endpoint, $memberEndpoints, true)) {
            throw new InvalidArgumentException(
                "Dahili {$extension}, {$queueId} kuyruğunda PJSIP üyesi değil"
            );
        }
    }
    callcenter_execute(
        'INSERT INTO callcenter_agents (dept_id, username, display_name, extension, password_hash) '
        . 'VALUES (:dept, :username, :name, :extension, :hash) '
        . 'ON CONFLICT (dept_id, username) DO UPDATE SET display_name = EXCLUDED.display_name, '
        . "extension = EXCLUDED.extension, password_hash = EXCLUDED.password_hash, status = 'active', updated_at = now()",
        compact('dept', 'username', 'name', 'extension', 'hash')
    );
    $agent = callcenter_rows(
        'SELECT agent_id FROM callcenter_agents WHERE dept_id = :dept AND username = :username',
        compact('dept', 'username')
    )[0] ?? null;
    if (!$agent) {
        throw new RuntimeException('Agent kaydı doğrulanamadı');
    }
    $agentId = (int) $agent['agent_id'];
    callcenter_execute(
        'DELETE FROM callcenter_agent_queue_assignments WHERE dept_id = :dept AND agent_id = :agent',
        ['dept' => $dept, 'agent' => $agentId]
    );
    foreach ($queueIds as $queueId) {
        callcenter_execute(
            'INSERT INTO callcenter_agent_queue_assignments (dept_id, agent_id, queue_id) '
            . 'VALUES (:dept, :agent, :queue)',
            ['dept' => $dept, 'agent' => $agentId, 'queue' => $queueId]
        );
    }
    $id = $agentId;
} else {
    $role = trim((string) ($options['role'] ?? ''));
    if (!in_array($role, ['admin', 'supervisor', 'viewer'], true)) {
        throw new InvalidArgumentException('--role admin, supervisor veya viewer olmalıdır');
    }
    $canChoose = isset($options['can-choose-tenant']);
    if ($canChoose && $role !== 'admin') {
        throw new InvalidArgumentException('--can-choose-tenant yalnızca admin rolünde kullanılabilir');
    }
    $tenantIds = array_values(array_filter(array_unique(array_map(
        'trim',
        explode(',', (string) ($options['tenants'] ?? ''))
    ))));
    if ($canChoose && !$tenantIds) {
        throw new InvalidArgumentException('--can-choose-tenant için --tenants kapsamı zorunludur');
    }
    if (!$canChoose && $tenantIds) {
        throw new InvalidArgumentException('--tenants yalnızca --can-choose-tenant ile kullanılabilir');
    }
    foreach ($tenantIds as $tenantId) {
        if ($tenantId === $dept || !callcenter_dept_exists($tenantId)) {
            throw new InvalidArgumentException("Geçersiz ek tenant kapsamı: {$tenantId}");
        }
    }
    callcenter_execute(
        'INSERT INTO callcenter_supervisor_users '
        . '(dept_id, username, display_name, password_hash, role, '
        . 'can_choose_tenant, must_change_password) '
        . 'VALUES (:dept, :username, :name, :hash, :role, :choose, FALSE) '
        . 'ON CONFLICT (dept_id, username) DO UPDATE SET display_name = EXCLUDED.display_name, '
        . 'password_hash = EXCLUDED.password_hash, role = EXCLUDED.role, '
        . 'can_choose_tenant = EXCLUDED.can_choose_tenant, must_change_password = FALSE, '
        . 'active = TRUE, updated_at = now()',
        ['dept' => $dept, 'username' => $username, 'name' => $name, 'hash' => $hash,
            'role' => $role, 'choose' => $canChoose]
    );
    $supervisor = callcenter_rows(
        'SELECT supervisor_id FROM callcenter_supervisor_users '
        . 'WHERE dept_id = :dept AND username = :username',
        compact('dept', 'username')
    )[0] ?? null;
    if (!$supervisor) {
        throw new RuntimeException('Supervisor kaydı doğrulanamadı');
    }
    $id = (int) $supervisor['supervisor_id'];
    callcenter_execute(
        'DELETE FROM callcenter_supervisor_queue_scopes WHERE dept_id = :dept AND supervisor_id = :id',
        ['dept' => $dept, 'id' => $id]
    );
    foreach ($queueIds as $queueId) {
        callcenter_execute(
            'INSERT INTO callcenter_supervisor_queue_scopes (dept_id, supervisor_id, queue_id) '
            . 'VALUES (:dept, :id, :queue)',
            ['dept' => $dept, 'id' => $id, 'queue' => $queueId]
        );
    }
    callcenter_execute(
        'DELETE FROM callcenter_supervisor_tenant_scopes '
        . 'WHERE owner_dept_id = :dept AND supervisor_id = :id',
        ['dept' => $dept, 'id' => $id]
    );
    foreach ($tenantIds as $tenantId) {
        callcenter_execute(
            'INSERT INTO callcenter_supervisor_tenant_scopes '
            . '(owner_dept_id, supervisor_id, allowed_dept_id) VALUES (:dept, :id, :allowed)',
            ['dept' => $dept, 'id' => $id, 'allowed' => $tenantId]
        );
    }
}
echo json_encode(['ok' => true, 'type' => $type, 'dept_id' => $dept, 'id' => $id],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
