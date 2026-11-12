<?php
declare(strict_types=1);

function ami_permission_names(): array
{
    return [
        'system', 'call', 'log', 'verbose', 'command', 'agent', 'user', 'config',
        'dtmf', 'reporting', 'cdr', 'dialplan', 'originate', 'message',
    ];
}

function ami_normalize_network(string $value): string
{
    $value = trim($value);
    if (preg_match('~^([^/]+)/([^/]+)$~D', $value, $match) !== 1) {
        return '';
    }
    $ip = filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    if ($ip === false) {
        return '';
    }
    $mask = $match[2];
    if (ctype_digit($mask)) {
        $prefix = (int) $mask;
    } else {
        $maskLong = ip2long($mask);
        if ($maskLong === false) {
            return '';
        }
        $bits = str_pad(decbin($maskLong & 0xffffffff), 32, '0', STR_PAD_LEFT);
        if (preg_match('/^1*0*$/D', $bits) !== 1) {
            return '';
        }
        $prefix = substr_count($bits, '1');
    }
    if ($prefix < 0 || $prefix > 32) {
        return '';
    }
    $ipLong = ip2long((string) $ip);
    $networkLong = $prefix === 0 ? 0 : ($ipLong & (-1 << (32 - $prefix)));
    return long2ip($networkLong) . '/' . $prefix;
}

function ami_acl_display(string $cidr): string
{
    [$ip, $prefixText] = array_pad(explode('/', $cidr, 2), 2, '32');
    $prefix = max(0, min(32, (int) $prefixText));
    $mask = $prefix === 0 ? 0 : ((0xffffffff << (32 - $prefix)) & 0xffffffff);
    return $ip . '/' . long2ip($mask);
}

function ami_managed_users(): array
{
    $pdo = pbx_relational_pdo();
    if (!$pdo) {
        throw new RuntimeException('AMI veritabanı bağlantısı kurulamadı');
    }
    $rows = $pdo->query(
        'SELECT manager_name,writetimeout,created_at,updated_at,updated_by '
        . 'FROM ami_managers ORDER BY lower(manager_name),manager_name'
    )->fetchAll();
    $acl = $pdo->query(
        'SELECT manager_name,position,action,network::text AS network '
        . 'FROM ami_manager_acl ORDER BY manager_name,position'
    )->fetchAll();
    $permissions = $pdo->query(
        'SELECT manager_name,permission_name,can_read,can_write '
        . 'FROM ami_manager_permissions ORDER BY manager_name,permission_name'
    )->fetchAll();
    $byName = [];
    foreach ($rows as $row) {
        $name = (string) $row['manager_name'];
        $row['acl'] = [];
        $row['read_permissions'] = [];
        $row['write_permissions'] = [];
        $row['managed'] = true;
        $row['protected'] = false;
        $byName[$name] = $row;
    }
    foreach ($acl as $rule) {
        $name = (string) $rule['manager_name'];
        if (isset($byName[$name])) {
            $byName[$name]['acl'][] = [
                'action' => (string) $rule['action'],
                'network' => (string) $rule['network'],
            ];
        }
    }
    foreach ($permissions as $permission) {
        $name = (string) $permission['manager_name'];
        if (!isset($byName[$name])) continue;
        $canRead = in_array(strtolower((string) $permission['can_read']), ['1', 't', 'true', 'yes', 'on'], true);
        $canWrite = in_array(strtolower((string) $permission['can_write']), ['1', 't', 'true', 'yes', 'on'], true);
        if ($canRead) {
            $byName[$name]['read_permissions'][] = (string) $permission['permission_name'];
        }
        if ($canWrite) {
            $byName[$name]['write_permissions'][] = (string) $permission['permission_name'];
        }
    }
    return array_values($byName);
}

