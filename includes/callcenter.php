<?php
declare(strict_types=1);

if (!function_exists('pbx_relational_json_rows')) {
    require_once __DIR__ . '/bootstrap.php';
}

function callcenter_pdo(): ?PDO
{
    if (PHP_OS_FAMILY === 'Windows' || !extension_loaded('pdo_pgsql')) {
        return null;
    }
    static $pdo;
    if (!$pdo instanceof PDO) {
        $dsn = astera_env('ASTERA_DB_DSN', 'pgsql:dbname=' . PBX_DB_NAME);
        $user = astera_env('ASTERA_DB_USER', '');
        $password = astera_env('ASTERA_DB_PASSWORD', '');
        $pdo = new PDO($dsn, $user !== '' ? $user : null, $password !== '' ? $password : null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function callcenter_literal(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? 'TRUE' : 'FALSE';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return pbx_sql_literal((string) $value);
}

function callcenter_expand_sql(string $sql, array $params): string
{
    uksort($params, static fn($a, $b) => strlen((string) $b) <=> strlen((string) $a));
    foreach ($params as $name => $value) {
        $sql = str_replace(':' . ltrim((string) $name, ':'), callcenter_literal($value), $sql);
    }
    if (preg_match('/:\w+/', $sql)) {
        throw new InvalidArgumentException('Eksik SQL parametresi');
    }
    return $sql;
}

function callcenter_prepared_sql(string $sql, array $params): array
{
    $bound = [];
    $counts = [];
    $prepared = preg_replace_callback(
        '/(?<![A-Za-z0-9_:]):([A-Za-z_][A-Za-z0-9_]*)/',
        static function (array $match) use ($params, &$bound, &$counts): string {
            $name = $match[1];
            if (!array_key_exists($name, $params)) {
                throw new InvalidArgumentException("Eksik SQL parametresi: {$name}");
            }
            $counts[$name] = ($counts[$name] ?? 0) + 1;
            $unique = $name . '__' . $counts[$name];
            $bound[$unique] = $params[$name];
            return ':' . $unique;
        },
        $sql
    );
    return [(string) $prepared, $bound];
}

function callcenter_execute_statement(PDOStatement $statement, array $params): void
{
    foreach ($params as $name => $value) {
        $type = match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
        $statement->bindValue(':' . ltrim((string) $name, ':'), $value, $type);
    }
    $statement->execute();
}

function callcenter_rows(string $sql, array $params = []): array
{
    $pdo = callcenter_pdo();
    if ($pdo) {
        [$sql, $params] = callcenter_prepared_sql($sql, $params);
        $statement = $pdo->prepare($sql);
        callcenter_execute_statement($statement, $params);
        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }
    return pbx_relational_json_rows(callcenter_expand_sql($sql, $params));
}

function callcenter_execute(string $sql, array $params = []): void
{
    $pdo = callcenter_pdo();
    if ($pdo) {
        [$sql, $params] = callcenter_prepared_sql($sql, $params);
        $statement = $pdo->prepare($sql);
        callcenter_execute_statement($statement, $params);
        return;
    }
    $result = pbx_odbc_sql(callcenter_expand_sql($sql, $params));
    if (!$result['ok'] || str_contains((string) ($result['output'] ?? ''), '[ISQL]ERROR')) {
        throw new RuntimeException('Çağrı merkezi veritabanı işlemi başarısız');
    }
}

function callcenter_execute_batch(array $statements): void
{
    $pdo = callcenter_pdo();
    if ($pdo) {
        $pdo->beginTransaction();
        try {
            foreach ($statements as [$sql, $params]) {
                [$sql, $params] = callcenter_prepared_sql($sql, $params);
                $statement = $pdo->prepare($sql);
                callcenter_execute_statement($statement, $params);
            }
            $pdo->commit();
            return;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
    $sql = "BEGIN;\n";
    foreach ($statements as [$statement, $params]) {
        $sql .= rtrim(callcenter_expand_sql($statement, $params), " \t\n\r;") . ";\n";
    }
    $result = pbx_odbc_sql($sql . 'COMMIT');
    if (!$result['ok'] || str_contains((string) ($result['output'] ?? ''), '[ISQL]ERROR')) {
        throw new RuntimeException('Çağrı merkezi veritabanı işlemi geri alındı');
    }
}

function callcenter_dept_exists(string $deptId): bool
{
    return isset(callcenter_rows(
        'SELECT dept_id FROM astera_tenants WHERE dept_id = :dept AND active',
        ['dept' => $deptId]
    )[0]);
}

function callcenter_agent_authenticate(string $deptId, string $username, string $password): ?array
{
    $rows = callcenter_rows(
        'SELECT agent_id, dept_id, username, display_name, extension, password_hash '
        . "FROM callcenter_agents WHERE dept_id = :dept "
        . "AND (username = :username OR extension = :username) AND status = 'active' "
        . 'ORDER BY (username = :username) DESC LIMIT 1',
        ['dept' => $deptId, 'username' => $username]
    );
    $row = $rows[0] ?? null;
    if (!$row || !password_verify($password, (string) $row['password_hash'])) {
        return null;
    }
    unset($row['password_hash']);
    return $row;
}

function callcenter_supervisor_authenticate(string $deptId, string $username, string $password): ?array
{
    $rows = callcenter_rows(
        'SELECT supervisor_id, dept_id, username, display_name, role, can_choose_tenant, '
        . 'must_change_password, password_hash '
        . 'FROM callcenter_supervisor_users '
        . 'WHERE dept_id = :dept AND username = :username AND active LIMIT 1',
        ['dept' => $deptId, 'username' => $username]
    );
    $row = $rows[0] ?? null;
    if (!$row || !password_verify($password, (string) $row['password_hash'])) {
        return null;
    }
    unset($row['password_hash']);
    $row['can_choose_tenant'] = in_array(strtolower((string) $row['can_choose_tenant']), ['1', 't', 'true'], true);
    $row['must_change_password'] = in_array(
        strtolower((string) $row['must_change_password']),
        ['1', 't', 'true'],
        true
    );
    return $row;
}

function callcenter_supervisor_change_password(
    string $deptId,
    int $supervisorId,
    string $password
): void {
    if (strlen($password) < 10 || $password === 'password') {
        throw new InvalidArgumentException(
            'Yeni parola en az 10 karakter olmalı ve varsayılan paroladan farklı olmalıdır'
        );
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        throw new RuntimeException('Parola güvenli biçimde hazırlanamadı');
    }
    callcenter_execute(
        'UPDATE callcenter_supervisor_users SET password_hash = :hash, '
        . 'must_change_password = FALSE, updated_at = now() '
        . 'WHERE dept_id = :dept AND supervisor_id = :supervisor AND active',
        ['hash' => $hash, 'dept' => $deptId, 'supervisor' => $supervisorId]
    );
}

function callcenter_config_queues(string $deptId): array
{
    $result = [];
    foreach (store_read('queues') as $queue) {
        if ((string) ($queue['dept'] ?? '') !== $deptId) {
            continue;
        }
        $id = trim((string) ($queue['id'] ?? ''));
        if ($id !== '') {
            $result[$id] = [
                'id' => $id,
                'name' => (string) ($queue['name'] ?? $queue['label'] ?? $id),
                'members' => array_values((array) ($queue['members'] ?? [])),
            ];
        }
    }
    return $result;
}

function callcenter_agent_queues(string $deptId, int $agentId): array
{
    $configured = callcenter_config_queues($deptId);
    $rows = callcenter_rows(
        'SELECT queue_id FROM callcenter_agent_queue_assignments '
        . 'WHERE dept_id = :dept AND agent_id = :agent AND active ORDER BY queue_id',
        ['dept' => $deptId, 'agent' => $agentId]
    );
    $result = [];
    foreach ($rows as $row) {
        $id = (string) $row['queue_id'];
        if (isset($configured[$id])) {
            $result[] = $configured[$id];
        }
    }
    return $result;
}

function callcenter_supervisor_queues(string $deptId, int $supervisorId): array
{
    $configured = callcenter_config_queues($deptId);
    $rows = callcenter_rows(
        'SELECT queue_id FROM callcenter_supervisor_queue_scopes '
        . 'WHERE dept_id = :dept AND supervisor_id = :supervisor ORDER BY queue_id',
        ['dept' => $deptId, 'supervisor' => $supervisorId]
    );
    if (!$rows) {
        return array_values($configured);
    }
    return array_values(array_intersect_key($configured, array_fill_keys(array_column($rows, 'queue_id'), true)));
}

function callcenter_supervisor_allowed_departments(array $identity): array
{
    $ownerDept = (string) ($identity['dept_id'] ?? '');
    $allowed = [];
    foreach (departments() as $department) {
        if ((string) ($department['id'] ?? '') === $ownerDept) {
            $allowed[$ownerDept] = $department;
            break;
        }
    }
    if (($identity['role'] ?? '') !== 'admin' || empty($identity['can_choose_tenant'])) {
        return array_values($allowed);
    }
    $rows = callcenter_rows(
        'SELECT allowed_dept_id FROM callcenter_supervisor_tenant_scopes '
        . 'WHERE owner_dept_id = :dept AND supervisor_id = :supervisor',
        ['dept' => $ownerDept, 'supervisor' => (int) ($identity['supervisor_id'] ?? 0)]
    );
    $ids = array_fill_keys(array_column($rows, 'allowed_dept_id'), true);
    $ids[$ownerDept] = true;
    foreach (departments() as $department) {
        $id = (string) ($department['id'] ?? '');
        if ($id !== '' && isset($ids[$id])) {
            $allowed[$id] = $department;
        }
    }
    return array_values($allowed);
}

function callcenter_supervisor_can_access_department(array $identity, string $deptId): bool
{
    foreach (callcenter_supervisor_allowed_departments($identity) as $department) {
        if ((string) ($department['id'] ?? '') === $deptId) {
            return true;
        }
    }
    return false;
}

function callcenter_permission_definitions(): array
{
    return callcenter_rows(
        'SELECT permission_code,category,label,description,sort_order '
        . 'FROM callcenter_permission_definitions '
        . 'ORDER BY category,sort_order,permission_code'
    );
}

function callcenter_supervisor_permission_codes(array $identity): array
{
    static $cache = [];
    $cacheKey = (string) ($identity['dept_id'] ?? '') . ':' . (int) ($identity['supervisor_id'] ?? 0)
        . ':' . (string) ($identity['role'] ?? '');
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    if (($identity['role'] ?? '') === 'admin') {
        return $cache[$cacheKey] = array_column(callcenter_permission_definitions(), 'permission_code');
    }
    $rows = callcenter_rows(
        'SELECT permission_code FROM callcenter_supervisor_permissions '
        . 'WHERE dept_id=:dept AND supervisor_id=:supervisor ORDER BY permission_code',
        [
            'dept' => (string) ($identity['dept_id'] ?? ''),
            'supervisor' => (int) ($identity['supervisor_id'] ?? 0),
        ]
    );
    return $cache[$cacheKey] = array_values(array_map('strval', array_column($rows, 'permission_code')));
}

function callcenter_supervisor_has_permission(array $identity, string $permission): bool
{
    if (($identity['role'] ?? '') === 'admin') {
        return true;
    }
    return in_array($permission, callcenter_supervisor_permission_codes($identity), true);
}

function callcenter_audit_category(string $page): string
{
    $page = strtolower($page);
    if (str_contains($page, 'cdr') || str_contains($page, 'dahili_ozet')
        || str_contains($page, 'cagri_grafik') || str_contains($page, 'rehber')) {
        return 'cdr';
    }
    if (in_array($page, [
        'agent_tanim', 'agent_yonetim', 'kuyruklistesi', 'mola_tanim',
        'kullanicilar', 'yetki_gruplari', 'ayarlar', 'kuyruksla',
        'anket_yonetim', 'mailtanimlari', 'lograpor',
    ], true)) {
        return 'system';
    }
    return 'callcenter';
}

function callcenter_audit_write(
    array $identity,
    string $eventType,
    string $page,
    string $action,
    string $description = '',
    ?string $category = null,
    ?string $requestPath = null
): void {
    $deptId = trim((string) ($identity['dept_id'] ?? ''));
    $username = trim((string) ($identity['username'] ?? ''));
    if ($deptId === '' || $username === '') {
        return;
    }
    $page = trim($page) !== '' ? trim($page) : 'unknown';
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI'));
    $path = $requestPath ?? (string) ($_SERVER['REQUEST_URI'] ?? '');
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        $ip = null;
    }
    try {
        callcenter_execute(
            'INSERT INTO callcenter_audit_logs '
            . '(dept_id,supervisor_id,username,event_type,category,page,action,description,request_method,request_path,ip_address) '
            . 'VALUES (:dept,:supervisor,:username,:event,:category,:page,:action,:description,:method,:path,:ip)',
            [
                'dept' => $deptId,
                'supervisor' => isset($identity['supervisor_id']) ? (int) $identity['supervisor_id'] : null,
                'username' => $username,
                'event' => $eventType,
                'category' => $category ?? callcenter_audit_category($page),
                'page' => mb_substr($page, 0, 160),
                'action' => mb_substr($action, 0, 160),
                'description' => $description,
                'method' => mb_substr($method, 0, 12),
                'path' => mb_substr($path, 0, 1000),
                'ip' => $ip,
            ]
        );
    } catch (Throwable $error) {
        error_log('Callcenter audit write failed: ' . $error->getMessage());
    }
}

function callcenter_audit_request(array $identity): void
{
    static $written = false;
    if ($written || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return;
    }
    $written = true;
    $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
    callcenter_audit_write($identity, 'page_view', $page, 'Sayfa görüntülendi');
}

function callcenter_ami_action(array $fields): array
{
    $socket = @fsockopen(PBX_HOST, AMI_PORT, $errorNumber, $errorMessage, 2.0);
    if (!is_resource($socket)) {
        throw new RuntimeException('AMI bağlantısı kurulamadı');
    }
    stream_set_timeout($socket, 3);
    fgets($socket);
    $readMessage = static function ($stream): array {
        $message = [];
        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                break;
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if ($message) {
                    break;
                }
                continue;
            }
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $message[strtolower(trim($key))] = trim($value);
            }
        }
        return $message;
    };
    try {
        fwrite($socket, "Action: Login\r\nUsername: " . AMI_USER
            . "\r\nSecret: " . AMI_SECRET . "\r\nEvents: off\r\n\r\n");
        $login = $readMessage($socket);
        if (strtolower((string) ($login['response'] ?? '')) !== 'success') {
            throw new RuntimeException('AMI kimlik doğrulaması başarısız');
        }
        $payload = '';
        foreach ($fields as $name => $value) {
            $cleanName = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $name) ?? '';
            $cleanValue = str_replace(["\r", "\n"], '', (string) $value);
            if ($cleanName !== '') {
                $payload .= $cleanName . ': ' . $cleanValue . "\r\n";
            }
        }
        fwrite($socket, $payload . "\r\n");
        $response = $readMessage($socket);
        if (strtolower((string) ($response['response'] ?? '')) !== 'success') {
            throw new RuntimeException(
                trim((string) ($response['message'] ?? 'AMI işlemi başarısız'))
            );
        }
        return $response;
    } finally {
        @fwrite($socket, "Action: Logoff\r\n\r\n");
        $readMessage($socket);
        fclose($socket);
    }
}

/**
 * Collect QueueStatus and CoreShowChannels over one authenticated AMI socket.
 * The result is filtered by the caller; no AMI identifiers are exposed to the browser.
 */
function callcenter_ami_snapshot(): array
{
    $socket = @fsockopen(PBX_HOST, AMI_PORT, $errorNumber, $errorMessage, 2.0);
    if (!is_resource($socket)) {
        throw new RuntimeException('AMI bağlantısı kurulamadı');
    }
    stream_set_timeout($socket, 4);
    $readMessage = static function ($stream): ?array {
        $message = [];
        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                return $message ?: null;
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                return $message ?: null;
            }
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $message[strtolower(trim($key))] = trim($value);
            }
        }
        return $message ?: null;
    };
    try {
        fgets($socket); // AMI banner is a single line without a message terminator.
        fwrite($socket, "Action: Login\r\nUsername: " . AMI_USER
            . "\r\nSecret: " . AMI_SECRET . "\r\nEvents: off\r\n\r\n");
        $login = $readMessage($socket);
        if (strtolower((string) ($login['response'] ?? '')) !== 'success') {
            throw new RuntimeException('AMI kimlik doğrulaması başarısız');
        }
        $collect = static function (
            $stream,
            callable $reader,
            string $action,
            string $completeEvent
        ): array {
            $id = 'astera-agent-' . bin2hex(random_bytes(6));
            fwrite($stream, "Action: {$action}\r\nActionID: {$id}\r\n\r\n");
            $events = [];
            while (!feof($stream)) {
                $message = $reader($stream);
                if ($message === null) {
                    break;
                }
                if (strtolower((string) ($message['response'] ?? '')) === 'error') {
                    throw new RuntimeException((string) ($message['message'] ?? 'AMI sorgusu başarısız'));
                }
                $event = strtolower((string) ($message['event'] ?? ''));
                if ($event !== '') {
                    $events[] = $message;
                }
                if ($event === strtolower($completeEvent)) {
                    break;
                }
            }
            return $events;
        };
        return [
            'queue_events' => $collect($socket, $readMessage, 'QueueStatus', 'QueueStatusComplete'),
            'channel_events' => $collect($socket, $readMessage, 'CoreShowChannels', 'CoreShowChannelsComplete'),
        ];
    } finally {
        @fwrite($socket, "Action: Logoff\r\n\r\n");
        $readMessage($socket);
        fclose($socket);
    }
}

function callcenter_agent_endpoint(string $deptId, string $extension): string
{
    $extensionRow = find_ext($deptId, $extension);
    return $extensionRow ? sip_user($extensionRow) : '';
}

function callcenter_phone_mode_labels(): array
{
    return [
        'webrtc' => 'WebRTC',
        'softphone' => 'Softphone',
        'desk_phone' => 'Masaüstü Telefon',
    ];
}