function ami_runtime_user_names(): array
{
    $result = pbx_cli('manager show users');
    $users = [];
    foreach (preg_split('/\r?\n/', $result) as $line) {
        $line = trim($line);
        if (
            $line === ''
            || $line === 'username'
            || preg_match('/^-+$/D', $line) === 1
            || str_contains($line, 'manager users configured')
        ) {
            continue;
        }
        if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $line) === 1) {
            $users[] = $line;
        }
    }
    return array_values(array_unique($users));
}

function ami_users_for_page(): array
{
    $managed = ami_managed_users();
    $byName = [];
    foreach ($managed as $row) {
        $byName[(string) $row['manager_name']] = $row;
    }
    foreach (ami_runtime_user_names() as $name) {
        if (!isset($byName[$name])) {
            $byName[$name] = [
                'manager_name' => $name,
                'writetimeout' => 0,
                'acl' => [],
                'read_permissions' => [],
                'write_permissions' => [],
                'managed' => false,
                'protected' => $name === AMI_USER || $name === 'asterisk22web',
            ];
        }
    }
    uasort($byName, static fn(array $a, array $b): int =>
        strcasecmp((string) $a['manager_name'], (string) $b['manager_name'])
    );
    return array_values($byName);
}

function ami_apply_managed_users(): array
{
    $blocks = ['; ASTERA panelinden yönetilen AMI kullanıcıları'];
    foreach (ami_managed_users() as $user) {
        $name = (string) $user['manager_name'];
        $pdo = pbx_relational_pdo();
        $secretStmt = $pdo->prepare('SELECT secret FROM ami_managers WHERE manager_name=:name');
        $secretStmt->execute(['name' => $name]);
        $secret = (string) $secretStmt->fetchColumn();
        $blocks[] = '';
        $blocks[] = '[' . $name . ']';
        $blocks[] = 'secret = ' . $secret;
        foreach ($user['acl'] as $rule) {
            $blocks[] = $rule['action'] . ' = ' . ami_acl_display((string) $rule['network']);
        }
        $blocks[] = 'read = ' . implode(',', $user['read_permissions']);
        $blocks[] = 'write = ' . implode(',', $user['write_permissions']);
        $blocks[] = 'writetimeout = ' . (int) $user['writetimeout'];
    }
    $contents = implode("\n", $blocks) . "\n";
    $encoded = base64_encode($contents);
    $result = pbx_ssh(
        'printf %s ' . escapeshellarg($encoded)
        . ' | base64 -d > /tmp/astera-ami-managers.conf' . "\n"
        . 'install -m 0640 -o root -g asterisk /tmp/astera-ami-managers.conf '
        . '/etc/asterisk/manager_astera_managed.conf' . "\n"
        . 'rm -f /tmp/astera-ami-managers.conf' . "\n"
        . "grep -Fqx '#include manager_astera_managed.conf' /etc/asterisk/manager.conf "
        . "|| printf '\\n#include manager_astera_managed.conf\\n' >> /etc/asterisk/manager.conf\n"
        . "asterisk -rx 'manager reload'"
    );
    if (empty($result['ok'])) {
        throw new RuntimeException('AMI kullanıcıları uygulanamadı: ' . (string) ($result['output'] ?? ''));
    }
    return $result;
}