function callcenter_extension_phone_modes(array $extension): array
{
    $allowed = array_keys(callcenter_phone_mode_labels());
    $configured = $extension['phone_modes'] ?? null;
    if (is_string($configured)) {
        $configured = array_filter(array_map('trim', explode(',', $configured)));
    }
    if (is_array($configured)) {
        return array_values(array_intersect($allowed, array_map('strval', $configured)));
    }

    // Eski kayıtlarda WebRTC açık aboneler tarayıcı telefonu olarak korunur.
    // Klasik PJSIP abonelerinde cihaz türü geçmişte tutulmadığı için iki
    // harici telefon seçeneği de düzenlenene kadar geriye uyumlu kabul edilir.
    return !empty($extension['webrtc']) ? ['webrtc'] : ['softphone', 'desk_phone'];
}

function callcenter_validate_agent_phone_mode(array $agent, string $phoneMode): array
{
    $labels = callcenter_phone_mode_labels();
    if (!isset($labels[$phoneMode])) {
        throw new InvalidArgumentException('Geçerli bir telefon türü seçin.');
    }
    $deptId = (string) ($agent['dept_id'] ?? '');
    $extension = (string) ($agent['extension'] ?? '');
    $extensionRow = find_ext($deptId, $extension);
    if (!$extensionRow) {
        throw new RuntimeException('Agent dahilisi abone kayıtlarında bulunamadı.');
    }
    if (!in_array($phoneMode, callcenter_extension_phone_modes($extensionRow), true)) {
        throw new RuntimeException($labels[$phoneMode] . ' bu abone için etkin değil.');
    }
    if ($phoneMode === 'webrtc') {
        if (empty($extensionRow['webrtc'])) {
            throw new RuntimeException('Bu abone için WebRTC erişimi kapalı.');
        }
        return $extensionRow;
    }

    $registered = false;
    foreach ((array) (pbx_blf_status($deptId)['extensions'] ?? []) as $state) {
        if ((string) ($state['extension'] ?? '') !== $extension) {
            continue;
        }
        $registered = !in_array(
            (string) ($state['state'] ?? 'unknown'),
            ['offline', 'unknown'],
            true
        );
        break;
    }
    if (!$registered) {
        throw new RuntimeException(
            $labels[$phoneMode] . ' aktif değil. Telefonu santrale kaydedip tekrar giriş yapın.'
        );
    }
    return $extensionRow;
}

function callcenter_agent_queue_context(string $deptId, int $agentId): array
{
    $agent = callcenter_rows(
        'SELECT extension, display_name FROM callcenter_agents '
        . "WHERE dept_id = :dept AND agent_id = :agent AND status = 'active'",
        ['dept' => $deptId, 'agent' => $agentId]
    )[0] ?? null;
    $extension = (string) ($agent['extension'] ?? '');
    $endpoint = callcenter_agent_endpoint($deptId, $extension);
    $queues = callcenter_agent_queues($deptId, $agentId);
    if ($endpoint === '' || !$queues) {
        throw new RuntimeException('Agent dahili veya kuyruk ataması bulunamadı');
    }
    return [
        'endpoint' => $endpoint,
        'interface' => 'PJSIP/' . $endpoint,
        'display_name' => (string) ($agent['display_name'] ?? $extension),
        'queues' => $queues,
    ];
}

function callcenter_live_queue_members(): array
{
    $members = [];
    foreach (callcenter_ami_snapshot()['queue_events'] as $event) {
        if (strtolower((string) ($event['event'] ?? '')) !== 'queuemember') {
            continue;
        }
        $queue = (string) ($event['queue'] ?? '');
        $interface = (string) (($event['location'] ?? '') ?: ($event['stateinterface'] ?? ''));
        if ($queue !== '' && $interface !== '') {
            $members[$queue . "\0" . $interface] = $event;
        }
    }
    return $members;
}

function callcenter_login_agent_queues(string $deptId, int $agentId): array
{
    $context = callcenter_agent_queue_context($deptId, $agentId);
    $members = callcenter_live_queue_members();
    $rollback = ['added' => [], 'repause' => []];
    try {
        foreach ($context['queues'] as $queue) {
            $queueId = (string) ($queue['id'] ?? '');
            $key = $queueId . "\0" . $context['interface'];
            if (isset($members[$key])) {
                $wasPaused = in_array(
                    strtolower((string) ($members[$key]['paused'] ?? '0')),
                    ['1', 'true', 'yes'],
                    true
                );
                callcenter_ami_action([
                    'Action' => 'QueuePause',
                    'Queue' => $queueId,
                    'Interface' => $context['interface'],
                    'Paused' => 'false',
                    'Reason' => 'Astera panel girişi',
                ]);
                if ($wasPaused) {
                    $rollback['repause'][] = $queueId;
                }
                continue;
            }
            callcenter_ami_action([
                'Action' => 'QueueAdd',
                'Queue' => $queueId,
                'Interface' => $context['interface'],
                'MemberName' => $context['display_name'],
                'StateInterface' => $context['interface'],
                'Paused' => 'false',
            ]);
            $rollback['added'][] = $queueId;
        }
        return $rollback;
    } catch (Throwable $error) {
        callcenter_rollback_agent_queue_login($context['interface'], $rollback);
        throw $error;
    }
}

function callcenter_rollback_agent_queue_login(string $interface, array $rollback): void
{
    foreach (array_reverse((array) ($rollback['added'] ?? [])) as $queueId) {
        try {
            callcenter_ami_action([
                'Action' => 'QueueRemove',
                'Queue' => (string) $queueId,
                'Interface' => $interface,
            ]);
        } catch (Throwable) {
        }
    }
    foreach ((array) ($rollback['repause'] ?? []) as $queueId) {
        try {
            callcenter_ami_action([
                'Action' => 'QueuePause',
                'Queue' => (string) $queueId,
                'Interface' => $interface,
                'Paused' => 'true',
                'Reason' => 'Oturum başlatma geri alma',
            ]);
        } catch (Throwable) {
        }
    }
}

function callcenter_logout_agent_queues(string $deptId, int $agentId): void
{
    $context = callcenter_agent_queue_context($deptId, $agentId);
    $members = callcenter_live_queue_members();
    foreach ($context['queues'] as $queue) {
        $queueId = (string) ($queue['id'] ?? '');
        $key = $queueId . "\0" . $context['interface'];
        if (!isset($members[$key])) {
            continue;
        }
        $dynamic = strtolower((string) ($members[$key]['membership'] ?? '')) === 'dynamic';
        callcenter_ami_action([
            'Action' => $dynamic ? 'QueueRemove' : 'QueuePause',
            'Queue' => $queueId,
            'Interface' => $context['interface'],
            ...($dynamic ? [] : [
                'Paused' => 'true',
                'Reason' => 'Astera panel çıkışı',
            ]),
        ]);
    }
}

function callcenter_set_agent_queue_pause(
    string $deptId,
    int $agentId,
    bool $paused,
    string $reason
): void {
    $context = callcenter_agent_queue_context($deptId, $agentId);
    $changed = [];
    try {
        foreach ($context['queues'] as $queue) {
            $queueId = (string) ($queue['id'] ?? '');
            callcenter_ami_action([
                'Action' => 'QueuePause',
                'Queue' => $queueId,
                'Interface' => $context['interface'],
                'Paused' => $paused ? 'true' : 'false',
                'Reason' => $reason,
            ]);
            $changed[] = $queueId;
        }
    } catch (Throwable $error) {
        foreach ($changed as $queueId) {
            try {
                callcenter_ami_action([
                    'Action' => 'QueuePause',
                    'Queue' => $queueId,
                    'Interface' => $context['interface'],
                    'Paused' => $paused ? 'false' : 'true',
                    'Reason' => 'rollback',
                ]);
            } catch (Throwable) {
            }
        }
        throw $error;
    }
}

function callcenter_start_session(string $deptId, int $agentId, string $phoneMode): int
{
    if (!isset(callcenter_phone_mode_labels()[$phoneMode])) {
        throw new InvalidArgumentException('Geçersiz telefon türü');
    }
    $context = callcenter_agent_queue_context($deptId, $agentId);
    $queueRollback = callcenter_login_agent_queues($deptId, $agentId);
    try {
        callcenter_execute_batch([
            [
                'UPDATE callcenter_breaks SET ended_at = now(), updated_at = now() '
                . 'WHERE dept_id = :dept AND agent_id = :agent AND ended_at IS NULL',
                ['dept' => $deptId, 'agent' => $agentId],
            ],
            [
                "UPDATE callcenter_agent_sessions SET ended_at = now(), end_reason = 'relogin', updated_at = now() "
                . 'WHERE dept_id = :dept AND agent_id = :agent AND ended_at IS NULL',
                ['dept' => $deptId, 'agent' => $agentId],
            ],
            [
                'INSERT INTO callcenter_agent_sessions (dept_id, agent_id, phone_mode) '
                . 'VALUES (:dept, :agent, :phone_mode)',
                ['dept' => $deptId, 'agent' => $agentId, 'phone_mode' => $phoneMode],
            ],
        ]);
    } catch (Throwable $error) {
        callcenter_rollback_agent_queue_login($context['interface'], $queueRollback);
        throw $error;
    }
    $row = callcenter_rows(
        'SELECT session_id FROM callcenter_agent_sessions '
        . 'WHERE dept_id = :dept AND agent_id = :agent AND ended_at IS NULL LIMIT 1',
        ['dept' => $deptId, 'agent' => $agentId]
    );
    if (!isset($row[0]['session_id'])) {
        throw new RuntimeException('Oturum kaydı oluşturulamadı');
    }
    return (int) $row[0]['session_id'];
}

function callcenter_end_session(string $deptId, int $agentId, int $sessionId): void
{
    try {
        callcenter_logout_agent_queues($deptId, $agentId);
    } catch (Throwable $error) {
        error_log('Callcenter logout queue operation failed: ' . $error->getMessage());
    }
    callcenter_execute_batch([
        [
            "UPDATE callcenter_breaks SET ended_at = now(), updated_at = now() "
            . 'WHERE dept_id = :dept AND agent_id = :agent AND ended_at IS NULL',
            ['dept' => $deptId, 'agent' => $agentId],
        ],
        [
            "UPDATE callcenter_agent_sessions SET ended_at = now(), end_reason = 'logout', updated_at = now() "
            . 'WHERE dept_id = :dept AND agent_id = :agent AND session_id = :session AND ended_at IS NULL',
            ['dept' => $deptId, 'agent' => $agentId, 'session' => $sessionId],
        ],
    ]);
}

function callcenter_break_types(string $deptId): array
{
    return callcenter_rows(
        'SELECT break_type_id, code, name, max_duration_seconds FROM callcenter_break_types '
        . 'WHERE dept_id = :dept AND active ORDER BY name',
        ['dept' => $deptId]
    );
}

function callcenter_active_break(string $deptId, int $agentId): ?array
{
    return callcenter_rows(
        'SELECT b.break_id, b.break_type_id, t.name, '
        . "to_char(b.started_at, 'YYYY-MM-DD HH24:MI:SS') AS started_at, "
        . 'EXTRACT(EPOCH FROM (now() - b.started_at))::integer AS duration_seconds '
        . 'FROM callcenter_breaks b JOIN callcenter_break_types t '
        . 'ON t.dept_id = b.dept_id AND t.break_type_id = b.break_type_id '
        . 'WHERE b.dept_id = :dept AND b.agent_id = :agent AND b.ended_at IS NULL LIMIT 1',
        ['dept' => $deptId, 'agent' => $agentId]
    )[0] ?? null;
}

function callcenter_start_break(string $deptId, int $agentId, int $sessionId, int $breakTypeId): array
{
    $valid = callcenter_rows(
        'SELECT break_type_id FROM callcenter_break_types '
        . 'WHERE dept_id = :dept AND break_type_id = :type AND active',
        ['dept' => $deptId, 'type' => $breakTypeId]
    );
    if (!$valid) {
        throw new InvalidArgumentException('Geçersiz mola türü');
    }
    $session = callcenter_rows(
        'SELECT session_id FROM callcenter_agent_sessions '
        . 'WHERE dept_id = :dept AND agent_id = :agent AND session_id = :session '
        . 'AND ended_at IS NULL',
        ['dept' => $deptId, 'agent' => $agentId, 'session' => $sessionId]
    );
    if (!$session) {
        throw new RuntimeException('Aktif agent oturumu bulunamadı');
    }
    if (callcenter_active_break($deptId, $agentId)) {
        throw new RuntimeException('Zaten etkin bir mola var');
    }
    callcenter_set_agent_queue_pause($deptId, $agentId, true, 'Astera panel molası');
    try {
        callcenter_execute(
            'INSERT INTO callcenter_breaks (dept_id, agent_id, break_type_id, session_id) '
            . 'VALUES (:dept, :agent, :type, :session)',
            ['dept' => $deptId, 'agent' => $agentId, 'type' => $breakTypeId, 'session' => $sessionId]
        );
    } catch (Throwable $error) {
        try {
            callcenter_set_agent_queue_pause($deptId, $agentId, false, 'Veritabanı geri alma');
        } catch (Throwable) {
        }
        throw $error;
    }
    return ['message' => 'Mola başlatıldı ve agent kuyruklarda duraklatıldı.'];
}

function callcenter_end_break(string $deptId, int $agentId): array
{
    if (!callcenter_active_break($deptId, $agentId)) {
        throw new RuntimeException('Etkin mola bulunamadı');
    }
    callcenter_set_agent_queue_pause($deptId, $agentId, false, 'Astera panel mola sonu');
    try {
        callcenter_execute(
            'UPDATE callcenter_breaks SET ended_at = now(), updated_at = now() '
            . 'WHERE dept_id = :dept AND agent_id = :agent AND ended_at IS NULL',
            ['dept' => $deptId, 'agent' => $agentId]
        );
    } catch (Throwable $error) {
        try {
            callcenter_set_agent_queue_pause($deptId, $agentId, true, 'Veritabanı geri alma');
        } catch (Throwable) {
        }
        throw $error;
    }
    return ['message' => 'Mola bitirildi ve agent kuyruklarda yeniden etkinleştirildi.'];
}

function callcenter_agent_daily_stats(
    string $deptId,
    int $agentId,
    string $extension,
    string $endpoint = ''
): array
{
    $time = callcenter_rows(
        'SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (COALESCE(ended_at, now()) '
        . "- GREATEST(started_at, CURRENT_DATE::timestamptz)))), 0)::integer AS login_seconds "
        . 'FROM callcenter_agent_sessions WHERE dept_id = :dept AND agent_id = :agent '
        . "AND started_at < CURRENT_DATE + interval '1 day' AND COALESCE(ended_at, now()) >= CURRENT_DATE",
        ['dept' => $deptId, 'agent' => $agentId]
    )[0] ?? [];
    $break = callcenter_rows(
        'SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (COALESCE(ended_at, now()) '
        . "- GREATEST(started_at, CURRENT_DATE::timestamptz)))), 0)::integer AS break_seconds "
        . 'FROM callcenter_breaks WHERE dept_id = :dept AND agent_id = :agent '
        . "AND started_at < CURRENT_DATE + interval '1 day' AND COALESCE(ended_at, now()) >= CURRENT_DATE",
        ['dept' => $deptId, 'agent' => $agentId]
    )[0] ?? [];
    $channelPattern = $endpoint !== '' ? 'PJSIP/' . $endpoint . '-%' : 'PJSIP/__no_endpoint__-%';
    $calls = callcenter_rows(
        "WITH agent_legs AS (SELECT COALESCE(NULLIF(linkedid, ''), uniqueid) AS call_id, "
        . "bool_or(src = :extension OR channel ILIKE :channel) AS outgoing, "
        . "bool_or(dst = :extension OR dstchannel ILIKE :channel) AS incoming, "
        . "bool_or(disposition = 'ANSWERED') AS answered "
        . 'FROM cdr WHERE dept_id = :dept AND calldate >= CURRENT_DATE '
        . "AND calldate < CURRENT_DATE + interval '1 day' "
        . 'AND (src = :extension OR dst = :extension OR channel ILIKE :channel '
        . 'OR dstchannel ILIKE :channel) GROUP BY COALESCE(NULLIF(linkedid, \'\'), uniqueid)) '
        . 'SELECT count(*)::integer AS total, '
        . 'count(*) FILTER (WHERE incoming AND NOT outgoing)::integer AS incoming, '
        . 'count(*) FILTER (WHERE outgoing)::integer AS outgoing, '
        . 'count(*) FILTER (WHERE answered)::integer AS answered, '
        . 'count(*) FILTER (WHERE incoming AND NOT outgoing AND answered)::integer AS incoming_answered, '
        . 'count(*) FILTER (WHERE incoming AND NOT outgoing AND NOT answered)::integer AS incoming_missed, '
        . 'count(*) FILTER (WHERE outgoing AND answered)::integer AS outgoing_answered, '
        . 'count(*) FILTER (WHERE outgoing AND NOT answered)::integer AS outgoing_missed '
        . 'FROM agent_legs',
        ['dept' => $deptId, 'extension' => $extension, 'channel' => $channelPattern]
    )[0] ?? [];
    return [
        'login_seconds' => (int) ($time['login_seconds'] ?? 0),
        'break_seconds' => (int) ($break['break_seconds'] ?? 0),
        'total' => (int) ($calls['total'] ?? 0),
        'incoming' => (int) ($calls['incoming'] ?? 0),
        'outgoing' => (int) ($calls['outgoing'] ?? 0),
        'answered' => (int) ($calls['answered'] ?? 0),
        'incoming_answered' => (int) ($calls['incoming_answered'] ?? 0),
        'incoming_missed' => (int) ($calls['incoming_missed'] ?? 0),
        'outgoing_answered' => (int) ($calls['outgoing_answered'] ?? 0),
        'outgoing_missed' => (int) ($calls['outgoing_missed'] ?? 0),
    ];
}