function ami_save_user(array $input): void
{
    $name = trim((string) ($input['manager_name'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $name) !== 1) {
        throw new InvalidArgumentException('AMI kullanıcı adı geçersiz');
    }
    if ($name === AMI_USER || $name === 'asterisk22web') {
        throw new InvalidArgumentException('Panelin sistem AMI kullanıcısı değiştirilemez');
    }
    $pdo = pbx_relational_pdo();
    $existingStmt = $pdo->prepare('SELECT secret FROM ami_managers WHERE manager_name=:name');
    $existingStmt->execute(['name' => $name]);
    $existingSecret = $existingStmt->fetchColumn();
    if ($existingSecret === false && in_array($name, ami_runtime_user_names(), true)) {
        throw new InvalidArgumentException('Bu kullanıcı manager.conf içinde sistem tarafından yönetiliyor');
    }
    $secret = (string) ($input['secret'] ?? '');
    if ($secret === '' && $existingSecret !== false) {
        $secret = (string) $existingSecret;
    }
    if (strlen($secret) < 8 || strlen($secret) > 255 || preg_match('/[\r\n]/', $secret)) {
        throw new InvalidArgumentException('AMI parolası 8-255 karakter olmalıdır');
    }
    $acl = [];
    foreach (['deny', 'permit'] as $action) {
        $values = preg_split('/[\s,;]+/', trim((string) ($input[$action] ?? ''))) ?: [];
        foreach ($values as $value) {
            if ($value === '') continue;
            $network = ami_normalize_network($value);
            if ($network === '') {
                throw new InvalidArgumentException(strtoupper($action) . ' ağı geçersiz: ' . $value);
            }
            $acl[] = ['action' => $action, 'network' => $network];
        }
    }
    if (!$acl) {
        $acl = [
            ['action' => 'deny', 'network' => '0.0.0.0/0'],
            ['action' => 'permit', 'network' => '127.0.0.1/32'],
        ];
    }
    $allowed = array_flip(ami_permission_names());
    $read = array_values(array_intersect(array_keys($allowed), array_map('strval', (array) ($input['read_permissions'] ?? []))));
    $write = array_values(array_intersect(array_keys($allowed), array_map('strval', (array) ($input['write_permissions'] ?? []))));
    if (!$read && !$write) {
        throw new InvalidArgumentException('En az bir Read veya Write yetkisi seçin');
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO ami_managers (manager_name,secret,writetimeout,updated_by) '
            . 'VALUES (:name,:secret,:timeout,:actor) ON CONFLICT (manager_name) DO UPDATE SET '
            . 'secret=EXCLUDED.secret,writetimeout=EXCLUDED.writetimeout,updated_at=now(),updated_by=EXCLUDED.updated_by'
        );
        $stmt->execute([
            'name' => $name,
            'secret' => $secret,
            'timeout' => max(100, min(60000, (int) ($input['writetimeout'] ?? 5000))),
            'actor' => store_actor(),
        ]);
        $pdo->prepare('DELETE FROM ami_manager_acl WHERE manager_name=:name')->execute(['name' => $name]);
        $aclStmt = $pdo->prepare(
            'INSERT INTO ami_manager_acl (manager_name,position,action,network) '
            . 'VALUES (:name,:position,:action,CAST(:network AS cidr))'
        );
        foreach ($acl as $position => $rule) {
            $aclStmt->execute(['name' => $name, 'position' => $position, 'action' => $rule['action'], 'network' => $rule['network']]);
        }
        $pdo->prepare('DELETE FROM ami_manager_permissions WHERE manager_name=:name')->execute(['name' => $name]);
        $permissionStmt = $pdo->prepare(
            'INSERT INTO ami_manager_permissions (manager_name,permission_name,can_read,can_write) '
            . 'VALUES (:name,:permission,:read,:write)'
        );
        foreach (ami_permission_names() as $permission) {
            $canRead = in_array($permission, $read, true);
            $canWrite = in_array($permission, $write, true);
            if (!$canRead && !$canWrite) continue;
            $permissionStmt->execute([
                'name' => $name,
                'permission' => $permission,
                'read' => $canRead ? 'true' : 'false',
                'write' => $canWrite ? 'true' : 'false',
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    ami_apply_managed_users();
}

function ami_delete_user(string $name): void
{
    if ($name === AMI_USER || $name === 'asterisk22web') {
        throw new InvalidArgumentException('Panelin sistem AMI kullanıcısı silinemez');
    }
    $pdo = pbx_relational_pdo();
    $stmt = $pdo->prepare('DELETE FROM ami_managers WHERE manager_name=:name');
    $stmt->execute(['name' => $name]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Yalnız panelden eklenen AMI kullanıcıları silinebilir');
    }
    ami_apply_managed_users();
}