function callcenter_recent_calls(
    string $deptId,
    string $extension,
    string $endpoint = '',
    int $limit = 10
): array
{
    $limit = max(1, min(50, $limit));
    $channelPattern = $endpoint !== '' ? 'PJSIP/' . $endpoint . '-%' : 'PJSIP/__no_endpoint__-%';
    return callcenter_rows(
        "SELECT to_char(max(calldate), 'YYYY-MM-DD HH24:MI:SS') AS calldate, "
        . "max(src) FILTER (WHERE src <> '') AS src, max(dst) FILTER (WHERE dst <> '') AS dst, "
        . "CASE WHEN bool_or(src = :extension OR channel ILIKE :channel) THEN 'outgoing' ELSE 'incoming' END AS direction, "
        . "CASE WHEN bool_or(disposition = 'ANSWERED') THEN 'ANSWERED' "
        . "ELSE max(disposition) END AS disposition, max(duration)::integer AS duration, "
        . "max(billsec)::integer AS billsec, COALESCE(NULLIF(linkedid, ''), uniqueid) AS call_id "
        . 'FROM cdr WHERE dept_id = :dept AND '
        . '(src = :extension OR dst = :extension OR channel ILIKE :channel OR dstchannel ILIKE :channel) '
        . "GROUP BY COALESCE(NULLIF(linkedid, ''), uniqueid) ORDER BY max(calldate) DESC LIMIT {$limit}",
        ['dept' => $deptId, 'extension' => $extension, 'channel' => $channelPattern]
    );
}

function callcenter_agent_live_state(
    string $endpoint,
    string $extension,
    array $channelEvents,
    array $queueEvents = []
): array
{
    $owned = [];
    foreach ($channelEvents as $channel) {
        $name = (string) ($channel['channel'] ?? '');
        if ($endpoint !== '' && preg_match('#^PJSIP/' . preg_quote($endpoint, '#') . '-[A-Fa-f0-9]+$#', $name)) {
            $owned[] = $channel;
        }
    }
    if (!$owned) {
        $memberSeen = false;
        $reachable = false;
        foreach ($queueEvents as $event) {
            if (strtolower((string) ($event['event'] ?? '')) !== 'queuemember') {
                continue;
            }
            $interface = (string) (($event['interface'] ?? '') ?: ($event['location'] ?? ''));
            if ($interface !== 'PJSIP/' . $endpoint) {
                continue;
            }
            $memberSeen = true;
            if (!in_array((int) ($event['status'] ?? 0), [0, 5], true)) {
                $reachable = true;
            }
        }
        return ['extension' => $extension,
            'state' => $reachable ? 'available' : ($memberSeen ? 'offline' : 'unknown'),
            'label' => $reachable ? 'Müsait' : ($memberSeen ? 'Çevrimdışı' : 'Bilinmiyor'),
            'peer' => '', 'call_age' => 0];
    }
    $channel = $owned[0];
    $state = strtolower((string) ($channel['channelstatedesc'] ?? ''));
    $bridge = (string) ($channel['bridgeid'] ?? '');
    $talking = $bridge !== '' || $state === 'up';
    $peer = '';
    $ownNumbers = array_values(array_unique(array_filter([$extension, $endpoint])));
    foreach ([
        (string) ($channel['connectedlinenum'] ?? ''),
        (string) ($channel['exten'] ?? ''),
        (string) ($channel['calleridnum'] ?? ''),
    ] as $candidate) {
        $candidate = trim($candidate);
        if (
            $candidate !== ''
            && strtolower($candidate) !== 's'
            && !in_array($candidate, $ownNumbers, true)
        ) {
            $peer = $candidate;
            break;
        }
    }
    $duration = (string) ($channel['duration'] ?? '');
    $seconds = 0;
    if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', $duration, $match)) {
        $seconds = (int) $match[1] * 3600 + (int) $match[2] * 60 + (int) $match[3];
    }
    return ['extension' => $extension, 'state' => $talking ? 'talking' : 'ringing',
        'label' => $talking ? 'Görüşmede' : 'Çalıyor', 'peer' => $peer, 'call_age' => $seconds];
}

function callcenter_owned_channels(string $endpoint, array $channelEvents): array
{
    if ($endpoint === '') {
        return [];
    }
    return array_values(array_filter($channelEvents, static function (array $channel) use ($endpoint): bool {
        return (bool) preg_match(
            '#^PJSIP/' . preg_quote($endpoint, '#') . '-[A-Fa-f0-9]+$#',
            (string) ($channel['channel'] ?? '')
        );
    }));
}

function callcenter_monitor_target_channels(array $extension, array $channelEvents): array
{
    $technology = strtolower((string) ($extension['technology'] ?? 'pjsip'));
    $number = (string) ($extension['exten'] ?? '');
    $endpoint = sip_user($extension);
    return array_values(array_filter(
        $channelEvents,
        static function (array $channel) use ($technology, $number, $endpoint): bool {
            $name = (string) ($channel['channel'] ?? '');
            return match ($technology) {
                'dahdi' => $number !== '' && (bool) preg_match(
                    '#^DAHDI/[^/]+/' . preg_quote($number, '#') . '-#',
                    $name
                ),
                'custom' => $number !== '' && (bool) preg_match(
                    '#^Local/' . preg_quote($number, '#') . '@#',
                    $name
                ),
                default => $endpoint !== '' && (bool) preg_match(
                    '#^PJSIP/' . preg_quote($endpoint, '#') . '-[A-Fa-f0-9]+$#',
                    $name
                ),
            };
        }
    ));
}

function callcenter_supervisor_monitor_start(
    array $identity,
    string $observerExtension,
    string $targetExtension,
    string $mode
): string {
    $dept = (string) ($identity['dept_id'] ?? '');
    $observerExtension = trim($observerExtension);
    $targetExtension = trim($targetExtension);
    $mode = strtolower(trim($mode));
    $modeOptions = [
        'listen' => 'qE',
        'whisper' => 'qwE',
        'barge' => 'qBE',
    ];
    $modeLabels = [
        'listen' => 'Dinleme',
        'whisper' => 'Fısıldama',
        'barge' => 'Araya girme',
    ];
    if (!isset($modeOptions[$mode])) {
        throw new InvalidArgumentException('Geçersiz izleme modu');
    }
    if (
        preg_match('/^[0-9A-Za-z_]{2,20}$/D', $observerExtension) !== 1
        || preg_match('/^[0-9A-Za-z_]{2,20}$/D', $targetExtension) !== 1
    ) {
        throw new InvalidArgumentException('Geçerli bir supervisor ve hedef dahili girin');
    }
    if ($observerExtension === $targetExtension) {
        throw new InvalidArgumentException('Supervisor telefonu ile hedef dahili aynı olamaz');
    }

    $observer = find_ext($dept, $observerExtension);
    if ($observer === null) {
        throw new RuntimeException('Supervisor telefonu bu firmaya ait değil');
    }
    if (strtolower((string) ($observer['technology'] ?? 'pjsip')) !== 'pjsip') {
        throw new RuntimeException('Supervisor telefonu kayıtlı bir PJSIP dahili olmalıdır');
    }
    $observerEndpoint = sip_user($observer);
    if ($observerEndpoint === '') {
        throw new RuntimeException('Supervisor telefonunun PJSIP eşleşmesi bulunamadı');
    }

    $target = find_ext($dept, $targetExtension);
    if ($target === null) {
        throw new RuntimeException('İzlenecek dahili bu firmaya ait değil');
    }
    $live = callcenter_ami_snapshot();
    $observerChannels = callcenter_owned_channels(
        $observerEndpoint,
        (array) ($live['channel_events'] ?? [])
    );
    if ($observerChannels) {
        throw new RuntimeException('Supervisor telefonu şu anda başka bir çağrıda');
    }
    $observerState = callcenter_ami_action([
        'Action' => 'ExtensionState',
        'Context' => 'int-' . dept_code_of($dept),
        'Exten' => $observerExtension,
    ]);
    $observerStatus = (int) ($observerState['status'] ?? -1);
    if ($observerStatus === -1 || ($observerStatus & 4) === 4) {
        throw new RuntimeException('Supervisor telefonu kayıtlı veya ulaşılabilir değil');
    }
    if ($observerStatus !== 0) {
        throw new RuntimeException('Supervisor telefonu boşta değil');
    }

    $targetChannels = callcenter_monitor_target_channels(
        $target,
        (array) ($live['channel_events'] ?? [])
    );
    $talkingChannels = array_values(array_filter(
        $targetChannels,
        static fn(array $channel): bool =>
            (string) ($channel['bridgeid'] ?? '') !== ''
            && strtolower((string) ($channel['channelstatedesc'] ?? '')) === 'up'
    ));
    if (count($talkingChannels) !== 1) {
        throw new RuntimeException(
            !$talkingChannels
                ? 'Hedef dahili şu anda bağlı bir görüşmede değil'
                : 'Hedef görüşme kanalı güvenle belirlenemedi'
        );
    }
    $targetChannel = (string) ($talkingChannels[0]['channel'] ?? '');
    if ($targetChannel === '') {
        throw new RuntimeException('Hedef görüşme kanalı bulunamadı');
    }

    callcenter_ami_action([
        'Action' => 'Originate',
        'Channel' => 'PJSIP/' . $observerEndpoint,
        'Application' => 'ChanSpy',
        'Data' => $targetChannel . ',' . $modeOptions[$mode],
        'CallerID' => '"Supervisor ' . $modeLabels[$mode] . '" <' . $targetExtension . '>',
        'Timeout' => '30000',
        'Async' => 'true',
    ]);
    return $modeLabels[$mode] . ' hazırlandı. ' . $observerExtension
        . ' telefonu çalacak; cevaplayınca işlem başlayacak.';
}

function callcenter_phone_action(array $identity, string $action, string $target = ''): string
{
    $dept = (string) $identity['dept_id'];
    $extension = (string) $identity['extension'];
    $endpoint = callcenter_agent_endpoint($dept, $extension);
    if ($endpoint === '') {
        throw new RuntimeException('Agent PJSIP dahili eşleşmesi bulunamadı');
    }
    if ($target !== '' && !preg_match('/^[0-9*#]{2,20}$/', $target)) {
        throw new InvalidArgumentException('Geçersiz hedef numara');
    }
    $live = callcenter_ami_snapshot();
    $owned = callcenter_owned_channels($endpoint, $live['channel_events']);
    if ($action === 'originate') {
        if ($target === '') {
            throw new InvalidArgumentException('Aranacak numarayı girin');
        }
        if ($owned) {
            throw new RuntimeException('Dahilinizde zaten etkin bir çağrı var');
        }
        callcenter_ami_action([
            'Action' => 'Originate',
            'Channel' => 'PJSIP/' . $endpoint,
            'Context' => 'from-' . dept_code_of($dept),
            'Exten' => $target,
            'Priority' => '1',
            'CallerID' => $extension,
            'Async' => 'true',
        ]);
        return 'Arama başlatıldı; önce dahiliniz çalacaktır.';
    }
    if (count($owned) !== 1) {
        throw new RuntimeException(
            !$owned ? 'Dahilinize ait etkin çağrı bulunamadı' : 'Çağrı kanalı güvenle belirlenemedi'
        );
    }
    $channel = $owned[0];
    $channelName = (string) $channel['channel'];
    if ($action === 'hangup') {
        callcenter_ami_action(['Action' => 'Hangup', 'Channel' => $channelName]);
        return 'Çağrı kapatıldı.';
    }
    if ($action === 'transfer') {
        $state = strtolower((string) ($channel['channelstatedesc'] ?? ''));
        if ($target === '' || ($state !== 'up' && (string) ($channel['bridgeid'] ?? '') === '')) {
            throw new RuntimeException('Yalnızca bağlı tek bir çağrı güvenle aktarılabilir');
        }
        callcenter_ami_action([
            'Action' => 'Redirect',
            'Channel' => $channelName,
            'Context' => 'from-' . dept_code_of($dept),
            'Exten' => $target,
            'Priority' => '1',
        ]);
        return 'Çağrı aktarıldı.';
    }
    throw new InvalidArgumentException('Bilinmeyen telefon işlemi');
}

function callcenter_agent_queue_state(array $queues, array $queueEvents): array
{
    $allowed = array_fill_keys(array_map('strval', array_column($queues, 'id')), true);
    $states = [];
    foreach ($queues as $queue) {
        $id = (string) $queue['id'];
        $states[$id] = $queue + ['available' => 0, 'paused' => 0, 'busy' => 0,
            'unavailable' => 0, 'waiting' => 0, 'completed' => 0, 'abandoned' => 0,
            'members' => []];
    }
    $waiting = [];
    foreach ($queueEvents as $event) {
        $queueId = (string) ($event['queue'] ?? '');
        if (!isset($allowed[$queueId])) {
            continue;
        }
        $type = strtolower((string) ($event['event'] ?? ''));
        if ($type === 'queueparams') {
            $states[$queueId]['completed'] = (int) ($event['completed'] ?? 0);
            $states[$queueId]['abandoned'] = (int) ($event['abandoned'] ?? 0);
        } elseif ($type === 'queuemember') {
            $paused = in_array(strtolower((string) ($event['paused'] ?? '0')), ['1', 'true', 'yes'], true);
            $status = (int) ($event['status'] ?? 0);
            if ($paused) {
                $states[$queueId]['paused']++;
            } elseif ($status === 1) {
                $states[$queueId]['available']++;
            } elseif (in_array($status, [2, 3, 6, 7, 8], true)) {
                $states[$queueId]['busy']++;
            } else {
                $states[$queueId]['unavailable']++;
            }
            $interface = (string) (($event['location'] ?? '') ?: ($event['stateinterface'] ?? ''));
            $extension = '';
            if (preg_match('/(\d+)(?:@[^\/]+)?$/', $interface, $extensionMatch)) {
                $extension = (string) $extensionMatch[1];
            }
            $memberState = match ($status) {
                1 => 'available',
                2, 3 => 'busy',
                6, 7 => 'ringing',
                8 => 'hold',
                5 => 'unavailable',
                default => 'unknown',
            };
            $memberLabel = $paused ? 'Molada' : match ($memberState) {
                'available' => 'Müsait',
                'busy' => 'Görüşmede',
                'ringing' => 'Çalıyor',
                'hold' => 'Beklemede',
                'unavailable' => 'Çevrimdışı',
                default => 'Bilinmiyor',
            };
            $memberName = trim((string) ($event['name'] ?? ''));
            if ($memberName === '' || $memberName === $interface) {
                $memberName = $extension !== '' ? 'Agent ' . $extension : $interface;
            }
            $states[$queueId]['members'][] = [
                'extension' => $extension,
                'name' => $memberName,
                'interface' => $interface,
                'state' => $paused ? 'break' : $memberState,
                'label' => $memberLabel,
                'paused' => $paused,
                'pause_reason' => trim((string) ($event['pausedreason'] ?? '')),
                'calls_taken' => max(0, (int) ($event['callstaken'] ?? 0)),
            ];
        } elseif ($type === 'queueentry') {
            $states[$queueId]['waiting']++;
            $waiting[] = [
                'queue' => $queueId,
                'queue_name' => (string) ($states[$queueId]['name'] ?? $queueId),
                'caller' => (string) (($event['calleridnum'] ?? '') ?: 'Bilinmiyor'),
                'position' => max(1, (int) ($event['position'] ?? 1)),
                'wait_seconds' => max(0, (int) ($event['wait'] ?? 0)),
            ];
        }
    }
    foreach ($states as &$state) {
        usort($state['members'], static fn(array $left, array $right): int =>
            strnatcasecmp((string) ($left['extension'] ?? ''), (string) ($right['extension'] ?? ''))
        );
    }
    unset($state);
    return ['queues' => array_values($states), 'waiting_calls' => $waiting];
}

function callcenter_queue_daily_counts(array $queues): array
{
    if (!$queues) {
        return [];
    }
    $ids = array_map('strval', array_column($queues, 'id'));
    $literals = implode(',', array_map('callcenter_literal', $ids));
    $rows = callcenter_rows(
        "SELECT queuename, count(*) FILTER (WHERE event = 'CONNECT')::integer AS answered, "
        . "count(*) FILTER (WHERE event IN ('ABANDON','EXITWITHTIMEOUT','EXITWITHKEY'))::integer AS abandoned "
        . "FROM queue_log WHERE time >= CURRENT_DATE AND time < CURRENT_DATE + interval '1 day' "
        . "AND queuename IN ({$literals}) GROUP BY queuename"
    );
    return array_column($rows, null, 'queuename');
}

function callcenter_waiting_calls_from_log(array $queues): array
{
    if (!$queues) {
        return [];
    }
    $names = array_column($queues, 'name', 'id');
    $literals = implode(',', array_map('callcenter_literal', array_keys($names)));
    $rows = callcenter_rows(
        "SELECT q.queuename, q.callid, q.data2 AS caller, q.data3 AS position, "
        . "EXTRACT(EPOCH FROM (now() - q.time))::integer AS wait_seconds "
        . "FROM queue_log q WHERE q.queuename IN ({$literals}) AND q.event = 'ENTERQUEUE' "
        . "AND q.time >= now() - interval '6 hours' AND NOT EXISTS (SELECT 1 FROM queue_log t "
        . "WHERE t.queuename = q.queuename AND t.callid = q.callid AND t.time >= q.time "
        . "AND t.event IN ('CONNECT','ABANDON','EXITWITHTIMEOUT','EXITWITHKEY','COMPLETEAGENT','COMPLETECALLER','TRANSFER')) "
        . 'ORDER BY q.time ASC LIMIT 100'
    );
    return array_map(static fn(array $row): array => [
        'queue' => (string) $row['queuename'],
        'queue_name' => (string) ($names[(string) $row['queuename']] ?? $row['queuename']),
        'caller' => (string) (($row['caller'] ?? '') ?: 'Bilinmiyor'),
        'position' => max(1, (int) ($row['position'] ?? 1)),
        'wait_seconds' => max(0, (int) ($row['wait_seconds'] ?? 0)),
    ], $rows);
}

function callcenter_agent_snapshot(array $identity): array
{
    $dept = (string) $identity['dept_id'];
    $agent = (int) $identity['agent_id'];
    $extension = (string) $identity['extension'];
    $endpoint = callcenter_agent_endpoint($dept, $extension);
    $queues = callcenter_agent_queues($dept, $agent);
    $amiAvailable = true;
    try {
        $live = callcenter_ami_snapshot();
    } catch (Throwable $error) {
        error_log('Callcenter AMI snapshot failed: ' . $error->getMessage());
        $live = ['queue_events' => [], 'channel_events' => []];
        $amiAvailable = false;
    }
    $queueState = callcenter_agent_queue_state($queues, $live['queue_events']);
    $queueAgentRows = callcenter_rows(
        'SELECT a.extension,a.display_name,bt.name AS active_break '
        . 'FROM callcenter_agents a '
        . 'LEFT JOIN callcenter_breaks b ON b.dept_id=a.dept_id AND b.agent_id=a.agent_id AND b.ended_at IS NULL '
        . 'LEFT JOIN callcenter_break_types bt ON bt.dept_id=b.dept_id AND bt.break_type_id=b.break_type_id '
        . "WHERE a.dept_id=:dept AND a.status='active'",
        ['dept' => $dept]
    );
    $queueAgentByExtension = array_column($queueAgentRows, null, 'extension');
    $memberLiveCache = [];
    foreach ($queueState['queues'] as &$queue) {
        foreach ($queue['members'] as &$member) {
            $memberExtension = (string) ($member['extension'] ?? '');
            $agentDetails = $queueAgentByExtension[$memberExtension] ?? [];
            if (!empty($agentDetails['display_name'])) {
                $member['name'] = (string) $agentDetails['display_name'];
            }
            $member['break_name'] = (string) ($agentDetails['active_break'] ?? '');
            $member['peer'] = '';
            $member['call_age'] = 0;
            if (!empty($member['paused'])) {
                $member['state'] = 'break';
                $member['label'] = 'Molada';
                if ($member['break_name'] === '') {
                    $member['break_name'] = (string) (($member['pause_reason'] ?? '') ?: 'Mola');
                }
                continue;
            }
            $memberInterface = (string) ($member['interface'] ?? '');
            $memberEndpoint = str_starts_with($memberInterface, 'PJSIP/')
                ? substr($memberInterface, 6)
                : '';
            $cacheKey = $memberEndpoint . "\0" . $memberExtension;
            if (!isset($memberLiveCache[$cacheKey])) {
                $memberLiveCache[$cacheKey] = callcenter_agent_live_state(
                    $memberEndpoint,
                    $memberExtension,
                    $live['channel_events'],
                    $live['queue_events']
                );
            }
            $memberLive = $memberLiveCache[$cacheKey];
            if (in_array((string) ($memberLive['state'] ?? ''), ['talking', 'ringing'], true)) {
                $member['state'] = (string) $memberLive['state'];
                $member['label'] = (string) $memberLive['label'];
                $member['peer'] = (string) ($memberLive['peer'] ?? '');
                $member['call_age'] = max(0, (int) ($memberLive['call_age'] ?? 0));
            }
        }
        unset($member);
    }
    unset($queue);
    $ownedChannels = callcenter_owned_channels($endpoint, $live['channel_events']);
    $singleChannel = count($ownedChannels) === 1;
    $singleState = $singleChannel ? strtolower((string) ($ownedChannels[0]['channelstatedesc'] ?? '')) : '';
    $singleBridge = $singleChannel ? (string) ($ownedChannels[0]['bridgeid'] ?? '') : '';
    foreach (callcenter_queue_daily_counts($queues) as $queueId => $counts) {
        foreach ($queueState['queues'] as &$queue) {
            if ((string) $queue['id'] === (string) $queueId) {
                $queue['answered_today'] = (int) ($counts['answered'] ?? 0);
                $queue['abandoned_today'] = (int) ($counts['abandoned'] ?? 0);
            }
        }
        unset($queue);
    }
    if (!$queueState['waiting_calls']) {
        $queueState['waiting_calls'] = callcenter_waiting_calls_from_log($queues);
    }
    $extensionStatus = callcenter_agent_live_state(
        $endpoint,
        $extension,
        $live['channel_events'],
        $live['queue_events']
    );
    return [
        'agent' => $identity,
        'queues' => $queueState['queues'],
        'waiting_calls' => $queueState['waiting_calls'],
        'extension_status' => $extensionStatus,
        'break_types' => callcenter_break_types($dept),
        'active_break' => callcenter_active_break($dept, $agent),
        'stats' => callcenter_agent_daily_stats($dept, $agent, $extension, $endpoint),
        'recent_calls' => callcenter_recent_calls($dept, $extension, $endpoint),
        'ami_available' => $amiAvailable,
        'phone' => ['originate' => $endpoint !== '' && $amiAvailable && !$ownedChannels
                && ($extensionStatus['state'] ?? '') === 'available',
            'hangup' => $singleChannel,
            'transfer' => $singleChannel && ($singleState === 'up' || $singleBridge !== '')],
    ];
}

function callcenter_supervisor_snapshot(array $identity, bool $detailed = false): array
{
    $dept = (string) $identity['dept_id'];
    $ownerDept = (string) ($identity['owner_dept_id'] ?? $dept);
    $queueRows = $ownerDept === $dept
        ? callcenter_supervisor_queues($dept, (int) $identity['supervisor_id'])
        : array_values(callcenter_config_queues($dept));
    $queueIds = array_column($queueRows, 'id');
    $agentQueueFilter = ' AND FALSE';
    if ($queueIds) {
        $agentQueueLiterals = implode(',', array_map('callcenter_literal', $queueIds));
        $agentQueueFilter = ' AND EXISTS (SELECT 1 FROM callcenter_agent_queue_assignments aq '
            . 'WHERE aq.dept_id = a.dept_id AND aq.agent_id = a.agent_id AND aq.active '
            . "AND aq.queue_id IN ({$agentQueueLiterals}))";
    }
    $agents = callcenter_rows(
        "SELECT a.agent_id, a.display_name, a.extension, a.status, "
        . 'COALESCE(s.login_seconds, 0)::integer AS login_seconds, '
        . 'COALESCE(b.break_seconds, 0)::integer AS break_seconds, '
        . 'bt.name AS active_break, active_session.session_id AS active_session_id, '
        . "to_char(active_session.started_at,'DD.MM.YYYY HH24:MI:SS') AS active_session_started, "
        . '(active_session.session_id IS NOT NULL) AS is_logged_in, '
        . "COALESCE(aq.queue_names,'') AS queue_names "
        . 'FROM callcenter_agents a '
        . 'LEFT JOIN LATERAL (SELECT SUM(EXTRACT(EPOCH FROM (COALESCE(ended_at, now()) - '
        . "GREATEST(started_at, CURRENT_DATE::timestamptz)))) AS login_seconds "
        . 'FROM callcenter_agent_sessions WHERE dept_id = a.dept_id AND agent_id = a.agent_id '
        . "AND started_at < CURRENT_DATE + interval '1 day' AND COALESCE(ended_at, now()) >= CURRENT_DATE) s ON TRUE "
        . 'LEFT JOIN LATERAL (SELECT SUM(EXTRACT(EPOCH FROM (COALESCE(ended_at, now()) - '
        . "GREATEST(started_at, CURRENT_DATE::timestamptz)))) AS break_seconds "
        . 'FROM callcenter_breaks WHERE dept_id = a.dept_id AND agent_id = a.agent_id '
        . "AND started_at < CURRENT_DATE + interval '1 day' AND COALESCE(ended_at, now()) >= CURRENT_DATE) b ON TRUE "
        . 'LEFT JOIN callcenter_breaks ab ON ab.dept_id = a.dept_id AND ab.agent_id = a.agent_id AND ab.ended_at IS NULL '
        . 'LEFT JOIN callcenter_break_types bt ON bt.dept_id = ab.dept_id AND bt.break_type_id = ab.break_type_id '
        . 'LEFT JOIN LATERAL (SELECT session_id,started_at FROM callcenter_agent_sessions '
        . 'WHERE dept_id=a.dept_id AND agent_id=a.agent_id AND ended_at IS NULL '
        . 'ORDER BY started_at DESC,session_id DESC LIMIT 1) active_session ON TRUE '
        . "LEFT JOIN LATERAL (SELECT string_agg(queue_id,', ' ORDER BY queue_id) AS queue_names "
        . 'FROM callcenter_agent_queue_assignments WHERE dept_id=a.dept_id AND agent_id=a.agent_id '
        . 'AND active AND queue_id IN (' . ($queueIds ? $agentQueueLiterals : "''") . ')) aq ON TRUE '
        . "WHERE a.dept_id = :dept AND a.status = 'active'{$agentQueueFilter} ORDER BY a.display_name",
        ['dept' => $dept]
    );
    $liveByExtension = [];
    $liveExtensions = [];
    $liveAvailable = true;
    try {
        $liveSnapshot = pbx_blf_status($dept);
        foreach ((array) ($liveSnapshot['extensions'] ?? []) as $liveStatus) {
            $extension = (string) ($liveStatus['extension'] ?? '');
            if ($extension !== '') {
                $liveByExtension[$extension] = $liveStatus;
                $liveExtensions[] = $liveStatus;
            }
        }
    } catch (Throwable) {
        $liveAvailable = false;
    }
    foreach ($agents as &$agent) {
        $extension = (string) $agent['extension'];
        $agent['is_logged_in'] = in_array(
            strtolower((string) ($agent['is_logged_in'] ?? '')),
            ['1', 't', 'true'],
            true
        );
        $agent['live'] = $liveByExtension[$extension]
            ?? ['extension' => $extension, 'state' => 'unknown', 'label' => 'Bilinmiyor'];
        $agent['activity_state'] = !$agent['is_logged_in']
            ? 'offline'
            : (!empty($agent['active_break']) ? 'break' : 'available');
    }
    unset($agent);
    $activeAgentCount = count(array_filter(
        $agents,
        static fn(array $agent): bool => !empty($agent['is_logged_in'])
    ));
    $activeBreakCount = count(array_filter(
        $agents,
        static fn(array $agent): bool => !empty($agent['is_logged_in']) && !empty($agent['active_break'])
    ));
    $events = [];
    $queueStats = [];
    if ($queueIds) {
        $literals = implode(',', array_map('callcenter_literal', $queueIds));
        $events = callcenter_rows(
            "SELECT to_char(time, 'YYYY-MM-DD HH24:MI:SS') AS time, queuename, agent, event, data1 "
            . "FROM queue_log WHERE queuename IN ({$literals}) ORDER BY time DESC, id DESC LIMIT 30"
        );
        $queueStats = callcenter_rows(
            "SELECT queuename, count(*) FILTER (WHERE event = 'ENTERQUEUE')::integer AS offered, "
            . "count(*) FILTER (WHERE event = 'CONNECT')::integer AS answered, "
            . "count(*) FILTER (WHERE event IN ('ABANDON','EXITWITHTIMEOUT'))::integer AS missed "
            . "FROM queue_log WHERE time >= CURRENT_DATE AND queuename IN ({$literals}) "
            . 'GROUP BY queuename ORDER BY queuename'
        );
    }

    $canSeePhone = callcenter_supervisor_has_permission($identity, 'phone_unmasked');
    $agentByExtension = [];
    foreach ($agents as $agent) {
        $agentByExtension[(string) $agent['extension']] = $agent;
    }
    foreach ($liveExtensions as &$extensionState) {
        $extension = (string) ($extensionState['extension'] ?? '');
        $peer = (string) ($extensionState['peer'] ?? '');
        if (!$canSeePhone && strlen(preg_replace('/\D+/', '', $peer) ?? '') > 6) {
            $digits = preg_replace('/\D+/', '', $peer) ?? '';
            $extensionState['peer'] = substr($digits, 0, 3)
                . str_repeat('*', max(3, strlen($digits) - 7))
                . substr($digits, -4);
        }
        $agent = $agentByExtension[$extension] ?? null;
        $extensionState['agent_id'] = (int) ($agent['agent_id'] ?? 0);
        $extensionState['agent_name'] = (string) ($agent['display_name'] ?? '');
        $extensionState['is_logged_in'] = !empty($agent['is_logged_in']);
        $extensionState['active_break'] = (string) ($agent['active_break'] ?? '');
        $extensionState['login_seconds'] = (int) ($agent['login_seconds'] ?? 0);
        $extensionState['break_seconds'] = (int) ($agent['break_seconds'] ?? 0);
    }
    unset($extensionState);
    usort($liveExtensions, static fn(array $left, array $right): int =>
        strnatcasecmp((string) ($left['extension'] ?? ''), (string) ($right['extension'] ?? ''))
    );

    $queueLive = ['queues' => array_map(
        static fn(array $queue): array => $queue + [
            'available' => 0, 'paused' => 0, 'busy' => 0, 'unavailable' => 0,
            'waiting' => 0, 'completed' => 0, 'abandoned' => 0, 'members' => [],
        ],
        $queueRows
    ), 'waiting_calls' => []];
    if ($detailed) {
    try {
        $amiSnapshot = callcenter_ami_snapshot();
        $queueLive = callcenter_agent_queue_state($queueRows, (array) $amiSnapshot['queue_events']);
        $allowedQueues = array_fill_keys(array_map('strval', $queueIds), true);
        $sipToExtension = [];
        foreach (store_read('extensions') as $extensionRow) {
            if ((string) ($extensionRow['dept'] ?? '') !== $dept) {
                continue;
            }
            $sip = sip_user($extensionRow);
            if ($sip !== '') {
                $sipToExtension[$sip] = (string) ($extensionRow['exten'] ?? '');
            }
        }
        $membersByQueue = [];
        foreach ((array) $amiSnapshot['queue_events'] as $event) {
            if (strtolower((string) ($event['event'] ?? '')) !== 'queuemember') {
                continue;
            }
            $queueId = (string) ($event['queue'] ?? '');
            if (!isset($allowedQueues[$queueId])) {
                continue;
            }
            $interface = (string) (($event['location'] ?? '') ?: ($event['stateinterface'] ?? ''));
            $endpoint = str_starts_with($interface, 'PJSIP/') ? substr($interface, 6) : '';
            $extension = $sipToExtension[$endpoint] ?? '';
            if ($extension === '' && preg_match('/(\d+)$/', $endpoint, $extensionMatch)) {
                $extension = (string) $extensionMatch[1];
            }
            $agent = $agentByExtension[$extension] ?? null;
            $statusCode = (int) ($event['status'] ?? 0);
            $paused = in_array(strtolower((string) ($event['paused'] ?? '0')), ['1', 'true', 'yes'], true);
            $status = match ($statusCode) {
                1 => 'available',
                2, 3 => 'busy',
                6, 7 => 'ringing',
                8 => 'hold',
                5 => 'unavailable',
                default => 'unknown',
            };
            $statusLabel = match ($status) {
                'available' => 'Müsait',
                'busy' => 'Görüşmede',
                'ringing' => 'Çalıyor',
                'hold' => 'Beklemede',
                'unavailable' => 'Kullanılamaz',
                default => 'Bilinmiyor',
            };
            $membersByQueue[$queueId][] = [
                'extension' => $extension,
                'name' => (string) ($agent['display_name'] ?? ($event['name'] ?? $extension)),
                'interface' => $interface,
                'status' => $status,
                'status_label' => $statusLabel,
                'paused' => $paused,
                'calls_taken' => (int) ($event['callstaken'] ?? 0),
                'last_call_seconds' => max(0, (int) ($event['lastcall'] ?? 0)),
                'active_break' => (string) ($agent['active_break'] ?? ''),
                'login_seconds' => (int) ($agent['login_seconds'] ?? 0),
                'break_seconds' => (int) ($agent['break_seconds'] ?? 0),
            ];
        }
        foreach ($queueLive['queues'] as &$queueState) {
            $queueId = (string) ($queueState['id'] ?? '');
            $queueState['members'] = $membersByQueue[$queueId] ?? [];
        }
        unset($queueState);
    } catch (Throwable $error) {
        $liveAvailable = false;
        error_log('Supervisor live queue snapshot failed: ' . $error->getMessage());
    }
    if (!$queueLive['waiting_calls']) {
        $queueLive['waiting_calls'] = callcenter_waiting_calls_from_log($queueRows);
        foreach ($queueLive['waiting_calls'] as $waitingCall) {
            foreach ($queueLive['queues'] as &$queueState) {
                if ((string) ($queueState['id'] ?? '') === (string) $waitingCall['queue']) {
                    $queueState['waiting'] = (int) ($queueState['waiting'] ?? 0) + 1;
                }
            }
            unset($queueState);
        }
    }
    $dailyByQueue = array_column($queueStats, null, 'queuename');
    foreach ($queueLive['queues'] as &$queueState) {
        $queueId = (string) ($queueState['id'] ?? '');
        $daily = $dailyByQueue[$queueId] ?? [];
        $queueState['offered_today'] = (int) ($daily['offered'] ?? 0);
        $queueState['answered_today'] = (int) ($daily['answered'] ?? 0);
        $queueState['missed_today'] = (int) ($daily['missed'] ?? 0);
        $queueState['waiting_calls'] = array_values(array_filter(
            $queueLive['waiting_calls'],
            static fn(array $call): bool => (string) ($call['queue'] ?? '') === $queueId
        ));
    }
    unset($queueState);
    }

    return [
        'supervisor' => $identity,
        'queues' => $queueRows,
        'agents' => $agents,
        'active_agent_count' => $activeAgentCount,
        'available_agent_count' => max(0, $activeAgentCount - $activeBreakCount),
        'active_break_count' => $activeBreakCount,
        'queue_stats' => $queueStats,
        'queue_live' => $queueLive['queues'],
        'waiting_calls' => $queueLive['waiting_calls'],
        'extensions' => $liveExtensions,
        'live_available' => $liveAvailable,
        'recent_events' => $events,
        'generated_at' => date(DATE_ATOM),
    ];
}
