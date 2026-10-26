<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$action = (string) ($_POST['action'] ?? '');
$webphoneCrmAccess = false;
if (empty($_SESSION['user']) && str_starts_with($action, 'crm_')) {
    $webphoneId = (string) ($_SESSION['webphone_extension_id'] ?? '');
    $softphoneId = (string) ($_SESSION['softphone_crm_extension_id'] ?? '');
    $webphoneExtension = $webphoneId !== ''
        ? find_by('extensions', 'id', $webphoneId)
        : ($softphoneId !== '' ? find_by('extensions', 'id', $softphoneId) : null);
    $softphoneAccess = $webphoneId === ''
        && softphone_crm_extension_allowed($webphoneExtension);
    if (
        $webphoneExtension
        && (!empty($webphoneExtension['webrtc']) || $softphoneAccess)
    ) {
        $_SESSION['user_dept'] = (string) ($webphoneExtension['dept'] ?? '');
        $_SESSION['username'] = ($softphoneAccess ? 'Softphone ' : 'WebPhone ')
            . (string) ($webphoneExtension['exten'] ?? '');
        $webphoneCrmAccess = $_SESSION['user_dept'] !== '';
    }
}
if (!$webphoneCrmAccess) {
    require_login();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST gerekli'], 405);
}
if (!csrf_ok()) {
    json_out(['ok' => false, 'error' => 'Güvenlik doğrulaması başarısız'], 403);
}

function security_networks(string|array $input): array
{
    $items = is_array($input) ? $input : preg_split('/[\s,;]+/', $input);
    $networks = [];
    foreach ((array) $items as $item) {
        $item = trim((string) $item);
        if ($item === '') {
            continue;
        }
        [$ip, $prefix] = array_pad(explode('/', $item, 2), 2, '32');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || !ctype_digit($prefix) || (int) $prefix < 0 || (int) $prefix > 32) {
            json_out(['ok' => false, 'error' => 'Geçersiz IPv4/CIDR: ' . $item], 422);
        }
        $normalized = $ip . '/' . (int) $prefix;
        if ($normalized === '0.0.0.0/0') {
            json_out(['ok' => false, 'error' => 'Tüm interneti kapsayan 0.0.0.0/0 kuralına izin verilmez'], 422);
        }
        $networks[$normalized] = true;
    }
    return array_keys($networks);
}

function network_posted_setting(): array
{
    if (!is_super()) {
        json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
    }
    $interface = network_interface_name((string) ($_POST['interface_name'] ?? ''));
    $inventory = network_inventory();
    $live = null;
    foreach ($inventory as $row) {
        if ((string) ($row['interface_name'] ?? '') === $interface) {
            $live = $row;
            break;
        }
    }
    if ($interface === '' || !$live) {
        json_out(['ok' => false, 'error' => 'Ethernet arayüzü bulunamadı'], 422);
    }
    $dept = require_dept_id();
    $method = (string) ($_POST['method'] ?? 'dhcp');
    if (!in_array($method, ['dhcp', 'manual'], true)) {
        json_out(['ok' => false, 'error' => 'Geçersiz IPv4 yöntemi'], 422);
    }
    $cidr = trim((string) ($_POST['ipv4_cidr'] ?? ''));
    $ip = '';
    $prefix = '';
    if ($method === 'manual') {
        [$ip, $prefix] = array_pad(explode('/', $cidr, 2), 2, '');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || !ctype_digit($prefix) || (int) $prefix < 1 || (int) $prefix > 32) {
            json_out(['ok' => false, 'error' => 'Statik IPv4 adresini 192.168.181.74/24 biçiminde girin'], 422);
        }
        $cidr = $ip . '/' . (int) $prefix;
    } else {
        $cidr = '';
    }
    $gateway = trim((string) ($_POST['gateway'] ?? ''));
    if ($gateway !== '' && filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        json_out(['ok' => false, 'error' => 'Geçersiz IPv4 ağ geçidi'], 422);
    }
    if ($method === 'manual' && $gateway !== '') {
        $mask = (0xFFFFFFFF << (32 - (int) $prefix)) & 0xFFFFFFFF;
        if ((ip2long($ip) & $mask) !== (ip2long($gateway) & $mask)) {
            json_out(['ok' => false, 'error' => 'IPv4 adresi ile ağ geçidi aynı ağda olmalıdır'], 422);
        }
    }
    if ($method === 'manual') {
        foreach ($inventory as $otherInterface) {
            if ((string) ($otherInterface['interface_name'] ?? '') === $interface) continue;
            $otherIp = explode('/', (string) ($otherInterface['ipv4_cidr'] ?? ''), 2)[0];
            if ($otherIp !== '' && $otherIp === $ip) {
                json_out([
                    'ok' => false,
                    'error' => 'Bu IPv4 adresi ' . (string) $otherInterface['interface_name']
                        . ' Ethernet kartında zaten kullanılıyor',
                ], 409);
            }
        }
        foreach (network_settings() as $otherSetting) {
            if ((string) ($otherSetting['interface_name'] ?? '') === $interface) continue;
            $otherIp = explode('/', (string) ($otherSetting['ipv4_cidr'] ?? ''), 2)[0];
            if ($otherIp !== '' && $otherIp === $ip) {
                json_out([
                    'ok' => false,
                    'error' => 'Bu IPv4 adresi ' . (string) $otherSetting['interface_name']
                        . ' için kayıtlı durumda',
                ], 409);
            }
        }
    }
    $dns = [];
    foreach (preg_split('/[\s,;]+/', trim((string) ($_POST['dns_servers'] ?? ''))) as $server) {
        if ($server === '') {
            continue;
        }
        if (filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            json_out(['ok' => false, 'error' => 'Geçersiz DNS sunucusu: ' . $server], 422);
        }
        $dns[$server] = true;
    }
    $mtu = (int) ($_POST['mtu'] ?? 1500);
    if ($mtu < 576 || $mtu > 9000) {
        json_out(['ok' => false, 'error' => 'MTU 576 ile 9000 arasında olmalıdır'], 422);
    }
    return [
        'interface_name' => $interface,
        'dept_id' => $dept,
        'method' => $method,
        'ipv4_cidr' => $cidr,
        'gateway' => $gateway,
        'dns_servers' => implode(',', array_keys($dns)),
        'mtu' => $mtu,
        'connection_uuid' => (string) ($live['connection_uuid'] ?? ''),
        'rollback_unit' => '',
        'backup_connection' => '',
        'confirmed' => true,
        'applied_at' => '',
    ];
}

function network_posted_route(): array
{
    if (!is_super()) {
        json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
    }
    $dept = require_dept_id();
    $interface = network_interface_name((string) ($_POST['interface_name'] ?? ''));
    $liveInterface = null;
    foreach (network_inventory() as $inventoryRow) {
        if ((string) ($inventoryRow['interface_name'] ?? '') === $interface) {
            $liveInterface = $inventoryRow;
            break;
        }
    }
    if ($interface === '' || !$liveInterface) {
        json_out(['ok' => false, 'error' => 'Ethernet arayüzü bulunamadı'], 422);
    }
    $destination = trim((string) ($_POST['destination'] ?? ''));
    [$network, $prefix] = array_pad(explode('/', $destination, 2), 2, '32');
    if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || !ctype_digit($prefix) || (int) $prefix < 0 || (int) $prefix > 32) {
        json_out([
            'ok' => false,
            'error' => 'Hedefi 10.0.10.10 veya 10.20.0.0/16 biçiminde girin',
        ], 422);
    }
    $destination = $network . '/' . (int) $prefix;
    if ($destination === '0.0.0.0/0') {
        json_out([
            'ok' => false,
            'error' => 'Varsayılan rota Ethernet kartının ağ geçidi alanından yönetilmelidir',
        ], 422);
    }
    $gateway = trim((string) ($_POST['gateway'] ?? ''));
    if (filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        json_out(['ok' => false, 'error' => 'Geçerli bir IPv4 ağ geçidi girin'], 422);
    }
    $metric = (int) ($_POST['metric'] ?? 100);
    if ($metric < 0 || $metric > 65535) {
        json_out(['ok' => false, 'error' => 'Metric 0 ile 65535 arasında olmalıdır'], 422);
    }
    $description = trim((string) ($_POST['description'] ?? ''));
    if (mb_strlen($description) > 160) {
        json_out(['ok' => false, 'error' => 'Rota açıklaması en fazla 160 karakter olabilir'], 422);
    }
    $gatewayCheck = pbx_ssh(
        'ip -4 route get ' . escapeshellarg($gateway)
        . ' oif ' . escapeshellarg($interface) . ' >/dev/null'
    );
    $liveCidr = (string) ($liveInterface['ipv4_cidr'] ?? '');
    $liveAddress = explode('/', $liveCidr, 2)[0] ?? '';
    $canApply = $liveCidr !== ''
        && ($gateway === $liveAddress || !empty($gatewayCheck['ok']));
    return [
        'dept_id' => $dept,
        'interface_name' => $interface,
        'destination' => $destination,
        'gateway' => $gateway,
        'metric' => $metric,
        'description' => $description,
        'enabled' => $canApply,
    ];
}

function asterisk_log_files(): array
{
    $result = pbx_ssh(
        "find /var/log/asterisk -maxdepth 1 -type f "
        . "-printf '%f\\t%s\\t%T@\\n' 2>/dev/null | sort"
    );
    $files = [];
    foreach (preg_split('/\r?\n/', trim((string) ($result['output'] ?? ''))) as $line) {
        if ($line === '') {
            continue;
        }
        [$name, $size, $modified] = array_pad(explode("\t", $line, 3), 3, '');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $name) !== 1) {
            continue;
        }
        $files[$name] = [
            'name' => $name,
            'size' => max(0, (int) $size),
            'modified' => max(0, (int) (float) $modified),
        ];
    }
    return array_values($files);
}

function pri_channel_numbers(string $spec): array
{
    $spec = preg_replace('/\s+/', '', $spec) ?? '';
    if ($spec === '' || preg_match('/^\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*$/D', $spec) !== 1) {
        json_out(['ok' => false, 'error' => 'B-kanalları 1-15,17-31 biçiminde olmalıdır'], 422);
    }
    $channels = [];
    foreach (explode(',', $spec) as $part) {
        [$first, $last] = array_pad(explode('-', $part, 2), 2, $part);
        $first = (int) $first;
        $last = (int) $last;
        if ($first < 1 || $last < $first || $last > 1024 || ($last - $first) > 255) {
            json_out(['ok' => false, 'error' => 'Geçersiz DAHDI kanal aralığı: ' . $part], 422);
        }
        for ($channel = $first; $channel <= $last; $channel++) {
            $channels[$channel] = true;
        }
    }
    return array_keys($channels);
}

function pri_detected_spans(string $output): array
{
    preg_match_all(
        '/^\[(\d+)\]\R(.*?)(?=^\[\d+\]\R|^--- Asterisk DAHDI ---|\z)/ms',
        $output,
        $matches,
        PREG_SET_ORDER
    );
    $spans = [];
    foreach ($matches as $match) {
        $span = ['span' => (int) $match[1]];
        foreach (preg_split('/\R/', trim((string) $match[2])) as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (in_array($key, ['basechan', 'totchans', 'syncsrc'], true)) {
                $span[$key] = (int) $value;
            } elseif (in_array($key, ['active', 'alarms', 'description', 'name', 'devicetype', 'type'], true)) {
                $span[$key] = $value;
            }
        }
        $spans[] = $span;
    }
    return $spans;
}

try {
    switch ($action) {
        case 'ami_user_save':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            try {
                ami_save_user($_POST);
            } catch (InvalidArgumentException $exception) {
                json_out(['ok' => false, 'error' => $exception->getMessage()], 422);
            }
            json_out(['ok' => true, 'message' => 'AMI kullanıcısı kaydedildi ve Asterisk’e uygulandı']);

        case 'ami_user_delete':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            try {
                ami_delete_user(trim((string) ($_POST['manager_name'] ?? '')));
            } catch (InvalidArgumentException $exception) {
                json_out(['ok' => false, 'error' => $exception->getMessage()], 422);
            }
            json_out(['ok' => true, 'message' => 'AMI kullanıcısı silindi']);

        case 'integration_fidelio_save':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            require_once __DIR__ . '/includes/integrations.php';
            $dept = require_dept_id();
            $name = trim((string) ($_POST['name'] ?? 'Fidelio PMS'));
            $mode = (string) ($_POST['connection_mode'] ?? 'client');
            $host = trim((string) ($_POST['remote_host'] ?? ''));
            $bindAddress = trim((string) ($_POST['bind_address'] ?? '0.0.0.0'));
            $port = (int) ($_POST['port'] ?? 5001);
            $reconnect = (int) ($_POST['reconnect_seconds'] ?? 10);
            $timeout = (int) ($_POST['connect_timeout_seconds'] ?? 10);
            $terminator = (string) ($_POST['message_terminator'] ?? 'CRLF');
            $charset = (string) ($_POST['charset'] ?? 'UTF-8');
            if ($name === '' || strlen($name) > 160) {
                json_out(['ok' => false, 'error' => 'Entegrasyon adı zorunludur'], 422);
            }
            if (!in_array($mode, ['client', 'server'], true)) {
                json_out(['ok' => false, 'error' => 'Bağlantı modu geçersiz'], 422);
            }
            if ($mode === 'client' && ($host === '' || strlen($host) > 255 || preg_match('/^[A-Za-z0-9._:-]+$/', $host) !== 1)) {
                json_out(['ok' => false, 'error' => 'Geçerli Fidelio sunucu adresi girin'], 422);
            }
            if (filter_var($bindAddress, FILTER_VALIDATE_IP) === false) {
                json_out(['ok' => false, 'error' => 'Dinleme IP adresi geçersiz'], 422);
            }
            if ($port < 1 || $port > 65535) {
                json_out(['ok' => false, 'error' => 'Port 1-65535 arasında olmalıdır'], 422);
            }
            if (!in_array($terminator, ['CRLF', 'LF', 'ETX'], true)
                || !in_array($charset, ['UTF-8', 'ISO-8859-9', 'WINDOWS-1254'], true)) {
                json_out(['ok' => false, 'error' => 'FIAS metin ayarı geçersiz'], 422);
            }
            $existing = integration_fidelio_connector($dept);
            $pdo = callcenter_pdo();
            if (!$pdo) {
                json_out(['ok' => false, 'error' => 'PostgreSQL bağlantısı gerekli'], 503);
            }
            $pdo->beginTransaction();
            try {
                if ($existing) {
                    $connectorId = (int) $existing['connector_id'];
                    callcenter_execute(
                        'UPDATE integration_connectors SET name=:name,enabled=:enabled,connection_mode=:mode,'
                        . 'bind_address=:bind,remote_host=:host,port=:port,reconnect_seconds=:reconnect,'
                        . 'connect_timeout_seconds=:timeout,connection_status=:status,last_error=:error,updated_at=now() '
                        . 'WHERE connector_id=:connector AND dept_id=:dept',
                        [
                            'name' => $name, 'enabled' => isset($_POST['enabled']), 'mode' => $mode,
                            'bind' => $bindAddress, 'host' => $host, 'port' => $port,
                            'reconnect' => max(1, min(3600, $reconnect)),
                            'timeout' => max(1, min(120, $timeout)),
                            'status' => isset($_POST['enabled']) ? 'waiting' : 'disabled',
                            'error' => '', 'connector' => $connectorId, 'dept' => $dept,
                        ]
                    );
                } else {
                    $connector = callcenter_rows(
                        'INSERT INTO integration_connectors '
                        . '(dept_id,integration_type,provider,name,enabled,connection_mode,bind_address,remote_host,'
                        . 'port,reconnect_seconds,connect_timeout_seconds,connection_status) '
                        . 'VALUES (:dept,:type,:provider,:name,:enabled,:mode,:bind,:host,:port,:reconnect,:timeout,:status) '
                        . 'RETURNING connector_id',
                        [
                            'dept' => $dept, 'type' => 'pms', 'provider' => 'fidelio_fias', 'name' => $name,
                            'enabled' => isset($_POST['enabled']), 'mode' => $mode, 'bind' => $bindAddress,
                            'host' => $host, 'port' => $port, 'reconnect' => max(1, min(3600, $reconnect)),
                            'timeout' => max(1, min(120, $timeout)),
                            'status' => isset($_POST['enabled']) ? 'waiting' : 'disabled',
                        ]
                    )[0] ?? [];
                    $connectorId = (int) ($connector['connector_id'] ?? 0);
                }
                callcenter_execute(
                    'INSERT INTO pms_fidelio_settings '
                    . '(connector_id,fias_version,hotel_code,charset,message_terminator,keepalive_seconds,auto_create_room_mapping) '
                    . 'VALUES (:connector,:version,:hotel,:charset,:terminator,:keepalive,:auto) '
                    . 'ON CONFLICT (connector_id) DO UPDATE SET fias_version=EXCLUDED.fias_version,'
                    . 'hotel_code=EXCLUDED.hotel_code,charset=EXCLUDED.charset,message_terminator=EXCLUDED.message_terminator,'
                    . 'keepalive_seconds=EXCLUDED.keepalive_seconds,auto_create_room_mapping=EXCLUDED.auto_create_room_mapping,'
                    . 'updated_at=now()',
                    [
                        'connector' => $connectorId,
                        'version' => mb_substr(trim((string) ($_POST['fias_version'] ?? '2.20')), 0, 20),
                        'hotel' => mb_substr(trim((string) ($_POST['hotel_code'] ?? '')), 0, 80),
                        'charset' => $charset, 'terminator' => $terminator,
                        'keepalive' => max(10, min(3600, (int) ($_POST['keepalive_seconds'] ?? 60))),
                        'auto' => isset($_POST['auto_create_room_mapping']),
                    ]
                );
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }
            json_out(['ok' => true, 'connector_id' => $connectorId]);

        case 'integration_fidelio_test':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            require_once __DIR__ . '/includes/integrations.php';
            $dept = require_dept_id();
            $connector = integration_fidelio_connector($dept);
            if (!$connector) {
                json_out(['ok' => false, 'error' => 'Önce Fidelio ayarlarını kaydedin'], 404);
            }
            if ((string) $connector['connection_mode'] === 'server') {
                json_out([
                    'ok' => true,
                    'message' => 'Sunucu modu ' . $connector['bind_address'] . ':' . $connector['port']
                        . ' adresinde Fidelio bağlantısını bekler.',
                    'status' => $connector['connection_status'],
                ]);
            }
            $socket = @stream_socket_client(
                'tcp://' . $connector['remote_host'] . ':' . $connector['port'],
                $errno,
                $error,
                (int) $connector['connect_timeout_seconds']
            );
            if (!$socket) {
                json_out(['ok' => false, 'error' => "Bağlantı kurulamadı: {$error} ({$errno})"], 422);
            }
            fclose($socket);
            json_out(['ok' => true, 'message' => 'TCP bağlantısı başarılı.']);

        case 'integration_fidelio_mapping_save':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            require_once __DIR__ . '/includes/integrations.php';
            $dept = require_dept_id();
            $connector = integration_fidelio_connector($dept);
            if (!$connector) {
                json_out(['ok' => false, 'error' => 'Önce Fidelio ayarlarını kaydedin'], 404);
            }
            $room = trim((string) ($_POST['room_number'] ?? ''));
            $extension = preg_replace('/\D/', '', (string) ($_POST['extension_number'] ?? '')) ?? '';
            if ($room === '' || strlen($room) > 40 || $extension === '' || strlen($extension) > 40) {
                json_out(['ok' => false, 'error' => 'Oda ve dahili numarası zorunludur'], 422);
            }
            $extensionRow = find_by('extensions', 'id', ext_id($dept, $extension));
            if (!$extensionRow) {
                json_out(['ok' => false, 'error' => 'Seçilen dahili bu firmada bulunamadı'], 422);
            }
            callcenter_execute(
                'INSERT INTO pms_room_mappings (dept_id,connector_id,room_number,extension_number,active) '
                . 'VALUES (:dept,:connector,:room,:extension,TRUE) '
                . 'ON CONFLICT (connector_id,room_number) DO UPDATE SET '
                . 'extension_number=EXCLUDED.extension_number,active=TRUE,updated_at=now()',
                [
                    'dept' => $dept, 'connector' => (int) $connector['connector_id'],
                    'room' => $room, 'extension' => $extension,
                ]
            );
            json_out(['ok' => true]);

        case 'integration_fidelio_mapping_delete':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            require_once __DIR__ . '/includes/integrations.php';
            $dept = require_dept_id();
            callcenter_execute(
                'DELETE FROM pms_room_mappings WHERE mapping_id=:mapping AND dept_id=:dept',
                ['mapping' => (int) ($_POST['mapping_id'] ?? 0), 'dept' => $dept]
            );
            json_out(['ok' => true]);

        case 'integration_syslog_save':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            require_once __DIR__ . '/includes/syslog_integration.php';
            $dept = require_dept_id();
            $name = trim((string) ($_POST['name'] ?? 'Remote Syslog'));
            $host = trim((string) ($_POST['remote_host'] ?? ''));
            $transport = (string) ($_POST['transport'] ?? 'udp');
            $port = (int) ($_POST['port'] ?? ($transport === 'tls' ? 6514 : 514));
            $facility = (int) ($_POST['facility'] ?? 16);
            $appName = trim((string) ($_POST['app_name'] ?? 'astera-pbx'));
            $enabled = isset($_POST['enabled']);
            $exportSystem = isset($_POST['export_system_alarms']);
            $exportPbx = isset($_POST['export_pbx_alarms']);
            $exportAudit = isset($_POST['export_audit_logs']);
            if ($name === '' || strlen($name) > 160) {
                json_out(['ok' => false, 'error' => 'Syslog bağlantı adı zorunludur'], 422);
            }
            if (($enabled || $host !== '')
                && ($host === '' || strlen($host) > 255 || preg_match('/^[A-Za-z0-9._:-]+$/', $host) !== 1)) {
                json_out(['ok' => false, 'error' => 'Geçerli Syslog sunucu adresi girin'], 422);
            }
            if (!in_array($transport, ['udp', 'tcp', 'tls'], true) || $port < 1 || $port > 65535) {
                json_out(['ok' => false, 'error' => 'Syslog protokolü veya portu geçersiz'], 422);
            }
            if ($facility < 0 || $facility > 23 || preg_match('/^[A-Za-z0-9_.-]{1,48}$/', $appName) !== 1) {
                json_out(['ok' => false, 'error' => 'Facility veya uygulama adı geçersiz'], 422);
            }
            if ($enabled && !$exportSystem && !$exportPbx && !$exportAudit) {
                json_out(['ok' => false, 'error' => 'En az bir aktarılacak veri türü seçin'], 422);
            }
            $existing = integration_syslog_connector($dept);
            $pdo = callcenter_pdo();
            if (!$pdo) {
                json_out(['ok' => false, 'error' => 'PostgreSQL bağlantısı gerekli'], 503);
            }
            $pdo->beginTransaction();
            try {
                if ($existing) {
                    $connectorId = (int) $existing['connector_id'];
                    callcenter_execute(
                        'UPDATE integration_connectors SET name=:name,enabled=:enabled,remote_host=:host,'
                        . 'port=:port,connect_timeout_seconds=:timeout,connection_status=:status,last_error=:error,updated_at=now() '
                        . 'WHERE connector_id=:connector AND dept_id=:dept',
                        [
                            'name' => $name, 'enabled' => $enabled, 'host' => $host, 'port' => $port,
                            'timeout' => max(1, min(120, (int) ($_POST['connect_timeout_seconds'] ?? 10))),
                            'status' => $enabled ? 'waiting' : 'disabled', 'error' => '',
                            'connector' => $connectorId, 'dept' => $dept,
                        ]
                    );
                } else {
                    $row = callcenter_rows(
                        'INSERT INTO integration_connectors '
                        . '(dept_id,integration_type,provider,name,enabled,connection_mode,remote_host,port,'
                        . 'connect_timeout_seconds,connection_status) '
                        . 'VALUES (:dept,:type,:provider,:name,:enabled,:mode,:host,:port,:timeout,:status) '
                        . 'RETURNING connector_id',
                        [
                            'dept' => $dept, 'type' => 'logging', 'provider' => 'remote_syslog',
                            'name' => $name, 'enabled' => $enabled, 'mode' => 'client',
                            'host' => $host, 'port' => $port,
                            'timeout' => max(1, min(120, (int) ($_POST['connect_timeout_seconds'] ?? 10))),
                            'status' => $enabled ? 'waiting' : 'disabled',
                        ]
                    )[0] ?? [];
                    $connectorId = (int) ($row['connector_id'] ?? 0);
                }
                callcenter_execute(
                    'INSERT INTO integration_syslog_settings '
                    . '(connector_id,transport,facility,app_name,export_system_alarms,export_pbx_alarms,export_audit_logs) '
                    . 'VALUES (:connector,:transport,:facility,:app,:system,:pbx,:audit) '
                    . 'ON CONFLICT (connector_id) DO UPDATE SET transport=EXCLUDED.transport,facility=EXCLUDED.facility,'
                    . 'app_name=EXCLUDED.app_name,export_system_alarms=EXCLUDED.export_system_alarms,'
                    . 'export_pbx_alarms=EXCLUDED.export_pbx_alarms,export_audit_logs=EXCLUDED.export_audit_logs,updated_at=now()',
                    [
                        'connector' => $connectorId, 'transport' => $transport, 'facility' => $facility,
                        'app' => $appName, 'system' => $exportSystem, 'pbx' => $exportPbx, 'audit' => $exportAudit,
                    ]
                );
                $becameEnabled = $enabled && (!$existing || !integration_syslog_bool($existing['enabled'] ?? false));
                if ($becameEnabled) {
                    $alarmMax = (int) (callcenter_rows(
                        'SELECT COALESCE(MAX(event_id),0) AS id FROM callcenter_alarm_events WHERE dept_id=:dept',
                        ['dept' => $dept]
                    )[0]['id'] ?? 0);
                    $auditMax = (int) (callcenter_rows(
                        'SELECT COALESCE(MAX(audit_id),0) AS id FROM callcenter_audit_logs WHERE dept_id=:dept',
                        ['dept' => $dept]
                    )[0]['id'] ?? 0);
                    foreach (['alarm_events' => $alarmMax, 'audit_logs' => $auditMax] as $source => $lastId) {
                        callcenter_execute(
                            'INSERT INTO integration_syslog_cursors (connector_id,source_name,last_id) '
                            . 'VALUES (:connector,:source,:last) ON CONFLICT (connector_id,source_name) '
                            . 'DO UPDATE SET last_id=EXCLUDED.last_id,updated_at=now()',
                            ['connector' => $connectorId, 'source' => $source, 'last' => $lastId]
                        );
                    }
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }
            json_out(['ok' => true, 'connector_id' => $connectorId]);

        case 'integration_syslog_test':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            require_once __DIR__ . '/includes/syslog_integration.php';
            $dept = require_dept_id();
            $connector = integration_syslog_connector($dept);
            if (!$connector) {
                json_out(['ok' => false, 'error' => 'Önce Syslog ayarlarını kaydedin'], 404);
            }
            try {
                integration_syslog_send(
                    $connector,
                    'notice',
                    'connection_test',
                    'dept=' . $dept . ' message="Astera PBX uzak Syslog bağlantı testi başarılı"'
                );
                integration_connector_status((int) $connector['connector_id'], 'connected');
            } catch (Throwable $exception) {
                integration_connector_status((int) $connector['connector_id'], 'error', $exception->getMessage());
                throw $exception;
            }
            json_out(['ok' => true, 'message' => 'Test Syslog mesajı gönderildi.']);

        case 'backup_settings_save':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            require_once __DIR__ . '/includes/backups.php';
            $settings = backup_settings();
            $companyName = trim((string) ($_POST['company_name'] ?? ''));
            if ($companyName === '' || mb_strlen($companyName) > 120) {
                json_out(['ok' => false, 'error' => 'Firma adı zorunludur ve en fazla 120 karakter olabilir'], 422);
            }
            $retention = max(1, min(100, (int) ($_POST['retention_count'] ?? 10)));
            $scheduleEnabled = backup_bool($_POST['schedule_enabled'] ?? '');
            $scheduleFrequency = (string) ($_POST['schedule_frequency'] ?? 'daily');
            $scheduleTime = trim((string) ($_POST['schedule_time'] ?? '02:00'));
            $scheduleWeekday = (int) ($_POST['schedule_weekday'] ?? 1);
            $scheduleDay = (int) ($_POST['schedule_day'] ?? 1);
            $scheduleMonth = (int) ($_POST['schedule_month'] ?? 1);
            $scheduleDate = trim((string) ($_POST['schedule_date'] ?? ''));
            if (!in_array($scheduleFrequency, ['once', 'daily', 'weekly', 'monthly', 'yearly'], true)
                || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $scheduleTime) !== 1
                || $scheduleWeekday < 1 || $scheduleWeekday > 7
                || $scheduleDay < 1 || $scheduleDay > 31
                || $scheduleMonth < 1 || $scheduleMonth > 12) {
                json_out(['ok' => false, 'error' => 'Otomatik backup zamanlaması geçersiz'], 422);
            }
            $scheduleOnceAt = null;
            if ($scheduleFrequency === 'once' && ($scheduleEnabled || $scheduleDate !== '')) {
                $scheduleOnceAt = DateTimeImmutable::createFromFormat(
                    '!Y-m-d H:i',
                    $scheduleDate . ' ' . $scheduleTime,
                    new DateTimeZone('Europe/Istanbul')
                );
                $dateErrors = DateTimeImmutable::getLastErrors();
                if (!$scheduleOnceAt
                    || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                    || $scheduleOnceAt->format('Y-m-d') !== $scheduleDate) {
                    json_out(['ok' => false, 'error' => 'Tek seferlik backup tarihi geçersiz'], 422);
                }
            }
            $scheduleValues = [
                'schedule_enabled' => $scheduleEnabled,
                'schedule_frequency' => $scheduleFrequency,
                'schedule_time' => $scheduleTime,
                'schedule_weekday' => $scheduleWeekday,
                'schedule_day' => $scheduleDay,
                'schedule_month' => $scheduleMonth,
                'schedule_once_at' => $scheduleOnceAt?->format(DATE_ATOM),
            ];
            $scheduleNext = backup_schedule_next_run($scheduleValues, null, true);
            if ($scheduleEnabled && $scheduleNext === null) {
                json_out(['ok' => false, 'error' => 'Seçilen otomatik backup zamanı geçmiş veya geçersiz'], 422);
            }
            $remoteEnabled = backup_bool($_POST['remote_enabled'] ?? '');
            $remoteHost = trim((string) ($_POST['remote_host'] ?? ''));
            $remotePort = (int) ($_POST['remote_port'] ?? 22);
            $remoteUsername = trim((string) ($_POST['remote_username'] ?? ''));
            $remotePath = rtrim(trim((string) ($_POST['remote_path'] ?? '')), '/');
            $authMode = (string) ($_POST['remote_auth_mode'] ?? 'password');
            $keyPath = trim((string) ($_POST['remote_key_path'] ?? ''));
            if ($remoteEnabled) {
                if ($remoteHost === '' || preg_match('/^[A-Za-z0-9._:-]+$/', $remoteHost) !== 1
                    || $remoteUsername === '' || preg_match('/^[A-Za-z0-9._-]+$/', $remoteUsername) !== 1) {
                    json_out(['ok' => false, 'error' => 'Uzak sunucu veya kullanıcı adı geçersiz'], 422);
                }
                if ($remotePort < 1 || $remotePort > 65535
                    || $remotePath === ''
                    || preg_match('~^/?[A-Za-z0-9_./-]+$~', $remotePath) !== 1
                    || in_array('..', explode('/', $remotePath), true)) {
                    json_out(['ok' => false, 'error' => 'Uzak port veya dizin geçersiz'], 422);
                }
                if (!in_array($authMode, ['password', 'key'], true)) {
                    json_out(['ok' => false, 'error' => 'Uzak doğrulama yöntemi geçersiz'], 422);
                }
                if ($authMode === 'key' && ($keyPath === '' || !str_starts_with($keyPath, '/etc/astera/'))) {
                    json_out(['ok' => false, 'error' => 'SSH anahtarı /etc/astera altında olmalıdır'], 422);
                }
            }
            $password = (string) ($_POST['remote_password'] ?? '');
            $encryptedPassword = $password !== ''
                ? astera_alarm_encrypt($password)
                : (string) ($settings['remote_password_encrypted'] ?? '');
            callcenter_execute(
                'UPDATE system_backup_settings SET company_name=:company_name,include_recordings=:recordings,retention_count=:retention,'
                . 'remote_enabled=:remote_enabled,remote_host=:host,remote_port=:port,remote_username=:username,'
                . 'remote_path=:remote_path,remote_auth_mode=:auth_mode,remote_password_encrypted=:password,'
                . 'remote_key_path=:key_path,schedule_enabled=:schedule_enabled,schedule_frequency=:schedule_frequency,'
                . 'schedule_time=:schedule_time,schedule_weekday=:schedule_weekday,schedule_day=:schedule_day,'
                . 'schedule_month=:schedule_month,schedule_once_at=:schedule_once_at,schedule_next_run=:schedule_next_run,'
                . 'updated_at=now() WHERE setting_id=1',
                [
                    'company_name' => $companyName,
                    'recordings' => backup_bool($_POST['include_recordings'] ?? ''), 'retention' => $retention,
                    'remote_enabled' => $remoteEnabled, 'host' => $remoteHost,
                    'port' => max(1, min(65535, $remotePort)), 'username' => $remoteUsername,
                    'remote_path' => $remotePath, 'auth_mode' => $authMode,
                    'password' => $encryptedPassword, 'key_path' => $keyPath,
                    'schedule_enabled' => $scheduleEnabled,
                    'schedule_frequency' => $scheduleFrequency,
                    'schedule_time' => $scheduleTime,
                    'schedule_weekday' => $scheduleWeekday,
                    'schedule_day' => $scheduleDay,
                    'schedule_month' => $scheduleMonth,
                    'schedule_once_at' => $scheduleOnceAt?->format(DATE_ATOM),
                    'schedule_next_run' => $scheduleNext?->format(DATE_ATOM),
                ]
            );
            json_out(['ok' => true]);

        case 'backup_remote_test':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            require_once __DIR__ . '/includes/backups.php';
            $settings = backup_settings();
            if (!backup_bool($settings['remote_enabled'] ?? false)) {
                json_out(['ok' => false, 'error' => 'Önce uzak SFTP backup ayarını etkinleştirip kaydedin'], 422);
            }
            json_out([
                'ok' => true,
                'message' => backup_remote_connection_test($settings),
            ]);

        case 'backup_create':
        case 'backup_restore':
        case 'backup_delete':
        case 'backup_remote_upload':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            require_once __DIR__ . '/includes/backups.php';
            $jobAction = match ($action) {
                'backup_create' => 'backup',
                'backup_restore' => 'restore',
                'backup_delete' => 'delete',
                default => 'remote_upload',
            };
            $backupId = trim((string) ($_POST['backup_id'] ?? ''));
            if ($jobAction !== 'backup') {
                $archive = callcenter_rows(
                    'SELECT backup_id FROM system_backup_archives WHERE backup_id=:id',
                    ['id' => $backupId]
                )[0] ?? null;
                if (!$archive) {
                    json_out(['ok' => false, 'error' => 'Backup bulunamadı'], 404);
                }
            }
            if ($jobAction === 'restore' && (string) ($_POST['confirmation'] ?? '') !== 'RESTORE') {
                json_out(['ok' => false, 'error' => 'Restore işlemi için RESTORE onayı gerekli'], 422);
            }
            $activeJob = callcenter_rows(
                "SELECT job_id FROM system_backup_jobs WHERE status IN ('queued','running') LIMIT 1"
            )[0] ?? null;
            if ($activeJob) {
                json_out(['ok' => false, 'error' => 'Devam eden başka bir backup/restore işi var'], 409);
            }
            $job = callcenter_rows(
                'INSERT INTO system_backup_jobs (action,backup_id,requested_by) '
                . 'VALUES (:action,:backup,:actor) RETURNING job_id',
                [
                    'action' => $jobAction,
                    'backup' => $jobAction === 'backup' ? null : $backupId,
                    'actor' => substr((string) ($_SESSION['user'] ?? 'admin'), 0, 120),
                ]
            )[0] ?? [];
            json_out(['ok' => true, 'job_id' => (int) ($job['job_id'] ?? 0)]);

        case 'status':
            $statusDeptId = current_dept_id();
            $pendingCount = store_pending_count();
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $status = pbx_status($statusDeptId);
            $status['pending_count'] = $pendingCount;
            json_out(['ok' => true, 'status' => $status]);

        case 'dept_select':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Firma değiştirilemez']);
            }
            $id = ast_sanitize_id((string) ($_POST['dept'] ?? '*')) ?: '*';
            if ($id !== '*' && !dept_by_id($id)) {
                json_out(['ok' => false, 'error' => 'Firma yok']);
            }
            $_SESSION['dept'] = $id;
            json_out(['ok' => true]);

        case 'url_trigger_save':
            $dept = require_dept_id();
            $template = trim((string) ($_POST['url_template'] ?? ''));
            if ($template === '' || strlen($template) > 2048 || !url_trigger_valid_template($template)) {
                json_out(['ok' => false, 'error' => 'Geçerli bir HTTP/HTTPS URL şablonu girin'], 422);
            }
            $trigger = (string) ($_POST['trigger'] ?? 'ring');
            if (!in_array($trigger, ['ring', 'answer', 'both'], true)) {
                $trigger = 'ring';
            }
            $mode = (string) ($_POST['mode'] ?? 'both');
            if (!in_array($mode, ['server', 'browser', 'both'], true)) {
                $mode = 'both';
            }
            $numberFormat = (string) ($_POST['number_format'] ?? 'digits');
            if (!in_array($numberFormat, ['raw', 'digits', 'e164_tr'], true)) {
                $numberFormat = 'digits';
            }
            store_upsert('url_triggers', [
                'id' => 'url-' . $dept,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? 'CRM')) ?: 'CRM',
                'url_template' => $template,
                'trigger' => $trigger,
                'mode' => $mode,
                'number_format' => $numberFormat,
                'min_digits' => max(1, min(32, (int) ($_POST['min_digits'] ?? 7))),
                'enabled' => !empty($_POST['enabled']),
                'updated_at' => date(DATE_ATOM),
                'updated_by' => store_actor(),
            ], 'id');
            json_out(['ok' => true]);

        case 'url_trigger_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('url_triggers', 'id', $id);
            if ($row) {
                assert_row_scope($row);
                store_delete('url_triggers', 'id', $id);
            }
            json_out(['ok' => true]);

        case 'url_trigger_test':
            $dept = require_dept_id();
            $template = trim((string) ($_POST['url_template'] ?? ''));
            if (!url_trigger_valid_template($template)) {
                json_out(['ok' => false, 'error' => 'Geçerli bir HTTP/HTTPS URL şablonu girin'], 422);
            }
            $config = [
                'dept' => $dept,
                'url_template' => $template,
                'number_format' => (string) ($_POST['number_format'] ?? 'digits'),
            ];
            $url = url_trigger_build_url(
                $config,
                trim((string) ($_POST['test_number'] ?? '905551112233')),
                '1001',
                'test'
            );
            $result = url_trigger_request($url);
            json_out([
                'ok' => !empty($result['ok']),
                'code' => (int) ($result['code'] ?? 0),
                'url' => $url,
                'error' => $result['error'] ?? null,
            ], !empty($result['ok']) ? 200 : 422);

        case 'crm_customer_save':
            $dept = require_dept_id();
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $existing = $id !== '' ? crm_customer_find_db($id) : null;
            if ($existing) {
                assert_row_scope($existing);
                if ((string) ($existing['dept'] ?? '') !== $dept) {
                    json_out(['ok' => false, 'error' => 'Müşteri başka bir firmaya ait'], 403);
                }
            }
            $company = substr(trim((string) ($_POST['company'] ?? '')), 0, 160);
            $phoneRaw = substr(trim((string) ($_POST['phone'] ?? '')), 0, 32);
            $phoneAltRaw = substr(trim((string) ($_POST['phone_alt'] ?? '')), 0, 32);
            $phone = crm_normalize_phone($phoneRaw);
            $phoneAlt = crm_normalize_phone($phoneAltRaw);
            if ($company === '') {
                json_out(['ok' => false, 'error' => 'Firma / müşteri adı zorunlu'], 422);
            }
            if (strlen($phone) < 7 || strlen($phone) > 15) {
                json_out(['ok' => false, 'error' => 'Geçerli bir telefon numarası girin'], 422);
            }
            if ($phoneAlt !== '' && (strlen($phoneAlt) < 7 || strlen($phoneAlt) > 15)) {
                json_out(['ok' => false, 'error' => 'İkinci telefon numarası geçersiz'], 422);
            }
            foreach (array_filter([$phone, $phoneAlt]) as $candidatePhone) {
                $customer = crm_customer_by_phone_db($dept, $candidatePhone);
                if (!$customer || (string) ($customer['id'] ?? '') === $id) {
                    continue;
                }
                json_out(['ok' => false, 'error' => 'Bu telefon numarası başka bir müşteri kartında kayıtlı'], 422);
            }
            $email = substr(trim((string) ($_POST['email'] ?? '')), 0, 180);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                json_out(['ok' => false, 'error' => 'E-posta adresi geçersiz'], 422);
            }
            if ($id === '') {
                $id = $dept . '-crm-' . bin2hex(random_bytes(6));
            }
            $savedCustomer = [
                'id' => $id,
                'dept' => $dept,
                'company' => $company,
                'contact' => substr(trim((string) ($_POST['contact'] ?? '')), 0, 160),
                'phone' => $phoneRaw,
                'phone_alt' => $phoneAltRaw,
                'email' => $email,
                'address' => substr(trim((string) ($_POST['address'] ?? '')), 0, 500),
                'notes' => substr(trim((string) ($_POST['notes'] ?? '')), 0, 3000),
                'created_at' => (string) ($existing['created_at'] ?? date(DATE_ATOM)),
                'updated_at' => date(DATE_ATOM),
                'updated_by' => store_actor(),
            ];
            crm_customer_save_db($savedCustomer);
            crm_sync_customer_caller_id_cache($existing, $savedCustomer);
            json_out(['ok' => true, 'id' => $id]);

        case 'crm_customer_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $customer = crm_customer_find_db($id);
            if ($customer) {
                assert_row_scope($customer);
                crm_customer_delete_db($id, (string) ($customer['dept'] ?? ''));
                crm_sync_customer_caller_id_cache($customer, null);
            }
            json_out(['ok' => true]);

        case 'crm_call_note_save':
            $customerId = ast_sanitize_id((string) ($_POST['customer_id'] ?? ''));
            $customer = crm_customer_find_db($customerId);
            if (!$customer) {
                json_out(['ok' => false, 'error' => 'Müşteri kartı bulunamadı'], 404);
            }
            assert_row_scope($customer);
            $dept = (string) ($customer['dept'] ?? '');
            $callKey = substr(trim((string) ($_POST['call_key'] ?? '')), 0, 255);
            if ($callKey === '') {
                json_out(['ok' => false, 'error' => 'Görüşme kimliği eksik'], 422);
            }
            $note = substr(trim((string) ($_POST['note'] ?? '')), 0, 3000);
            $noteId = crm_call_note_id($dept, $callKey);
            if ($note === '') {
                crm_call_note_delete_db($noteId, $dept);
                json_out(['ok' => true]);
            }
            crm_call_note_save_db([
                'id' => $noteId,
                'dept' => $dept,
                'customer_id' => $customerId,
                'call_key' => $callKey,
                'note' => $note,
                'updated_at' => date(DATE_ATOM),
                'updated_by' => store_actor(),
            ]);
            json_out(['ok' => true]);

        case 'feature_takeover_save':
            $dept = require_dept_id();
            require_once __DIR__ . '/includes/feature_codes.php';
            $enabled = in_array(
                strtolower((string) ($_POST['enabled'] ?? '')),
                ['1', 'true', 'yes', 'on'],
                true
            );
            $activationPrefix = trim((string) ($_POST['activation_prefix'] ?? '*88'));
            $cancelCode = trim((string) ($_POST['cancel_code'] ?? '*89'));
            if (preg_match('/^\*[0-9]{2,6}$/D', $activationPrefix) !== 1
                || preg_match('/^\*[0-9]{2,6}$/D', $cancelCode) !== 1) {
                json_out(['ok' => false, 'error' => 'Kodlar * ile başlamalı ve ardından 2-6 rakam gelmelidir'], 422);
            }
            if ($activationPrefix === $cancelCode) {
                json_out(['ok' => false, 'error' => 'Devralma ve iptal kodları aynı olamaz'], 422);
            }
            $conflicts = feature_takeover_conflicts($dept, $activationPrefix, $cancelCode);
            if ($enabled && $conflicts) {
                json_out([
                    'ok' => false,
                    'error' => 'Kod çakışması: ' . implode(' · ', $conflicts),
                    'conflicts' => $conflicts,
                ], 409);
            }
            callcenter_execute(
                'INSERT INTO feature_takeover_settings '
                . '(dept_id,enabled,activation_prefix,cancel_code) '
                . 'VALUES (:dept,:enabled,:activation,:cancel) '
                . 'ON CONFLICT (dept_id) DO UPDATE SET enabled=EXCLUDED.enabled,'
                . 'activation_prefix=EXCLUDED.activation_prefix,cancel_code=EXCLUDED.cancel_code,updated_at=now()',
                [
                    'dept' => $dept,
                    'enabled' => $enabled,
                    'activation' => $activationPrefix,
                    'cancel' => $cancelCode,
                ]
            );
            $applyResult = apply_to_pbx();
            pbx_cli('database deltree TAKEOVER ' . dept_code_of($dept));
            callcenter_audit_write([
                'dept_id' => $dept,
                'username' => substr((string) ($_SESSION['username'] ?? $_SESSION['user'] ?? 'santral-admin'), 0, 160),
            ], 'update', 'ozellik_kodlari', 'Dahili devralma kodları güncellendi', '', 'pbx');
            json_out(array_merge([
                'ok' => true,
                'message' => $enabled
                    ? 'Dahili devralma etkinleştirildi.'
                    : 'Dahili devralma kapatıldı.',
            ], $applyResult));

        case 'department_save':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi']);
            }
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($id === '' || $id === '*') {
                json_out(['ok' => false, 'error' => 'Firma kodu zorunlu (harf/rakam)']);
            }
            $existing = dept_by_id($id);
            $panelPass = (string) ($_POST['panel_pass'] ?? '');
            if ($panelPass === '' && $existing) {
                $panelPass = (string) ($existing['panel_pass'] ?? '');
            }
            $panelUser = trim((string) ($_POST['panel_user'] ?? ''));
            $submittedPanelPass = (string) ($_POST['panel_pass'] ?? '');
            if ($submittedPanelPass !== '' && strlen($submittedPanelPass) < 8) {
                json_out(['ok' => false, 'error' => 'Firma paneli parolası en az 8 karakter olmalıdır'], 422);
            }
            if ($panelUser !== '' && $panelPass === '') {
                json_out(['ok' => false, 'error' => 'Firma paneli kullanıcısı için parola zorunludur'], 422);
            }
            $extensionLimit = max(0, min(100000, (int) ($_POST['extension_limit'] ?? 0)));
            $extensionCount = department_extension_count($id);
            if ($extensionLimit > 0 && $extensionLimit < $extensionCount) {
                json_out([
                    'ok' => false,
                    'error' => "Abone limiti mevcut {$extensionCount} aboneden düşük olamaz.",
                ], 422);
            }
            store_upsert('departments', [
                'id' => $id,
                'code' => $id,
                'name' => trim((string) ($_POST['name'] ?? $id)),
                'record' => !empty($_POST['record']),
                'cid' => trim((string) ($_POST['cid'] ?? '')),
                'panel_user' => $panelUser,
                'panel_pass' => $panelPass,
                'extension_limit' => $extensionLimit,
            ], 'id');
            crm_ensure_tenant($id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'department_delete':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi']);
            }
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($id === 'genel') {
                json_out(['ok' => false, 'error' => 'Varsayılan firma silinemez']);
            }
            foreach (store_read('extensions') as $row) {
                if ((string) ($row['dept'] ?? '') === $id) {
                    json_out(['ok' => false, 'error' => 'Önce bu firmanın abonelerini silin']);
                }
            }
            store_delete('departments', 'id', $id);
            crm_tenant_delete_db($id);
            foreach (tenant_store_names() as $name) {
                $rows = array_values(array_filter(store_read($name), static fn($r) => (string) ($r['dept'] ?? '') !== $id));
                store_write($name, $rows);
            }
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'extension_export':
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            $exportRows = scoped('extensions');
            foreach ($exportRows as &$exportRow) {
                unset($exportRow['vm_pin']);
                $fallbackType = dest_allowed((string) ($exportRow['fallback_type'] ?? 'hangup'));
                $exportRow['fallback_type'] = $fallbackType;
                $exportRow['fallback_export_dest'] = dest_export_value(
                    (string) ($exportRow['dept'] ?? ''),
                    $fallbackType,
                    (string) ($exportRow['fallback_dest'] ?? '')
                );
                if (strtolower((string) ($exportRow['technology'] ?? 'pjsip')) !== 'pjsip') {
                    $exportRow['password'] = '';
                }
            }
            unset($exportRow);
            json_out(['ok' => true, 'extensions' => $exportRows]);

        case 'extension_secret':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('extensions', 'id', $id);
            if (!$row) {
                json_out(['ok' => false, 'error' => 'Abone bulunamadı'], 404);
            }
            assert_row_scope($row);
            if (strtolower((string) ($row['technology'] ?? 'pjsip')) !== 'pjsip') {
                json_out(['ok' => false, 'error' => 'Bu abone türünde SIP şifresi bulunmaz'], 422);
            }
            $password = (string) ($row['password'] ?? '');
            if ($password === '') {
                json_out(['ok' => false, 'error' => 'Bu abone için SIP şifresi bulunamadı'], 404);
            }
            json_out(['ok' => true, 'password' => $password]);

        case 'extension_save':
            $dept = require_dept_id();
            $exten = preg_replace('/\D/', '', (string) ($_POST['exten'] ?? '')) ?? '';
            if (strlen($exten) < 2 || strlen($exten) > 8) {
                json_out(['ok' => false, 'error' => 'Dahili 2-8 haneli olmalı']);
            }
            $id = ext_id($dept, $exten);
            $old = find_by('extensions', 'id', $id);
            $technology = strtolower((string) ($_POST['technology'] ?? ($old['technology'] ?? 'pjsip')));
            if (!in_array($technology, ['pjsip', 'dahdi', 'custom'], true)) {
                json_out(['ok' => false, 'error' => 'Geçersiz abone bağlantı türü'], 422);
            }
            $isPjsip = $technology === 'pjsip';
            $isDahdi = $technology === 'dahdi';
            $password = trim((string) ($_POST['password'] ?? ''));
            if ($password === '' && $old) {
                $password = (string) ($old['password'] ?? '');
            }
            if ($isPjsip && strlen($password) < 8) {
                json_out(['ok' => false, 'error' => 'Abone parolası en az 8 karakter olmalıdır'], 422);
            }
            if (!$isPjsip) {
                $password = '';
            }
            if (!$old) {
                assert_extension_capacity($dept);
            }
            $sip = '';
            $auth = '';
            if ($isPjsip) {
                $wantSip = ast_sanitize_id((string) ($_POST['sipuser'] ?? ''));
                if ($wantSip === '') {
                    $wantSip = (string) ($old['sipuser'] ?? $exten);
                }
                $sip = pick_sip_user($dept, $exten, $wantSip);
                $other = find_sip_user($sip);
                if ($other && ((string) ($other['id'] ?? '') !== $id) && sip_user($other) === $sip) {
                    json_out(['ok' => false, 'error' => 'Bu SIP kullanıcı adı başka abonede kayıtlı: ' . $sip]);
                }
                $auth = ast_sanitize_id((string) ($_POST['authuser'] ?? ''));
                if ($auth === '') {
                    $auth = ast_sanitize_id((string) ($old['authuser'] ?? $sip));
                }
                if ($auth === '') {
                    $auth = $sip;
                }
            }
            $codecs = $_POST['codecs'] ?? ['alaw', 'ulaw'];
            if (!is_array($codecs)) {
                $codecs = [$codecs];
            }
            $recordFormat = strtolower((string) ($_POST['record_format'] ?? 'wav'));
            if (!in_array($recordFormat, ['wav', 'gsm', 'ulaw'], true)) {
                $recordFormat = 'wav';
            }
            $defaultFallback = extension_default_fallback($dept);
            $fallbackType = dest_allowed((string) ($_POST['fallback_type'] ?? $defaultFallback['type']));
            $fallbackDest = ast_sanitize_id((string) ($_POST['fallback_dest'] ?? $defaultFallback['dest']));
            if ($fallbackType !== 'hangup' && $fallbackDest === '') {
                json_out(['ok' => false, 'error' => 'Cevaplanmama/meşgul yönlendirme hedefi zorunlu']);
            }
            if ($fallbackType !== 'hangup' && !dest_belongs_to_dept($dept, $fallbackType, $fallbackDest)) {
                json_out(['ok' => false, 'error' => 'Yönlendirme hedefi bu firmaya ait değil']);
            }
            $crmTrigger = (string) ($_POST['crm_trigger'] ?? ($old['crm_trigger'] ?? 'disabled'));
            if (!in_array($crmTrigger, ['inherit', 'enabled', 'disabled'], true)) {
                $crmTrigger = 'disabled';
            }
            $dahdiGroup = null;
            $dahdiDial = '';
            $customDial = '';
            if ($isDahdi) {
                $rawDahdiDial = trim((string) ($_POST['dahdi_dial'] ?? ''));
                $compoundDahdi = [];
                $hasCompoundDahdi = preg_match(
                    '~^(?:DAHDI/)?g([0-9]{1,2})/([0-9]{1,20})$~iD',
                    $rawDahdiDial,
                    $compoundDahdi
                ) === 1;
                $submittedGroup = filter_var(
                    $hasCompoundDahdi ? $compoundDahdi[1] : ($_POST['dahdi_group'] ?? null),
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 0, 'max_range' => 63]]
                );
                if ($submittedGroup === false) {
                    json_out(['ok' => false, 'error' => 'DAHDI grubu 0-63 arasında olmalıdır'], 422);
                }
                $dahdiGroup = (int) $submittedGroup;
                $dahdiDial = $hasCompoundDahdi ? $compoundDahdi[2] : $rawDahdiDial;
                if ($dahdiDial === '') {
                    $dahdiDial = $exten;
                }
                if (preg_match('/^[0-9]{1,20}$/D', $dahdiDial) !== 1) {
                    json_out([
                        'ok' => false,
                        'error' => 'DAHDI hedefi numara veya g3/201 biçiminde olmalıdır',
                    ], 422);
                }
                $phoneModes = ['dahdi'];
            } elseif ($technology === 'custom') {
                $customDial = trim((string) ($_POST['custom_dial'] ?? ''));
                if (
                    strlen($customDial) > 160
                    || preg_match('~^[A-Za-z][A-Za-z0-9_]*/[A-Za-z0-9_+*#@.:\-/]+$~D', $customDial) !== 1
                ) {
                    json_out([
                        'ok' => false,
                        'error' => 'Özel kanal geçersiz. Örnek: local/110@from-alcatel',
                    ], 422);
                }
                $phoneModes = ['custom'];
            } else {
                $phoneModes = $_POST['phone_modes'] ?? [];
                if (!is_array($phoneModes)) {
                    $phoneModes = [$phoneModes];
                }
                $phoneModes = array_values(array_unique(array_intersect(
                    ['webrtc', 'softphone', 'desk_phone'],
                    array_map('strval', $phoneModes)
                )));
                if (count($phoneModes) !== 1) {
                    json_out(['ok' => false, 'error' => 'Abone için bir telefon türü seçin']);
                }
            }
            $webrtcEnabled = $isPjsip && $phoneModes[0] === 'webrtc';
            $deptOutboundRouteIds = [];
            foreach (store_read('outbound') as $route) {
                if ((string) ($route['dept'] ?? '') === $dept) {
                    $routeId = ast_sanitize_id((string) ($route['id'] ?? ''));
                    if ($routeId !== '') {
                        $deptOutboundRouteIds[$routeId] = true;
                    }
                }
            }
            if (!empty($_POST['outbound_routes_present'])) {
                $submittedOutboundRoutes = $_POST['outbound_routes'] ?? [];
                if (!is_array($submittedOutboundRoutes)) {
                    $submittedOutboundRoutes = [$submittedOutboundRoutes];
                }
                $allowedOutboundRoutes = array_values(array_unique(array_filter(array_map(
                    static fn($routeId): string => ast_sanitize_id((string) $routeId),
                    $submittedOutboundRoutes
                ))));
                foreach ($allowedOutboundRoutes as $routeId) {
                    if (!isset($deptOutboundRouteIds[$routeId])) {
                        json_out(['ok' => false, 'error' => 'Seçilen giden arama çıkışı bu firmaya ait değil'], 422);
                    }
                }
            } elseif (is_array($old['outbound_routes'] ?? null)) {
                $allowedOutboundRoutes = array_values(array_filter(
                    array_map(
                        static fn($routeId): string => ast_sanitize_id((string) $routeId),
                        $old['outbound_routes']
                    ),
                    static fn(string $routeId): bool => isset($deptOutboundRouteIds[$routeId])
                ));
            } else {
                $legacyOutboundEnabled = !is_array($old)
                    || !array_key_exists('outbound_enabled', $old)
                    || !empty($old['outbound_enabled']);
                $allowedOutboundRoutes = $legacyOutboundEnabled
                    ? array_keys($deptOutboundRouteIds)
                    : [];
            }
            $outboundEnabled = $allowedOutboundRoutes !== [];
            $vmPin = trim((string) ($_POST['vm_pin'] ?? ''));
            if ($vmPin === '') {
                $vmPin = (string) random_int(100000, 999999);
            } elseif (preg_match('/^\d{4,10}$/D', $vmPin) !== 1) {
                json_out(['ok' => false, 'error' => 'PIN yalnızca 4-10 rakamdan oluşmalıdır'], 422);
            }
            store_upsert('extensions', [
                'id' => $id,
                'dept' => $dept,
                'exten' => $exten,
                'technology' => $technology,
                'sipuser' => $sip,
                'authuser' => $auth,
                'dahdi_group' => $dahdiGroup,
                'dahdi_dial' => $dahdiDial,
                'custom_dial' => $customDial,
                'name' => trim((string) ($_POST['name'] ?? $exten)),
                'password' => $password,
                'max_contacts' => max(1, (int) ($_POST['max_contacts'] ?? 1)),
                'codecs' => array_values($codecs),
                'callerid' => trim((string) ($_POST['name'] ?? $exten)),
                'vm_enabled' => !empty($_POST['vm_enabled']),
                'vm_pin' => $vmPin,
                'vm_email' => trim((string) ($_POST['vm_email'] ?? '')),
                'cid_num' => trim((string) ($_POST['cid_num'] ?? '')),
                'record' => !empty($_POST['record']),
                'record_format' => $recordFormat,
                'ringtime' => max(8, (int) ($_POST['ringtime'] ?? 30)),
                'followme' => trim((string) ($_POST['followme'] ?? '')),
                'fallback_type' => $fallbackType,
                'fallback_dest' => $fallbackDest,
                'dtmf' => in_array((string) ($_POST['dtmf'] ?? 'rfc4733'), ['rfc4733', 'inband', 'info'], true) ? (string) $_POST['dtmf'] : 'rfc4733',
                'language' => ast_sanitize_id((string) ($_POST['language'] ?? 'tr')) ?: 'tr',
                'transport' => ($_POST['transport'] ?? 'udp') === 'tcp' ? 'tcp' : 'udp',
                'qualify' => max(0, (int) ($_POST['qualify'] ?? 60)),
                'webrtc' => $webrtcEnabled,
                'phone_modes' => $phoneModes,
                'crm_trigger' => $crmTrigger,
                'outbound_enabled' => $outboundEnabled,
                'outbound_routes' => $allowedOutboundRoutes,
            ], 'id');
            $applyResult = apply_to_pbx();
            if (!$old) {
                require_once __DIR__ . '/includes/alarms.php';
                $extensionName = trim((string) ($_POST['name'] ?? $exten));
                astera_alarm_emit_event(
                    $dept,
                    'extension_added',
                    'Yeni abone eklendi: ' . $exten,
                    'Ad: ' . ($extensionName !== '' ? $extensionName : $exten)
                        . "\nBağlantı türü: " . strtoupper($technology)
                );
            }
            json_out(array_merge(['ok' => true, 'sipuser' => $sip], $applyResult));

        case 'extension_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('extensions', 'id', $id);
            if (!$row) {
                json_out(['ok' => false, 'error' => 'Abone yok']);
            }
            assert_row_scope($row);
            store_delete('extensions', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'extension_bulk_delete':
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids)) {
                $ids = [$ids];
            }
            $ids = array_values(array_unique(array_filter(array_map(
                static fn($id) => ast_sanitize_id((string) $id),
                $ids
            ))));
            if (!$ids) {
                json_out(['ok' => false, 'error' => 'Silinecek abone seçilmedi']);
            }
            if (count($ids) > 5000) {
                json_out(['ok' => false, 'error' => 'Tek işlemde en fazla 5000 abone silinebilir']);
            }
            $idIndex = array_fill_keys($ids, true);
            $rows = store_read('extensions');
            $deleted = 0;
            foreach ($rows as $row) {
                if (!isset($idIndex[(string) ($row['id'] ?? '')])) {
                    continue;
                }
                assert_row_scope($row);
                $deleted++;
            }
            if ($deleted === 0) {
                json_out(['ok' => false, 'error' => 'Seçilen aboneler bulunamadı']);
            }
            $rows = array_values(array_filter(
                $rows,
                static fn($row) => !isset($idIndex[(string) ($row['id'] ?? '')])
            ));
            store_write('extensions', $rows);
            json_out(array_merge(['ok' => true, 'deleted' => $deleted], apply_to_pbx()));

        case 'extension_bulk_update_features':
            $targetIds = $_POST['target_ids'] ?? [];
            $features = $_POST['features'] ?? [];
            $targetIds = is_array($targetIds) ? $targetIds : [$targetIds];
            $features = is_array($features) ? $features : [$features];
            $targetIds = array_values(array_unique(array_filter(array_map(
                static fn($value): string => ast_sanitize_id((string) $value),
                $targetIds
            ))));
            $features = array_values(array_unique(array_intersect(
                ['outbound', 'recording', 'voicemail', 'routing', 'phone', 'sip', 'crm'],
                array_map('strval', $features)
            )));
            if (!$targetIds) {
                json_out(['ok' => false, 'error' => 'Düzenlenecek en az bir abone seçin'], 422);
            }
            if (!$features) {
                json_out(['ok' => false, 'error' => 'Uygulanacak en az bir özellik seçin'], 422);
            }
            if (count($targetIds) > 5000) {
                json_out(['ok' => false, 'error' => 'Tek işlemde en fazla 5000 abone düzenlenebilir'], 422);
            }
            $targetIndex = array_fill_keys($targetIds, true);
            $rows = store_read('extensions');
            $targetDept = null;
            $matched = 0;
            foreach ($rows as $row) {
                if (!isset($targetIndex[(string) ($row['id'] ?? '')])) {
                    continue;
                }
                assert_row_scope($row);
                $rowDept = (string) ($row['dept'] ?? '');
                if ($targetDept !== null && $rowDept !== $targetDept) {
                    json_out(['ok' => false, 'error' => 'Toplu düzenleme yalnızca aynı firmadaki abonelere uygulanabilir'], 422);
                }
                $targetDept = $rowDept;
                $matched++;
            }
            if ($matched !== count($targetIds) || $targetDept === null) {
                json_out(['ok' => false, 'error' => 'Seçilen abonelerden biri bulunamadı'], 404);
            }

            $updates = [];
            if (in_array('outbound', $features, true)) {
                $routeIds = $_POST['outbound_routes'] ?? [];
                $routeIds = is_array($routeIds) ? $routeIds : [$routeIds];
                $routeIds = array_values(array_unique(array_filter(array_map(
                    static fn($value): string => ast_sanitize_id((string) $value),
                    $routeIds
                ))));
                $allowedRouteIds = [];
                foreach (store_read('outbound') as $route) {
                    if ((string) ($route['dept'] ?? '') === $targetDept) {
                        $allowedRouteIds[(string) ($route['id'] ?? '')] = true;
                    }
                }
                foreach ($routeIds as $routeId) {
                    if (!isset($allowedRouteIds[$routeId])) {
                        json_out(['ok' => false, 'error' => 'Seçilen giden arama çıkışı firmaya ait değil'], 422);
                    }
                }
                $updates['outbound_routes'] = $routeIds;
                $updates['outbound_enabled'] = $routeIds !== [];
            }
            if (in_array('recording', $features, true)) {
                $recordFormat = strtolower((string) ($_POST['record_format'] ?? 'wav'));
                $updates['record'] = !empty($_POST['record']);
                $updates['record_format'] = in_array($recordFormat, ['wav', 'gsm', 'ulaw'], true)
                    ? $recordFormat
                    : 'wav';
            }
            if (in_array('voicemail', $features, true)) {
                $updates['vm_enabled'] = !empty($_POST['vm_enabled']);
            }
            if (in_array('routing', $features, true)) {
                $fallbackType = dest_allowed((string) ($_POST['fallback_type'] ?? 'hangup'));
                $fallbackDest = ast_sanitize_id((string) ($_POST['fallback_dest'] ?? ''));
                if ($fallbackType !== 'hangup'
                    && ($fallbackDest === '' || !dest_belongs_to_dept($targetDept, $fallbackType, $fallbackDest))) {
                    json_out(['ok' => false, 'error' => 'Yönlendirme hedefi seçili firmaya ait değil'], 422);
                }
                $updates['ringtime'] = max(8, (int) ($_POST['ringtime'] ?? 30));
                $updates['followme'] = trim((string) ($_POST['followme'] ?? ''));
                $updates['fallback_type'] = $fallbackType;
                $updates['fallback_dest'] = $fallbackDest;
            }
            if (in_array('phone', $features, true)) {
                $phoneMode = (string) ($_POST['phone_mode'] ?? 'softphone');
                if (!in_array($phoneMode, ['webrtc', 'softphone', 'desk_phone'], true)) {
                    json_out(['ok' => false, 'error' => 'Geçersiz telefon türü'], 422);
                }
                $updates['phone_modes'] = [$phoneMode];
                $updates['webrtc'] = $phoneMode === 'webrtc';
                $updates['max_contacts'] = max(1, (int) ($_POST['max_contacts'] ?? 1));
            }
            if (in_array('sip', $features, true)) {
                $codecs = $_POST['codecs'] ?? [];
                $codecs = is_array($codecs) ? $codecs : [$codecs];
                $codecs = array_values(array_unique(array_intersect(
                    ['alaw', 'ulaw', 'g722', 'gsm', 'g726', 'g729', 'opus'],
                    array_map('strval', $codecs)
                )));
                if (!$codecs) {
                    json_out(['ok' => false, 'error' => 'En az bir ses kodeki seçin'], 422);
                }
                $dtmf = (string) ($_POST['dtmf'] ?? 'rfc4733');
                $updates['codecs'] = $codecs;
                $updates['dtmf'] = in_array($dtmf, ['rfc4733', 'inband', 'info'], true) ? $dtmf : 'rfc4733';
                $updates['language'] = ast_sanitize_id((string) ($_POST['language'] ?? 'tr')) ?: 'tr';
                $updates['transport'] = ($_POST['transport'] ?? 'udp') === 'tcp' ? 'tcp' : 'udp';
                $updates['qualify'] = max(0, (int) ($_POST['qualify'] ?? 0));
            }
            if (in_array('crm', $features, true)) {
                $crmTrigger = (string) ($_POST['crm_trigger'] ?? 'disabled');
                $updates['crm_trigger'] = in_array($crmTrigger, ['inherit', 'enabled', 'disabled'], true)
                    ? $crmTrigger
                    : 'disabled';
            }

            foreach ($rows as &$row) {
                if (isset($targetIndex[(string) ($row['id'] ?? '')])) {
                    $row = array_merge($row, $updates);
                }
            }
            unset($row);
            store_write('extensions', $rows);
            json_out(array_merge(['ok' => true, 'updated' => $matched], apply_to_pbx()));

        case 'extension_copy_features':
            $sourceId = ast_sanitize_id((string) ($_POST['source_id'] ?? ''));
            $source = find_by('extensions', 'id', $sourceId);
            if (!$source) {
                json_out(['ok' => false, 'error' => 'Kaynak abone bulunamadı'], 404);
            }
            assert_row_scope($source);
            $targetIds = $_POST['target_ids'] ?? [];
            $features = $_POST['features'] ?? [];
            $targetIds = is_array($targetIds) ? $targetIds : [$targetIds];
            $features = is_array($features) ? $features : [$features];
            $targetIds = array_values(array_unique(array_filter(array_map(
                static fn($value): string => ast_sanitize_id((string) $value),
                $targetIds
            ))));
            $featureFields = [
                'outbound' => ['outbound_enabled', 'outbound_routes'],
                'recording' => ['record', 'record_format'],
                'voicemail' => ['vm_enabled'],
                'routing' => ['ringtime', 'followme', 'fallback_type', 'fallback_dest'],
                'phone' => ['max_contacts', 'webrtc', 'phone_modes'],
                'sip' => ['codecs', 'dtmf', 'language', 'transport', 'qualify'],
                'crm' => ['crm_trigger'],
            ];
            $copyFields = [];
            foreach (array_unique(array_map('strval', $features)) as $feature) {
                foreach ($featureFields[$feature] ?? [] as $field) {
                    $copyFields[$field] = true;
                }
            }
            if (!$targetIds) {
                json_out(['ok' => false, 'error' => 'En az bir hedef abone seçin'], 422);
            }
            if (!$copyFields) {
                json_out(['ok' => false, 'error' => 'Kopyalanacak en az bir özellik seçin'], 422);
            }
            if (count($targetIds) > 5000) {
                json_out(['ok' => false, 'error' => 'Tek işlemde en fazla 5000 hedef seçilebilir'], 422);
            }
            $targetIndex = array_fill_keys($targetIds, true);
            $sourceDept = (string) ($source['dept'] ?? '');
            $rows = store_read('extensions');
            $copied = 0;
            foreach ($rows as &$target) {
                $targetId = (string) ($target['id'] ?? '');
                if (!isset($targetIndex[$targetId]) || $targetId === $sourceId) {
                    continue;
                }
                assert_row_scope($target);
                if ((string) ($target['dept'] ?? '') !== $sourceDept) {
                    json_out(['ok' => false, 'error' => 'Özellikler yalnızca aynı firmadaki abonelere kopyalanabilir'], 422);
                }
                foreach (array_keys($copyFields) as $field) {
                    if (array_key_exists($field, $source)) {
                        $target[$field] = $source[$field];
                    } else {
                        unset($target[$field]);
                    }
                }
                $copied++;
            }
            unset($target);
            if ($copied !== count($targetIds)) {
                json_out(['ok' => false, 'error' => 'Hedef abonelerden biri bulunamadı'], 404);
            }
            store_write('extensions', $rows);
            json_out(array_merge(['ok' => true, 'copied' => $copied], apply_to_pbx()));

        case 'extension_import':
            $dept = require_dept_id();
            if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                json_out(['ok' => false, 'error' => 'CSV dosyası seçin'], 422);
            }
            if ((int) ($_FILES['file']['size'] ?? 0) > 2 * 1024 * 1024) {
                json_out(['ok' => false, 'error' => 'CSV dosyası 2 MB üstü olamaz'], 422);
            }
            $handle = fopen((string) $_FILES['file']['tmp_name'], 'rb');
            if ($handle === false) {
                json_out(['ok' => false, 'error' => 'CSV dosyası okunamadı'], 422);
            }
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                fclose($handle);
                json_out(['ok' => false, 'error' => 'CSV dosyası boş'], 422);
            }
            $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
            rewind($handle);
            $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
            $normalizeHeader = static function (string $value): string {
                $value = trim($value, "\xEF\xBB\xBF \t\n\r\0\x0B");
                $value = strtr(mb_strtolower($value, 'UTF-8'), [
                    'ş' => 's', 'ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c',
                ]);
                return trim((string) preg_replace('/[^a-z0-9]+/', '_', $value), '_');
            };
            $headerAliases = [
                'dahili' => 'exten', 'exten' => 'exten',
                'ad' => 'name', 'isim' => 'name', 'name' => 'name',
                'parola' => 'password', 'sifre' => 'password', 'password' => 'password',
                'abone_turu' => 'technology', 'teknoloji' => 'technology',
                'technology' => 'technology', 'tur' => 'technology',
                'sip_kullanici' => 'sipuser', 'sipuser' => 'sipuser',
                'auth_kullanici' => 'authuser', 'authuser' => 'authuser',
                'dahdi_grup' => 'dahdi_group', 'dahdi_group' => 'dahdi_group',
                'dahdi_hedef' => 'dahdi_dial', 'dahdi_dial' => 'dahdi_dial',
                'custom_hedef' => 'custom_dial', 'ozel_hedef' => 'custom_dial',
                'custom_dial' => 'custom_dial',
                'dis_arama' => 'outbound_enabled', 'outbound' => 'outbound_enabled',
                'outbound_enabled' => 'outbound_enabled',
                'telefon' => 'phone_mode', 'telefon_turu' => 'phone_mode', 'phone_mode' => 'phone_mode',
                'kayit' => 'record', 'record' => 'record',
                'kodekler' => 'codecs', 'codecs' => 'codecs',
                'cevaplanmazsa_mesgulse_tur' => 'fallback_type',
                'cevaplanmazsa_mesgulse_hedef' => 'fallback_dest',
                'fallback_type' => 'fallback_type', 'fallback_dest' => 'fallback_dest',
            ];
            $columns = [];
            foreach ((array) $headers as $position => $header) {
                $normalized = $normalizeHeader((string) $header);
                if (isset($headerAliases[$normalized])) {
                    $columns[$headerAliases[$normalized]] = $position;
                }
            }
            if (!isset($columns['exten'])) {
                fclose($handle);
                json_out(['ok' => false, 'error' => 'CSV başlıklarında dahili alanı zorunludur'], 422);
            }
            $previewOnly = !empty($_POST['preview_only']);
            $updateMode = (string) ($_POST['update_mode'] ?? (!empty($_POST['update_existing']) ? 'all' : 'none'));
            if (!in_array($updateMode, ['none', 'all', 'selected'], true)) {
                fclose($handle);
                json_out(['ok' => false, 'error' => 'Geçersiz güncelleme seçeneği'], 422);
            }
            $selectedExisting = [];
            foreach ((array) ($_POST['selected_existing'] ?? []) as $selectedExten) {
                $selectedExten = preg_replace('/\D/', '', (string) $selectedExten) ?? '';
                if ($selectedExten !== '') {
                    $selectedExisting[$selectedExten] = true;
                }
            }
            $rows = store_read('extensions');
            $rowIndexes = [];
            $existingIds = [];
            $sipOwners = [];
            $authOwners = [];
            foreach ($rows as $position => $row) {
                $rowId = (string) ($row['id'] ?? '');
                $rowIndexes[$rowId] = $position;
                $existingIds[$rowId] = true;
                $sipOwners[sip_user($row)] = (string) ($row['id'] ?? '');
                $auth = ast_sanitize_id((string) ($row['authuser'] ?? ''));
                if ($auth !== '') {
                    $authOwners[$auth] = true;
                }
            }
            $booleanValue = static fn(string $value): bool => in_array(
                mb_strtolower(trim($value), 'UTF-8'),
                ['1', 'true', 'yes', 'evet', 'acik', 'açık', 'var'],
                true
            );
            $technologyValue = static function (string $value) use ($normalizeHeader): string {
                $technology = $normalizeHeader($value);
                return [
                    '' => 'pjsip',
                    'sip' => 'pjsip',
                    'pjsip' => 'pjsip',
                    'dahdi' => 'dahdi',
                    'pri' => 'dahdi',
                    'dahdi_pri' => 'dahdi',
                    'custom' => 'custom',
                    'ozel' => 'custom',
                    'ozel_custom' => 'custom',
                ][$technology] ?? '';
            };
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $lineNumber = 1;
            $errors = [];
            $conflicts = [];
            $conflictIds = [];
            while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $lineNumber++;
                if ($lineNumber > 1001) {
                    $errors[] = 'En fazla 1000 abone içe aktarılabilir';
                    break;
                }
                if (count($values) === 1 && trim((string) ($values[0] ?? '')) === '') {
                    continue;
                }
                $valueOf = static fn(string $field): string =>
                    isset($columns[$field]) ? trim((string) ($values[$columns[$field]] ?? '')) : '';
                $exten = preg_replace('/\D/', '', $valueOf('exten')) ?? '';
                $technology = $technologyValue($valueOf('technology'));
                $password = $valueOf('password');
                if (strlen($exten) < 2 || strlen($exten) > 8) {
                    $errors[] = "{$lineNumber}. satır: dahili 2-8 haneli olmalıdır";
                    continue;
                }
                if ($technology === '') {
                    $errors[] = "{$lineNumber}. satır: abone türü PJSIP, DAHDI veya CUSTOM olmalıdır";
                    continue;
                }
                $id = ext_id($dept, $exten);
                $existingPosition = $rowIndexes[$id] ?? null;
                $isStoredExtension = isset($existingIds[$id]);
                if ($isStoredExtension && !isset($conflictIds[$id])) {
                    $conflicts[] = [
                        'exten' => $exten,
                        'current_name' => (string) ($rows[$existingPosition]['name'] ?? ''),
                        'import_name' => $valueOf('name'),
                    ];
                    $conflictIds[$id] = true;
                }
                $shouldUpdate = $previewOnly
                    || $updateMode === 'all'
                    || ($updateMode === 'selected' && isset($selectedExisting[$exten]));
                if ($existingPosition !== null && !$shouldUpdate) {
                    $skipped++;
                    continue;
                }
                if ($technology === 'pjsip' && $password === '' && $existingPosition !== null) {
                    $password = (string) ($rows[$existingPosition]['password'] ?? '');
                }
                if ($technology === 'pjsip' && strlen($password) < 8) {
                    $errors[] = "{$lineNumber}. satır: PJSIP parolası en az 8 karakter olmalıdır";
                    continue;
                }
                $dahdiGroup = null;
                $dahdiDial = '';
                $customDial = '';
                if ($technology === 'dahdi') {
                    $rawDahdiDial = $valueOf('dahdi_dial');
                    $compoundDahdi = [];
                    $hasCompoundDahdi = preg_match(
                        '~^(?:DAHDI/)?g([0-9]{1,2})/([0-9]{1,20})$~iD',
                        $rawDahdiDial,
                        $compoundDahdi
                    ) === 1;
                    $submittedGroup = filter_var(
                        $hasCompoundDahdi ? $compoundDahdi[1] : $valueOf('dahdi_group'),
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 0, 'max_range' => 63]]
                    );
                    if ($submittedGroup === false) {
                        $errors[] = "{$lineNumber}. satır: DAHDI grubu 0-63 arasında olmalıdır";
                        continue;
                    }
                    $dahdiGroup = (int) $submittedGroup;
                    $dahdiDial = $hasCompoundDahdi ? $compoundDahdi[2] : $rawDahdiDial;
                    $dahdiDial = $dahdiDial !== '' ? $dahdiDial : $exten;
                    if (preg_match('/^[0-9]{1,20}$/D', $dahdiDial) !== 1) {
                        $errors[] = "{$lineNumber}. satır: DAHDI hedefi numara veya g3/201 biçiminde olmalıdır";
                        continue;
                    }
                } elseif ($technology === 'custom') {
                    $customDial = $valueOf('custom_dial');
                    if (
                        strlen($customDial) > 160
                        || preg_match('~^[A-Za-z][A-Za-z0-9_]*/[A-Za-z0-9_+*#@.:\-/]+$~D', $customDial) !== 1
                    ) {
                        $errors[] = "{$lineNumber}. satır: custom hedef geçersiz; örnek local/110@from-alcatel";
                        continue;
                    }
                }
                if ($existingPosition === null) {
                    $sip = '';
                    $auth = '';
                    if ($technology === 'pjsip') {
                        $sip = pick_sip_user($dept, $exten, $valueOf('sipuser') ?: $exten);
                        $baseSip = $sip;
                        $suffix = 2;
                        while (isset($sipOwners[$sip]) && $sipOwners[$sip] !== $id) {
                            $sip = $baseSip . '-' . $suffix++;
                        }
                        $auth = ast_sanitize_id($valueOf('authuser'));
                        if ($auth === '' || isset($authOwners[$auth])) {
                            do {
                                $auth = bin2hex(random_bytes(8));
                            } while (isset($authOwners[$auth]));
                        }
                        $authOwners[$auth] = true;
                        $sipOwners[$sip] = $id;
                    }
                    $defaultFallback = extension_default_fallback($dept);
                    $row = [
                        'id' => $id, 'dept' => $dept, 'exten' => $exten,
                        'technology' => $technology,
                        'sipuser' => $sip, 'authuser' => $auth,
                        'dahdi_group' => $dahdiGroup, 'dahdi_dial' => $dahdiDial,
                        'custom_dial' => $customDial,
                        'name' => $valueOf('name') ?: 'Abone ' . $exten,
                        'password' => $technology === 'pjsip' ? $password : '', 'max_contacts' => 1,
                        'codecs' => ['alaw', 'ulaw'], 'callerid' => $valueOf('name') ?: 'Abone ' . $exten,
                        'vm_enabled' => false, 'vm_pin' => (string) random_int(100000, 999999), 'vm_email' => '',
                        'cid_num' => '', 'record' => false, 'record_format' => 'wav',
                        'ringtime' => 30, 'followme' => '', 'fallback_type' => $defaultFallback['type'],
                        'fallback_dest' => $defaultFallback['dest'], 'dtmf' => 'rfc4733', 'language' => 'tr',
                        'transport' => 'udp', 'qualify' => 60, 'webrtc' => false,
                        'phone_modes' => [$technology === 'pjsip' ? 'softphone' : $technology],
                        'crm_trigger' => 'disabled',
                        'outbound_enabled' => false,
                    ];
                    $created++;
                } else {
                    $row = $rows[$existingPosition];
                    $row['technology'] = $technology;
                    $row['password'] = $technology === 'pjsip' ? $password : '';
                    $row['dahdi_group'] = $dahdiGroup;
                    $row['dahdi_dial'] = $dahdiDial;
                    $row['custom_dial'] = $customDial;
                    if ($technology === 'pjsip') {
                        $row['sipuser'] = ast_sanitize_id($valueOf('sipuser'))
                            ?: (string) ($row['sipuser'] ?? pick_sip_user($dept, $exten, $exten));
                        $row['authuser'] = ast_sanitize_id($valueOf('authuser'))
                            ?: (string) ($row['authuser'] ?? '');
                        if ($row['authuser'] === '') {
                            do {
                                $row['authuser'] = bin2hex(random_bytes(8));
                            } while (isset($authOwners[$row['authuser']]));
                        }
                    } else {
                        $row['sipuser'] = '';
                        $row['authuser'] = '';
                        $row['webrtc'] = false;
                        $row['phone_modes'] = [$technology];
                    }
                    if ($valueOf('name') !== '') {
                        $row['name'] = $valueOf('name');
                        $row['callerid'] = $valueOf('name');
                    }
                    $updated++;
                }
                if (isset($columns['outbound_enabled'])) {
                    $row['outbound_enabled'] = $booleanValue($valueOf('outbound_enabled'));
                }
                if (isset($columns['record'])) {
                    $row['record'] = $booleanValue($valueOf('record'));
                }
                if (isset($columns['fallback_type']) || isset($columns['fallback_dest'])) {
                    $submittedFallbackType = $normalizeHeader($valueOf('fallback_type'));
                    $submittedFallbackType = [
                        'abone' => 'extension',
                        'kuyruk' => 'queue',
                        'ring_grup' => 'ringgroup',
                        'zaman_kosulu' => 'time',
                        'duyuru' => 'announcement',
                        'konferans' => 'conference',
                        'gunduz_gece' => 'flow',
                        'anons_page' => 'paging',
                        'mesaj_kutusu' => 'voicemail',
                        'dis_numara' => 'external',
                        'ozel_hedef' => 'custom',
                        'kapat' => 'hangup',
                    ][$submittedFallbackType] ?? $submittedFallbackType;
                    if ($submittedFallbackType === '') {
                        $submittedFallbackType = extension_default_fallback($dept)['type'];
                    }
                    $fallbackType = dest_allowed($submittedFallbackType);
                    if ($fallbackType !== $submittedFallbackType) {
                        $errors[] = "{$lineNumber}. satır: cevaplanmazsa/meşgulse hedef türü geçersiz";
                        continue;
                    }
                    $submittedFallbackDest = $valueOf('fallback_dest');
                    $fallbackDest = dest_resolve_for_dept($dept, $fallbackType, $submittedFallbackDest);
                    if ($fallbackType !== 'hangup' && $fallbackDest === '') {
                        $errors[] = "{$lineNumber}. satır: cevaplanmazsa/meşgulse hedefi bulunamadı";
                        continue;
                    }
                    $row['fallback_type'] = $fallbackType;
                    $row['fallback_dest'] = $fallbackDest;
                }
                if ($technology === 'pjsip' && isset($columns['phone_mode'])) {
                    $mode = $normalizeHeader($valueOf('phone_mode'));
                    $mode = ['webphone' => 'webrtc', 'masaustu' => 'desk_phone'][$mode] ?? $mode;
                    if (!in_array($mode, ['webrtc', 'softphone', 'desk_phone'], true)) {
                        $errors[] = "{$lineNumber}. satır: telefon türü webrtc, softphone veya desk_phone olmalıdır";
                        continue;
                    }
                    $row['phone_modes'] = [$mode];
                    $row['webrtc'] = $mode === 'webrtc';
                }
                if (isset($columns['codecs']) && $valueOf('codecs') !== '') {
                    $codecs = preg_split('/[|,\s]+/', strtolower($valueOf('codecs'))) ?: [];
                    $codecs = array_values(array_intersect(['alaw', 'ulaw', 'gsm', 'g722', 'g726', 'g729', 'opus'], $codecs));
                    if (!$codecs) {
                        $errors[] = "{$lineNumber}. satır: geçerli kodek bulunamadı";
                        continue;
                    }
                    $row['codecs'] = $codecs;
                }
                if ($existingPosition === null) {
                    $rowIndexes[$id] = count($rows);
                    $rows[] = $row;
                } else {
                    $rows[$existingPosition] = $row;
                }
            }
            fclose($handle);
            if ($errors) {
                json_out([
                    'ok' => false,
                    'error' => implode("\n", array_slice($errors, 0, 10)),
                ], 422);
            }
            if ($previewOnly) {
                json_out([
                    'ok' => true,
                    'preview' => true,
                    'conflicts' => $conflicts,
                    'created' => $created,
                ]);
            }
            assert_extension_capacity($dept, $created);
            store_write('extensions', $rows);
            json_out(array_merge([
                'ok' => true, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped,
            ], apply_to_pbx()));

        case 'provision_device_save':
            $dept = require_dept_id();
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $existing = $id !== '' ? provisioning_device($id) : null;
            if ($existing) {
                assert_row_scope($existing);
                $dept = (string) $existing['dept'];
            }
            $mac = provisioning_mac((string) ($_POST['mac'] ?? ''));
            if ($mac === '') {
                json_out(['ok' => false, 'error' => 'MAC adresini 00:11:22:33:44:55 biçiminde girin'], 422);
            }
            foreach (provisioning_devices() as $other) {
                if ((string) $other['mac'] === $mac && (string) $other['id'] !== $id) {
                    json_out(['ok' => false, 'error' => 'Bu MAC adresi başka bir cihazda kayıtlı'], 409);
                }
            }
            $vendor = (string) ($_POST['vendor'] ?? '');
            if (!in_array($vendor, ['yealink', 'grandstream', 'gigaset'], true)) {
                json_out(['ok' => false, 'error' => 'Desteklenmeyen telefon markası'], 422);
            }
            $extensionId = ast_sanitize_id((string) ($_POST['extension_id'] ?? ''));
            $extension = find_by('extensions', 'id', $extensionId);
            if (!$extension || (string) ($extension['dept'] ?? '') !== $dept) {
                json_out(['ok' => false, 'error' => 'Seçilen dahili bu firmaya ait değil'], 422);
            }
            assert_row_scope($extension);
            $transport = (string) ($_POST['transport'] ?? 'udp');
            if (!in_array($transport, ['udp', 'tcp', 'tls'], true)) {
                $transport = 'udp';
            }
            $templateId = ast_sanitize_id((string) ($_POST['template_id'] ?? ''));
            if ($templateId === '') {
                $templateId = $vendor . '-generic';
            }
            $template = provisioning_template($templateId, $dept);
            if (!$template || (string) $template['vendor'] !== $vendor) {
                json_out(['ok' => false, 'error' => 'Seçilen telefon şablonu bu marka için geçerli değil'], 422);
            }
            $maxAccounts = max(1, (int) ($template['account_count'] ?? 1));
            $accountSlot = max(1, min($maxAccounts, (int) ($_POST['account_slot'] ?? 1)));
            if ($vendor !== 'yealink') {
                $accountSlot = 1;
            }
            $id = $id !== '' ? $id : 'prov-' . strtolower($mac);
            provisioning_save_device([
                'id' => $id,
                'dept' => $dept,
                'mac' => $mac,
                'vendor' => $vendor,
                'model' => substr(trim((string) ($_POST['model'] ?? '')), 0, 80),
                'extension_id' => $extensionId,
                'display_name' => substr(trim((string) ($_POST['display_name'] ?? '')), 0, 120),
                'transport' => $transport,
                'account_slot' => $accountSlot,
                'template_id' => $templateId,
                'enabled' => !empty($_POST['enabled']),
            ]);
            $fileCount = provisioning_publish_all();
            json_out(['ok' => true, 'id' => $id, 'file_count' => $fileCount]);

        case 'provision_schedule_save':
            $dept = require_dept_id();
            $frequency = (string) ($_POST['schedule_frequency'] ?? 'daily');
            if (!in_array($frequency, ['daily', 'weekly'], true)) {
                $frequency = 'daily';
            }
            $time = trim((string) ($_POST['schedule_time'] ?? '03:00'));
            if (
                preg_match('/^(\d{2}):(\d{2})$/D', $time, $timeParts) !== 1
                || (int) $timeParts[1] > 23
                || (int) $timeParts[2] > 59
            ) {
                json_out(['ok' => false, 'error' => 'Otomatik kontrol saati geçersiz'], 422);
            }
            provisioning_save_settings([
                'dept' => $dept,
                'schedule_enabled' => !empty($_POST['schedule_enabled']),
                'schedule_frequency' => $frequency,
                'schedule_weekday' => max(0, min(6, (int) ($_POST['schedule_weekday'] ?? 0))),
                'schedule_time' => $time,
                'window_minutes' => max(15, min(360, (int) ($_POST['window_minutes'] ?? 60))),
            ]);
            json_out(['ok' => true, 'file_count' => provisioning_publish_all()]);

        case 'provision_keys_save':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $device = provisioning_device($id);
            if (!$device) {
                json_out(['ok' => false, 'error' => 'Provision cihazı bulunamadı'], 404);
            }
            assert_row_scope($device);
            $template = provisioning_template(
                (string) ($device['template_id'] ?? ''),
                (string) $device['dept']
            );
            $maxKeys = max(0, min(120, (int) ($template['key_count'] ?? 0)));
            $keys = json_decode((string) ($_POST['keys'] ?? '[]'), true);
            if (!is_array($keys)) {
                json_out(['ok' => false, 'error' => 'Tuş listesi geçersiz'], 422);
            }
            $allowedTypes = ['none', 'line', 'blf', 'speed_dial', 'transfer', 'park', 'dtmf', 'url'];
            $clean = [];
            foreach ($keys as $key) {
                $index = (int) ($key['key_index'] ?? 0);
                $type = (string) ($key['key_type'] ?? 'none');
                if ($index < 1 || $index > $maxKeys || !in_array($type, $allowedTypes, true)) {
                    continue;
                }
                $clean[] = [
                    'key_index' => $index,
                    'key_type' => $type,
                    'label' => (string) ($key['label'] ?? ''),
                    'key_value' => (string) ($key['key_value'] ?? ''),
                    'extension' => (string) ($key['extension'] ?? ''),
                    'account_slot' => max(1, min(32, (int) ($key['account_slot'] ?? $device['account_slot'] ?? 1))),
                ];
            }
            provisioning_save_device_keys($id, (string) $device['dept'], $clean);
            json_out(['ok' => true, 'file_count' => provisioning_publish_all()]);

        case 'provision_template_save':
            $dept = require_dept_id();
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($id !== '' && isset(provisioning_builtin_templates()[$id])) {
                json_out(['ok' => false, 'error' => 'Hazır sistem şablonları değiştirilemez'], 422);
            }
            $id = $id !== '' ? $id : 'tpl-' . bin2hex(random_bytes(8));
            $name = substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
            if ($name === '') {
                json_out(['ok' => false, 'error' => 'Şablon adı zorunlu'], 422);
            }
            $vendor = (string) ($_POST['vendor'] ?? 'yealink');
            if (!in_array($vendor, ['yealink', 'grandstream', 'gigaset'], true)) {
                json_out(['ok' => false, 'error' => 'Şablon markası geçersiz'], 422);
            }
            provisioning_save_template([
                'id' => $id,
                'dept' => $dept,
                'name' => $name,
                'vendor' => $vendor,
                'model' => substr(trim((string) ($_POST['model'] ?? '')), 0, 80),
                'account_count' => max(1, min(32, (int) ($_POST['account_count'] ?? 1))),
                'key_count' => max(0, min(120, (int) ($_POST['key_count'] ?? 0))),
                'config_body' => substr((string) ($_POST['config_body'] ?? ''), 0, 65535),
            ]);
            json_out(['ok' => true, 'id' => $id]);

        case 'provision_template_delete':
            $dept = require_dept_id();
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($id === '' || isset(provisioning_builtin_templates()[$id])) {
                json_out(['ok' => false, 'error' => 'Hazır sistem şablonları silinemez'], 422);
            }
            provisioning_delete_template($id, $dept);
            json_out(['ok' => true, 'file_count' => provisioning_publish_all()]);

        case 'provision_device_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $device = provisioning_device($id);
            if ($device) {
                assert_row_scope($device);
                provisioning_delete_device($id);
            }
            json_out(['ok' => true, 'file_count' => provisioning_publish_all()]);

        case 'provision_publish':
            json_out(['ok' => true, 'file_count' => provisioning_publish_all()]);

        case 'pri_status':
            $result = pbx_ssh(
                "printf '%s\n' '--- dahdi_hardware ---'\n"
                . "(command -v dahdi_hardware >/dev/null && dahdi_hardware) || true\n"
                . "printf '%s\n' '--- dahdi_scan ---'\n"
                . "(command -v dahdi_scan >/dev/null && dahdi_scan) || true\n"
                . "printf '%s\n' '--- Asterisk DAHDI ---'\n"
                . "asterisk -rx 'module show like chan_dahdi.so' 2>&1 || true\n"
                . "asterisk -rx 'dahdi show status' 2>&1 || true\n"
                . "asterisk -rx 'dahdi show channels' 2>&1 || true"
            );
            $detectedPriSpans = pri_detected_spans((string) ($result['output'] ?? ''));
            if (!is_super()) {
                $tenantDept = (string) current_dept_id();
                $assignedSpans = [];
                $tenantSpans = [];
                foreach (store_read('pri_spans') as $configuredSpan) {
                    $configuredNumber = (int) ($configuredSpan['span'] ?? 0);
                    if ($configuredNumber < 1) continue;
                    $assignedSpans[$configuredNumber] = true;
                    if ((string) ($configuredSpan['dept'] ?? '') === $tenantDept) {
                        $tenantSpans[$configuredNumber] = true;
                    }
                }
                $detectedPriSpans = array_values(array_filter(
                    $detectedPriSpans,
                    static fn(array $span): bool =>
                        !isset($assignedSpans[(int) ($span['span'] ?? 0)])
                        || isset($tenantSpans[(int) ($span['span'] ?? 0)])
                ));
            }
            json_out([
                'ok' => true,
                'available' => str_contains((string) ($result['output'] ?? ''), '--- dahdi_scan ---'),
                'output' => (string) ($result['output'] ?? ''),
                'spans' => $detectedPriSpans,
            ]);

        case 'pri_span_save':
            $dept = require_dept_id();
            $span = max(1, min(32, (int) ($_POST['span'] ?? 1)));
            $scan = pbx_ssh("command -v dahdi_scan >/dev/null && dahdi_scan 2>/dev/null || true");
            preg_match_all('/^\[(\d+)\]$/m', (string) ($scan['output'] ?? ''), $detectedSpanMatches);
            $detectedSpans = array_map('intval', $detectedSpanMatches[1] ?? []);
            if ($detectedSpans && !in_array($span, $detectedSpans, true)) {
                json_out([
                    'ok' => false,
                    'error' => 'Fiziksel span bulunamadı. Algılanan span: ' . implode(', ', $detectedSpans),
                ], 422);
            }
            $id = ast_sanitize_id((string) ($_POST['id'] ?? '')) ?: 'pri-span-' . $span;
            $old = find_by('pri_spans', 'id', $id);
            if ($old) {
                assert_row_scope($old);
            }
            $bchannels = preg_replace('/\s+/', '', (string) ($_POST['bchannels'] ?? '1-15,17-31')) ?? '';
            $channelNumbers = pri_channel_numbers($bchannels);
            $dchannel = max(1, min(1024, (int) ($_POST['dchannel'] ?? 16)));
            if (in_array($dchannel, $channelNumbers, true)) {
                json_out(['ok' => false, 'error' => 'D-kanalı B-kanalı aralığında olamaz'], 422);
            }
            $group = max(0, min(63, (int) ($_POST['group'] ?? $span)));
            $signalling = (string) ($_POST['signalling'] ?? 'pri_cpe');
            if (!in_array($signalling, ['pri_cpe', 'pri_net'], true)) {
                json_out(['ok' => false, 'error' => 'Geçersiz PRI sinyalleşme rolü'], 422);
            }
            $switchtype = (string) ($_POST['switchtype'] ?? 'euroisdn');
            if (!in_array($switchtype, ['euroisdn', 'qsig', 'national', 'dms100', '4ess', '5ess'], true)) {
                json_out(['ok' => false, 'error' => 'Geçersiz PRI switch tipi'], 422);
            }
            $framing = (string) ($_POST['framing'] ?? 'ccs');
            $coding = (string) ($_POST['coding'] ?? 'hdb3');
            if (!in_array($framing, ['ccs', 'cas'], true) || !in_array($coding, ['hdb3', 'ami'], true)) {
                json_out(['ok' => false, 'error' => 'Geçersiz E1 framing/kodlama değeri'], 422);
            }
            $dialplan = (string) ($_POST['pridialplan'] ?? 'unknown');
            if (!in_array($dialplan, ['unknown', 'local', 'national', 'international', 'dynamic'], true)) {
                $dialplan = 'unknown';
            }
            $rxgain = max(-12.0, min(12.0, round((float) ($_POST['rxgain'] ?? 0) * 2) / 2));
            $txgain = max(-12.0, min(12.0, round((float) ($_POST['txgain'] ?? 0) * 2) / 2));
            $enabled = !empty($_POST['enabled']);
            foreach (store_read('pri_spans') as $other) {
                if ((string) ($other['id'] ?? '') === $id) {
                    continue;
                }
                if ((int) ($other['span'] ?? 0) === $span) {
                    json_out(['ok' => false, 'error' => 'Bu fiziksel span zaten tanımlı'], 422);
                }
                if ($enabled && !empty($other['enabled'])) {
                    $otherChannels = pri_channel_numbers((string) ($other['bchannels'] ?? ''));
                    $otherChannels[] = (int) ($other['dchannel'] ?? 0);
                    if (array_intersect([...$channelNumbers, $dchannel], $otherChannels)) {
                        json_out(['ok' => false, 'error' => 'DAHDI kanal aralığı başka bir etkin span ile çakışıyor'], 422);
                    }
                }
            }
            store_upsert('pri_spans', [
                'id' => $id,
                'dept' => $dept,
                'name' => substr(trim((string) ($_POST['name'] ?? 'PRI Span ' . $span)), 0, 120),
                'span' => $span,
                'group' => $group,
                'enabled' => $enabled,
                'timing' => max(0, min(31, (int) ($_POST['timing'] ?? 1))),
                'lbo' => max(0, min(7, (int) ($_POST['lbo'] ?? 0))),
                'framing' => $framing,
                'coding' => $coding,
                'crc4' => !empty($_POST['crc4']),
                'bchannels' => $bchannels,
                'dchannel' => $dchannel,
                'signalling' => $signalling,
                'switchtype' => $switchtype,
                'pridialplan' => $dialplan,
                'rxgain' => $rxgain,
                'txgain' => $txgain,
                'overlapdial' => !empty($_POST['overlapdial']),
                'echocancel' => !empty($_POST['echocancel']),
                'denoise' => !empty($_POST['denoise']),
                'updated_at' => date(DATE_ATOM),
                'updated_by' => store_actor(),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'pri_span_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $spanRow = find_by('pri_spans', 'id', $id);
            if (!$spanRow) {
                json_out(['ok' => false, 'error' => 'PRI spanı bulunamadı'], 404);
            }
            assert_row_scope($spanRow);
            foreach (store_read('outbound') as $route) {
                $routeTrunks = is_array($route['trunks'] ?? null)
                    ? array_map('strval', $route['trunks'])
                    : [(string) ($route['trunk'] ?? '')];
                if (in_array($id, $routeTrunks, true)) {
                    json_out(['ok' => false, 'error' => 'Bu PRI spanı bir giden arama kuralında kullanılıyor'], 409);
                }
            }
            store_delete('pri_spans', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'trunk_save':
            $dept = require_dept_id();
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $host = trim((string) ($_POST['host'] ?? ''));
            if ($id === '' || $host === '') {
                json_out(['ok' => false, 'error' => 'Trunk adı ve sunucu zorunlu']);
            }
            $old = find_by('trunks', 'id', $id);
            if ($old) {
                assert_row_scope($old);
            }
            $trunkType = ($_POST['type'] ?? 'register') === 'peer' ? 'peer' : 'register';
            $codecs = $_POST['codecs'] ?? ['ulaw', 'alaw', 'gsm', 'g726', 'g729', 'g722'];
            if (!is_array($codecs)) {
                $codecs = [$codecs];
            }
            $dtmfMode = (string) ($_POST['dtmf_mode'] ?? 'auto');
            if (!in_array($dtmfMode, ['auto', 'rfc4733', 'inband', 'info'], true)) {
                $dtmfMode = 'auto';
            }
            $mediaEncryption = (string) ($_POST['media_encryption'] ?? 'no');
            if (!in_array($mediaEncryption, ['no', 'sdes', 'dtls'], true)) {
                $mediaEncryption = 'no';
            }
            $cidOption = (string) ($_POST['cid_option'] ?? 'force_trunk');
            if (!in_array($cidOption, ['allow_any', 'block_foreign', 'remove_cnam', 'force_trunk'], true)) {
                $cidOption = 'force_trunk';
            }
            $outboundCallerId = preg_replace('/\D+/', '', (string) ($_POST['outbound_caller_id'] ?? '')) ?? '';
            $inboundMode = (string) ($_POST['inbound_mode'] ?? 'routes');
            if (!in_array($inboundMode, ['routes', 'direct'], true)) {
                $inboundMode = 'routes';
            }
            $qualifyEnabled = array_key_exists('qualify_enabled', $_POST)
                ? (string) $_POST['qualify_enabled'] !== 'no'
                : (!array_key_exists('qualify_frequency', $_POST)
                    || (int) $_POST['qualify_frequency'] > 0);
            $qualifyFrequency = $qualifyEnabled
                ? max(1, (int) ($_POST['qualify_frequency'] ?? 60))
                : 0;
            store_upsert('trunks', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $id)),
                'type' => $trunkType,
                'inbound_mode' => $inboundMode,
                'enabled' => $old ? ($old['enabled'] ?? true) : true,
                'host' => $host,
                'port' => (int) ($_POST['port'] ?? 5060) ?: 5060,
                'username' => trim((string) ($_POST['username'] ?? '')),
                'password' => (string) ($_POST['password'] ?? ''),
                'from_user' => trim((string) ($_POST['from_user'] ?? '')),
                'from_domain' => trim((string) ($_POST['from_domain'] ?? '')),
                'outbound_caller_id' => $outboundCallerId,
                'cid_option' => $cidOption,
                'codecs' => array_values($codecs),
                'dtmf_mode' => $dtmfMode,
                'direct_media' => !empty($_POST['direct_media']),
                'fax_detect' => !empty($_POST['fax_detect']),
                'media_encryption' => $mediaEncryption,
                'rewrite_contact' => !empty($_POST['rewrite_contact']),
                'rtp_symmetric' => !empty($_POST['rtp_symmetric']),
                'trust_id_inbound' => !empty($_POST['trust_id_inbound']),
                'support_path' => !empty($_POST['support_path']),
                'qualify_frequency' => $qualifyFrequency,
                'expiration' => max(60, (int) ($_POST['expiration'] ?? 3600)),
                'retry_interval' => max(1, (int) ($_POST['retry_interval'] ?? 60)),
                'max_retries' => max(0, (int) ($_POST['max_retries'] ?? 10)),
                'auth_rejection_permanent' => !empty($_POST['auth_rejection_permanent']),
                't38_udptl' => !empty($_POST['t38_udptl']),
                't38_udptl_nat' => !empty($_POST['t38_udptl_nat']),
            ], 'id');
            ensure_default_outbound($dept, $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'trunk_toggle':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('trunks', 'id', $id);
            if (!$row) {
                json_out(['ok' => false, 'error' => 'Trunk bulunamadı'], 404);
            }
            assert_row_scope($row);
            $wasEnabled = (bool) ($row['enabled'] ?? true);
            $row['enabled'] = !$wasEnabled;
            store_upsert('trunks', $row, 'id');
            $applyResult = apply_to_pbx();
            if ($wasEnabled && !$row['enabled']) {
                require_once __DIR__ . '/includes/alarms.php';
                astera_alarm_emit_event(
                    (string) ($row['dept'] ?? ''),
                    'trunk_error',
                    'Trunk devre dışı bırakıldı: ' . (string) ($row['name'] ?? $row['id']),
                    'Trunk: ' . (string) $row['id']
                        . "\nSunucu: " . (string) ($row['host'] ?? '')
                        . ':' . (int) ($row['port'] ?? 5060),
                    'warning'
                );
            }
            json_out(array_merge([
                'ok' => true,
                'enabled' => $row['enabled'],
            ], $applyResult));

        case 'trunk_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('trunks', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            foreach (store_read('outbound') as $route) {
                $routeTrunks = is_array($route['trunks'] ?? null)
                    ? array_map('strval', $route['trunks'])
                    : [(string) ($route['trunk'] ?? '')];
                if (in_array($id, $routeTrunks, true)) {
                    json_out(['ok' => false, 'error' => 'Bu trunk bir giden arama kuralında kullanılıyor'], 409);
                }
            }
            store_delete('trunks', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'outbound_save':
            $dept = require_dept_id();
            $id = ast_sanitize_id((string) ($_POST['id'] ?? '')) ?: ($dept . 'out' . substr(bin2hex(random_bytes(3)), 0, 6));
            $postedPatterns = json_decode((string) ($_POST['patterns_json'] ?? ''), true);
            $postedTrunks = json_decode((string) ($_POST['trunks_json'] ?? ''), true);
            if (!is_array($postedPatterns) || !array_is_list($postedPatterns)
                || !is_array($postedTrunks) || !array_is_list($postedTrunks)) {
                json_out(['ok' => false, 'error' => 'Giden rota bilgileri geçersiz'], 422);
            }
            $patterns = [];
            foreach ($postedPatterns as $index => $postedPattern) {
                if (!is_array($postedPattern)) {
                    json_out(['ok' => false, 'error' => 'Arama kalıbı geçersiz'], 422);
                }
                $prepend = preg_replace('/\D/', '', (string) ($postedPattern['prepend'] ?? '')) ?? '';
                $dialPrefix = preg_replace('/\D/', '', (string) ($postedPattern['prefix'] ?? '')) ?? '';
                $match = ast_dial_pattern((string) ($postedPattern['match'] ?? $postedPattern['pattern'] ?? ''));
                $callerId = ast_dial_pattern((string) ($postedPattern['caller_id'] ?? ''));
                if ($match === '') {
                    json_out(['ok' => false, 'error' => ($index + 1) . '. arama kalıbı geçersiz'], 422);
                }
                if (trim((string) ($postedPattern['caller_id'] ?? '')) !== '' && $callerId === '') {
                    json_out(['ok' => false, 'error' => ($index + 1) . '. arayan numara kalıbı geçersiz'], 422);
                }
                $patterns[] = [
                    'prepend' => $prepend,
                    'prefix' => $dialPrefix,
                    'match' => $match,
                    'caller_id' => $callerId,
                    'strip_count' => $dialPrefix === ''
                        ? max(0, (int) ($postedPattern['strip_count'] ?? 0))
                        : strlen($dialPrefix),
                ];
            }
            if (!$patterns) {
                json_out(['ok' => false, 'error' => 'En az bir arama kalıbı zorunlu'], 422);
            }
            $trunks = [];
            foreach ($postedTrunks as $postedTrunk) {
                $trunkId = ast_sanitize_id((string) $postedTrunk);
                if ($trunkId === '' || in_array($trunkId, $trunks, true)) {
                    continue;
                }
                $trunkRow = find_by('trunks', 'id', $trunkId) ?? find_by('pri_spans', 'id', $trunkId);
                if (!$trunkRow || (string) ($trunkRow['dept'] ?? '') !== $dept) {
                    json_out(['ok' => false, 'error' => 'Trunk bu firmaya ait değil: ' . $trunkId], 422);
                }
                $trunks[] = $trunkId;
            }
            if (!$trunks) {
                json_out(['ok' => false, 'error' => 'En az bir trunk zorunlu'], 422);
            }
            $firstPattern = $patterns[0];
            $matchBody = str_starts_with($firstPattern['match'], '_')
                ? substr($firstPattern['match'], 1)
                : $firstPattern['match'];
            $legacyPattern = $firstPattern['prefix'] . $matchBody;
            if (preg_match('/[XZN.!\[]/', $legacyPattern) === 1) {
                $legacyPattern = '_' . $legacyPattern;
            }
            store_upsert('outbound', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $legacyPattern)),
                'patterns' => $patterns,
                'trunks' => $trunks,
                'equal_distribution' => !empty($_POST['equal_distribution']),
                'pattern' => $legacyPattern,
                'strip' => (int) $firstPattern['strip_count'],
                'prefix' => (string) $firstPattern['prepend'],
                'trunk' => $trunks[0],
                'pin' => ast_sanitize_id((string) ($_POST['pin'] ?? '')),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'outbound_reorder':
            $dept = require_dept_id();
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $direction = strtolower((string) ($_POST['direction'] ?? ''));
            if ($id === '' || !in_array($direction, ['up', 'down'], true)) {
                json_out(['ok' => false, 'error' => 'Geçersiz sıralama isteği'], 422);
            }
            $outboundRows = store_read('outbound');
            $targetIndex = null;
            $deptIndexes = [];
            foreach ($outboundRows as $index => $outboundRow) {
                if ((string) ($outboundRow['dept'] ?? '') !== $dept) {
                    continue;
                }
                $deptIndexes[] = $index;
                if ((string) ($outboundRow['id'] ?? '') === $id) {
                    $targetIndex = $index;
                }
            }
            if ($targetIndex === null) {
                json_out(['ok' => false, 'error' => 'Giden arama kuralı bulunamadı'], 404);
            }
            $position = array_search($targetIndex, $deptIndexes, true);
            $swapPosition = $direction === 'up' ? $position - 1 : $position + 1;
            if (!isset($deptIndexes[$swapPosition])) {
                json_out(['ok' => true, 'moved' => false]);
            }
            $swapIndex = $deptIndexes[$swapPosition];
            [$outboundRows[$targetIndex], $outboundRows[$swapIndex]] = [
                $outboundRows[$swapIndex],
                $outboundRows[$targetIndex],
            ];
            store_write('outbound', array_values($outboundRows));
            json_out(array_merge(['ok' => true, 'moved' => true], apply_to_pbx()));

        case 'outbound_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('outbound', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('outbound', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'inbound_save':
            $dept = require_dept_id();
            $id = ast_sanitize_id((string) ($_POST['id'] ?? '')) ?: ($dept . 'in' . substr(bin2hex(random_bytes(3)), 0, 6));
            $did = ast_dial_pattern((string) ($_POST['did'] ?? ''));
            $callerIdRaw = trim((string) ($_POST['caller_id'] ?? ''));
            $callerId = $callerIdRaw === '' ? '' : ast_dial_pattern($callerIdRaw);
            $destType = dest_allowed((string) ($_POST['dest_type'] ?? 'extension'));
            $dest = ast_sanitize_id((string) ($_POST['dest'] ?? ''));
            if ($did === '' || ($dest === '' && $destType !== 'hangup')) {
                json_out(['ok' => false, 'error' => 'Geçerli DID ve hedef zorunlu'], 422);
            }
            if ($callerIdRaw !== '' && $callerId === '') {
                json_out(['ok' => false, 'error' => 'Arayan numara kalıbı geçersiz'], 422);
            }
            foreach (store_read('inbound') as $otherRoute) {
                if (
                    (string) ($otherRoute['id'] ?? '') !== $id
                    && (string) ($otherRoute['dept'] ?? '') === $dept
                    && (string) ($otherRoute['did'] ?? '') === $did
                    && (string) ($otherRoute['caller_id'] ?? '') === $callerId
                ) {
                    json_out([
                        'ok' => false,
                        'error' => 'Aynı DID ve arayan numara kalıbına sahip başka bir gelen kural var',
                    ], 409);
                }
            }
            $recordMode = (string) ($_POST['record_mode'] ?? 'inherit');
            if (!in_array($recordMode, ['inherit', 'force', 'off'], true)) {
                $recordMode = 'inherit';
            }
            store_upsert('inbound', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $did)),
                'did' => $did,
                'caller_id' => $callerId,
                'priority' => max(0, min(9999, (int) ($_POST['priority'] ?? 100))),
                'cid_prefix' => str_replace(["\r", "\n"], '', trim((string) ($_POST['cid_prefix'] ?? ''))),
                'alert_info' => str_replace(["\r", "\n"], '', trim((string) ($_POST['alert_info'] ?? ''))),
                'musicclass' => ast_sanitize_id((string) ($_POST['musicclass'] ?? dept_code_of($dept))) ?: dept_code_of($dept),
                'language' => ast_sanitize_id((string) ($_POST['language'] ?? 'tr')) ?: 'tr',
                'signal_ringing' => !empty($_POST['signal_ringing']),
                'pause_before_answer' => max(0, min(30, (int) ($_POST['pause_before_answer'] ?? 0))),
                'privacy_manager' => !empty($_POST['privacy_manager']),
                'record_mode' => $recordMode,
                'dest_type' => $destType,
                'dest' => $dest,
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'inbound_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('inbound', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('inbound', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'queue_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($local === '') {
                json_out(['ok' => false, 'error' => 'Kuyruk kimliği zorunlu']);
            }
            $id = str_starts_with($local, $dept . '-') ? $local : ($dept . '-' . $local);
            $oldQueue = find_by('queues', 'id', $id);
            $exten = preg_replace('/\D/', '', (string) ($_POST['exten'] ?? '')) ?? '';
            if ($exten === '') {
                $exten = preg_replace('/\D/', '', (string) ($oldQueue['exten'] ?? '')) ?? '';
            }
            if ($exten === '') {
                $exten = next_available_dial_code($dept, 8000, 8499);
            }
            if ($exten === '') {
                json_out(['ok' => false, 'error' => '8000-8499 aralığında kullanılabilir kuyruk numarası kalmadı'], 422);
            }
            $usages = dial_code_usages($dept, $exten, 'queues', $id);
            if ($usages) {
                json_out(['ok' => false, 'error' => $exten . ' kodu kullanılıyor: ' . implode(', ', $usages)], 422);
            }
            $members = $_POST['members'] ?? [];
            if (!is_array($members)) {
                $members = array_filter(array_map('trim', explode(',', (string) $members)));
            }
            $members = array_values(array_unique(array_filter(array_map(
                static fn($member) => normalize_group_member((string) $member),
                $members
            ))));
            foreach ($members as $member) {
                if (!group_member_is_external($member) && find_ext($dept, $member) === null) {
                    json_out([
                        'ok' => false,
                        'error' => 'Kuyruk üyesi geçersiz veya bu firmada kayıtlı değil: ' . $member,
                    ], 422);
                }
            }
            $postedPenalties = json_decode((string) ($_POST['member_penalties_json'] ?? '[]'), true);
            $penaltyByMember = [];
            if (is_array($postedPenalties)) {
                foreach ($postedPenalties as $penaltyRow) {
                    if (!is_array($penaltyRow)) {
                        continue;
                    }
                    $penaltyMember = normalize_group_member((string) ($penaltyRow['member'] ?? ''));
                    if ($penaltyMember !== '' && in_array($penaltyMember, $members, true)) {
                        $penaltyByMember[$penaltyMember] = max(
                            1,
                            min(99, (int) ($penaltyRow['penalty'] ?? 1))
                        );
                    }
                }
            }
            $memberPenalties = array_map(
                static fn(string $member): array => [
                    'member' => $member,
                    'penalty' => (int) ($penaltyByMember[$member] ?? 1),
                ],
                $members
            );
            $strategy = ast_sanitize_id((string) ($_POST['strategy'] ?? 'ringall')) ?: 'ringall';
            if (!array_key_exists($strategy, ring_strategies())) {
                $strategy = 'ringall';
            }
            $joinempty = (string) ($_POST['joinempty'] ?? 'yes');
            if (!in_array($joinempty, ['yes', 'no', 'strict'], true)) {
                $joinempty = 'yes';
            }
            $leavewhenempty = (string) ($_POST['leavewhenempty'] ?? 'no');
            if (!in_array($leavewhenempty, ['yes', 'no', 'strict'], true)) {
                $leavewhenempty = 'no';
            }
            $autopause = (string) ($_POST['autopause'] ?? 'no');
            if (!in_array($autopause, ['yes', 'no', 'all'], true)) {
                $autopause = 'no';
            }
            $holdtime = (string) ($_POST['announce_holdtime'] ?? 'no');
            if (!in_array($holdtime, ['yes', 'no', 'once'], true)) {
                $holdtime = 'no';
            }
            $round = (int) ($_POST['announce_round_seconds'] ?? 10);
            if (!in_array($round, [0, 5, 10, 15, 20, 30], true)) {
                $round = 10;
            }
            $announce = normalize_sound_name((string) ($_POST['announce'] ?? ''));
            $periodicAnnounce = normalize_sound_name((string) ($_POST['periodic_announce'] ?? ''));
            if (!tenant_sound_allowed($dept, $announce) || !tenant_sound_allowed($dept, $periodicAnnounce)) {
                json_out(['ok' => false, 'error' => 'Seçilen ses bu firmaya ait değil'], 422);
            }
            $recordFormat = strtolower((string) ($_POST['record_format'] ?? 'wav'));
            if (!in_array($recordFormat, ['wav', 'gsm', 'ulaw'], true)) {
                $recordFormat = 'wav';
            }
            store_upsert('queues', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'language' => ast_sanitize_id((string) ($_POST['language'] ?? 'tr')) ?: 'tr',
                'record' => !empty($_POST['record']),
                'record_format' => $recordFormat,
                'exten' => $exten,
                'strategy' => $strategy,
                'timeout' => max(5, (int) ($_POST['timeout'] ?? 20)),
                'retry' => max(1, min(60, (int) ($_POST['retry'] ?? 5))),
                'wrapuptime' => max(0, (int) ($_POST['wrapuptime'] ?? 5)),
                'maxlen' => max(0, (int) ($_POST['maxlen'] ?? 0)),
                'queue_timeout' => max(0, min(86400, (int) ($_POST['queue_timeout'] ?? 300))),
                'servicelevel' => max(1, min(86400, (int) ($_POST['servicelevel'] ?? 60))),
                'weight' => max(0, min(100, (int) ($_POST['weight'] ?? 0))),
                'memberdelay' => max(0, min(60, (int) ($_POST['memberdelay'] ?? 0))),
                'musicclass' => ast_sanitize_id((string) ($_POST['musicclass'] ?? dept_code_of($dept))) ?: dept_code_of($dept),
                'cid_prefix' => trim((string) ($_POST['cid_prefix'] ?? '')),
                'joinempty' => $joinempty,
                'leavewhenempty' => $leavewhenempty,
                'ringinuse' => !empty($_POST['ringinuse']),
                'autofill' => !empty($_POST['autofill']),
                'autopause' => $autopause,
                'autopausebusy' => !empty($_POST['autopausebusy']),
                'autopausenoanswer' => !empty($_POST['autopausenoanswer']),
                'autopauseunavailable' => !empty($_POST['autopauseunavailable']),
                'autopausedelay' => max(0, min(3600, (int) ($_POST['autopausedelay'] ?? 0))),
                'timeoutrestart' => !empty($_POST['timeoutrestart']),
                'shared_lastcall' => !empty($_POST['shared_lastcall']),
                'caller_transfer' => !empty($_POST['caller_transfer']),
                'agent_transfer' => !empty($_POST['agent_transfer']),
                'announce' => $announce,
                'announce_position' => !empty($_POST['announce_position']),
                'announce_frequency' => max(0, min(3600, (int) ($_POST['announce_frequency'] ?? 60))),
                'min_announce_frequency' => max(0, min(3600, (int) ($_POST['min_announce_frequency'] ?? 15))),
                'announce_holdtime' => $holdtime,
                'announce_round_seconds' => $round,
                'announce_position_limit' => max(0, min(999, (int) ($_POST['announce_position_limit'] ?? 0))),
                'periodic_announce' => $periodicAnnounce,
                'periodic_announce_frequency' => max(0, min(86400, (int) ($_POST['periodic_announce_frequency'] ?? 60))),
                'relative_periodic_announce' => !empty($_POST['relative_periodic_announce']),
                'announce_to_first_user' => !empty($_POST['announce_to_first_user']),
                'reportholdtime' => !empty($_POST['reportholdtime']),
                'timeout_type' => dest_allowed((string) ($_POST['timeout_type'] ?? 'hangup')),
                'timeout_dest' => ast_sanitize_id((string) ($_POST['timeout_dest'] ?? '')),
                'members' => array_values($members),
                'member_penalties' => array_values($memberPenalties),
                'dial_aliases' => array_values((array) ($oldQueue['dial_aliases'] ?? [])),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'queue_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('queues', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('queues', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'ringgroup_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $existing = $local !== '' ? find_by('ringgroups', 'id', $local) : null;
            if (!$existing && $local !== '' && !str_starts_with($local, $dept . '-')) {
                $existing = find_by('ringgroups', 'id', $dept . '-' . $local);
            }
            if ($existing) {
                assert_row_scope($existing);
                $id = (string) ($existing['id'] ?? '');
            } else {
                $id = '';
            }
            $exten = preg_replace('/\D/', '', (string) ($existing['exten'] ?? '')) ?? '';
            if ($exten === '') {
                $exten = next_available_dial_code($dept, 8600, 9999);
            }
            if ($exten === '') {
                json_out(['ok' => false, 'error' => '8600-9999 aralığında kullanılabilir ring grup numarası kalmadı'], 422);
            }
            $usages = dial_code_usages($dept, $exten, 'ringgroups', $id);
            if ($usages) {
                json_out(['ok' => false, 'error' => $exten . ' kodu kullanılıyor: ' . implode(', ', $usages)], 422);
            }
            if ($id === '') {
                $id = $dept . '-ringgroup-' . $exten;
            }
            $members = $_POST['members'] ?? [];
            if (!is_array($members)) {
                $members = array_filter(array_map('trim', explode(',', (string) $members)));
            }
            $allowedMembers = [];
            foreach (store_read('extensions') as $extension) {
                if ((string) ($extension['dept'] ?? '') === $dept) {
                    $allowedMembers[] = (string) ($extension['exten'] ?? '');
                }
            }
            $members = array_values(array_unique(array_filter(array_map(
                static fn ($member) => normalize_group_member((string) $member),
                $members
            ))));
            foreach ($members as $member) {
                if (!group_member_is_external($member) && !in_array($member, $allowedMembers, true)) {
                    json_out([
                        'ok' => false,
                        'error' => 'Ring grup üyesi geçersiz veya bu firmada kayıtlı değil: ' . $member,
                    ], 422);
                }
            }
            if (!$members) {
                json_out(['ok' => false, 'error' => 'Seçilen firmaya ait en az bir abone seçin']);
            }
            $postedPriorities = json_decode((string) ($_POST['member_priorities_json'] ?? '[]'), true);
            $priorityByMember = [];
            if (is_array($postedPriorities)) {
                foreach ($postedPriorities as $priorityRow) {
                    if (!is_array($priorityRow)) {
                        continue;
                    }
                    $priorityMember = normalize_group_member((string) ($priorityRow['member'] ?? ''));
                    if ($priorityMember !== '' && in_array($priorityMember, $members, true)) {
                        $priorityByMember[$priorityMember] = max(
                            1,
                            min(99, (int) ($priorityRow['priority'] ?? 1))
                        );
                    }
                }
            }
            $memberPriorities = array_map(
                static fn(string $member): array => [
                    'member' => $member,
                    'priority' => (int) ($priorityByMember[$member] ?? 1),
                ],
                $members
            );
            $recordFormat = strtolower((string) ($_POST['record_format'] ?? 'wav'));
            if (!in_array($recordFormat, ['wav', 'gsm', 'ulaw'], true)) {
                $recordFormat = 'wav';
            }
            $announcement = normalize_sound_name((string) ($_POST['announcement'] ?? ''));
            if (!tenant_sound_allowed($dept, $announcement)) {
                json_out(['ok' => false, 'error' => 'Seçilen ses bu firmaya ait değil'], 422);
            }
            store_upsert('ringgroups', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $exten)),
                'description' => trim((string) ($_POST['description'] ?? '')),
                'language' => ast_sanitize_id((string) ($_POST['language'] ?? 'tr')) ?: 'tr',
                'exten' => $exten,
                'strategy' => ($_POST['strategy'] ?? 'ringall') === 'hunt' ? 'hunt' : 'ringall',
                'ring_time' => max(5, (int) ($_POST['ring_time'] ?? 20)),
                'confirm_calls' => !empty($_POST['confirm_calls']),
                'announcement' => $announcement,
                'music_class' => trim((string) ($_POST['music_class'] ?? dept_code_of($dept))) ?: dept_code_of($dept),
                'cid_prefix' => trim((string) ($_POST['cid_prefix'] ?? '')),
                'skip_busy' => !empty($_POST['skip_busy']),
                'record' => !empty($_POST['record']),
                'record_format' => $recordFormat,
                'timeout' => max(5, (int) ($_POST['timeout'] ?? 25)),
                'timeout_type' => dest_allowed((string) ($_POST['timeout_type'] ?? 'hangup')),
                'timeout_dest' => ast_sanitize_id((string) ($_POST['timeout_dest'] ?? '')),
                'members' => array_values($members),
                'member_priorities' => array_values($memberPriorities),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'ringgroup_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('ringgroups', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('ringgroups', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'recordings':
            json_out(['ok' => true, 'files' => pbx_recordings(current_dept_id())]);

        case 'ivr_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $existing = $local !== '' ? find_by('ivrs', 'id', $local) : null;
            if (!$existing && $local !== '' && !str_starts_with($local, $dept . '-')) {
                $existing = find_by('ivrs', 'id', $dept . '-' . $local);
            }
            if ($existing) {
                assert_row_scope($existing);
                $id = (string) ($existing['id'] ?? '');
            } else {
                $id = '';
            }
            $exten = preg_replace('/\D/', '', (string) ($existing['exten'] ?? '')) ?? '';
            if ($exten === '') {
                $exten = next_available_dial_code($dept);
            }
            if ($exten === '') {
                json_out(['ok' => false, 'error' => '8500-9999 aralığında kullanılabilir IVR numarası kalmadı'], 422);
            }
            $usages = dial_code_usages($dept, $exten, 'ivrs', $id);
            if ($usages) {
                json_out(['ok' => false, 'error' => $exten . ' kodu kullanılıyor: ' . implode(', ', $usages)], 422);
            }
            if ($id === '') {
                $id = $dept . '-ivr-' . $exten;
            }
            $digits = json_decode((string) ($_POST['digits_json'] ?? '{}'), true);
            if (!is_array($digits)) {
                $digits = [];
            }
            $validatedDigits = [];
            foreach ($digits as $digit => $target) {
                $digit = trim((string) $digit);
                if (!preg_match('/^[0-9*#]{1,4}$/', $digit)) {
                    json_out(['ok' => false, 'error' => 'IVR tuşlaması 1-4 haneli; yalnızca 0-9, * ve # olabilir'], 422);
                }
                if (isset($validatedDigits[$digit])) {
                    json_out(['ok' => false, 'error' => 'Aynı IVR tuşlaması birden fazla kullanılamaz: ' . $digit], 422);
                }
                $targetType = dest_allowed((string) ($target['type'] ?? 'hangup'));
                $targetDest = ast_sanitize_id((string) ($target['dest'] ?? ''));
                if (!dest_belongs_to_dept($dept, $targetType, $targetDest)) {
                    json_out(['ok' => false, 'error' => $digit . ' tuşunun hedefi bu firmaya ait değil'], 422);
                }
                $validatedDigits[$digit] = ['type' => $targetType, 'dest' => $targetDest];
                if (count($validatedDigits) > 50) {
                    json_out(['ok' => false, 'error' => 'En fazla 50 IVR tuşlaması eklenebilir'], 422);
                }
            }
            $blockedExtensions = json_decode((string) ($_POST['blocked_extensions_json'] ?? '[]'), true);
            if (!is_array($blockedExtensions)) {
                $blockedExtensions = [];
            }
            $deptExtensions = [];
            foreach (store_read('extensions') as $extension) {
                if ((string) ($extension['dept'] ?? '') === $dept) {
                    $number = ast_sanitize_id((string) ($extension['exten'] ?? ''));
                    if ($number !== '') {
                        $deptExtensions[$number] = true;
                    }
                }
            }
            $blockedExtensions = array_values(array_unique(array_filter(
                array_map(static fn($number) => ast_sanitize_id((string) $number), $blockedExtensions),
                static fn(string $number): bool => isset($deptExtensions[$number])
            )));
            $blockedType = ast_sanitize_id((string) ($_POST['blocked_type'] ?? 'hangup')) ?: 'hangup';
            $blockedDest = ast_sanitize_id((string) ($_POST['blocked_dest'] ?? ''));
            if ($blockedExtensions && !dest_belongs_to_dept($dept, $blockedType, $blockedDest)) {
                json_out(['ok' => false, 'error' => 'Yasaklı dahili hedefi bu firmaya ait değil'], 422);
            }
            $sound = normalize_sound_name((string) ($_POST['sound'] ?? 'hello-world')) ?: 'hello-world';
            if (!tenant_sound_allowed($dept, $sound)) {
                json_out(['ok' => false, 'error' => 'Seçilen ses bu firmaya ait değil'], 422);
            }
            $timeoutType = dest_allowed((string) ($_POST['timeout_type'] ?? 'hangup'));
            $timeoutDest = ast_sanitize_id((string) ($_POST['timeout_dest'] ?? ''));
            if (!dest_belongs_to_dept($dept, $timeoutType, $timeoutDest)) {
                json_out(['ok' => false, 'error' => 'Zaman aşımı hedefi bu firmaya ait değil'], 422);
            }
            $invalidTypeRaw = (string) ($_POST['invalid_type'] ?? 'repeat');
            $invalidType = $invalidTypeRaw === 'repeat' ? 'repeat' : dest_allowed($invalidTypeRaw);
            $invalidDest = ast_sanitize_id((string) ($_POST['invalid_dest'] ?? ''));
            if ($invalidType !== 'repeat' && !dest_belongs_to_dept($dept, $invalidType, $invalidDest)) {
                json_out(['ok' => false, 'error' => 'Geçersiz tuşlama hedefi bu firmaya ait değil'], 422);
            }
            $ivrRow = [
                'id' => $id,
                'dept' => $dept,
                'exten' => $exten,
                'name' => trim((string) ($_POST['name'] ?? $exten)),
                'language' => ast_sanitize_id((string) ($_POST['language'] ?? 'tr')) ?: 'tr',
                'sound' => $sound,
                'timeout_type' => $timeoutType,
                'timeout_dest' => $timeoutDest,
                'invalid_type' => $invalidType,
                'invalid_dest' => $invalidDest,
                'direct_dial' => !empty($_POST['direct_dial']),
                'blocked_extensions' => $blockedExtensions,
                'blocked_type' => $blockedType,
                'blocked_dest' => $blockedDest,
                'digits' => $validatedDigits,
            ];
            if ($existing) {
                $changed = false;
                foreach ($ivrRow as $field => $value) {
                    if (
                        json_encode($existing[$field] ?? null, JSON_UNESCAPED_UNICODE)
                        !== json_encode($value, JSON_UNESCAPED_UNICODE)
                    ) {
                        $changed = true;
                        break;
                    }
                }
                if (!$changed) {
                    json_out([
                        'ok' => true,
                        'unchanged' => true,
                        'pending' => false,
                        'pending_count' => store_pending_count(),
                        'output' => 'Değişiklik yapılmadı.',
                    ]);
                }
            }
            store_upsert('ivrs', $ivrRow, 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'ivr_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('ivrs', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('ivrs', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'time_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($local === '') {
                for ($sequence = 1; $sequence <= 9999; $sequence++) {
                    $candidate = $dept . '-mesai-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
                    if (!find_by('timeconditions', 'id', $candidate)) {
                        $local = $candidate;
                        break;
                    }
                }
                if ($local === '') {
                    json_out(['ok' => false, 'error' => 'Otomatik zaman koşulu kodu üretilemedi'], 500);
                }
            }
            $id = str_starts_with($local, $dept . '-') ? $local : ($dept . '-' . $local);
            $rulesInput = json_decode((string) ($_POST['rules_json'] ?? '[]'), true);
            $rules = [];
            $validDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
            foreach (is_array($rulesInput) ? array_slice($rulesInput, 0, 30) : [] as $rule) {
                $start = (string) ($rule['start'] ?? '');
                $end = (string) ($rule['end'] ?? '');
                $days = array_values(array_intersect($validDays, (array) ($rule['days'] ?? [])));
                if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || !$days) {
                    continue;
                }
                $rules[] = [
                    'name' => trim((string) ($rule['name'] ?? 'Çalışma aralığı')),
                    'start' => $start,
                    'end' => $end,
                    'days' => $days,
                ];
            }
            if (!$rules) {
                json_out(['ok' => false, 'error' => 'En az bir çalışma aralığı ve gün seçmelisiniz'], 422);
            }
            $exceptionsInput = json_decode((string) ($_POST['exceptions_json'] ?? '[]'), true);
            $exceptions = [];
            foreach (is_array($exceptionsInput) ? array_slice($exceptionsInput, 0, 100) : [] as $exception) {
                $from = (string) ($exception['from'] ?? '');
                $to = (string) ($exception['to'] ?? $from);
                $time = (string) ($exception['time'] ?? '00:00-23:59');
                $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
                $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
                if (!$fromDate || !$toDate || $toDate < $fromDate || $fromDate->diff($toDate)->days > 366
                    || !preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $time)) {
                    json_out(['ok' => false, 'error' => 'Özel tarih veya saat aralığı geçersiz'], 422);
                }
                $exceptions[] = [
                    'name' => trim((string) ($exception['name'] ?? 'Özel tarih')),
                    'from' => $from,
                    'to' => $to,
                    'state' => ($exception['state'] ?? 'closed') === 'open' ? 'open' : 'closed',
                    'time' => $time,
                ];
            }
            $timezone = (string) ($_POST['timezone'] ?? 'Europe/Istanbul');
            if (!in_array($timezone, ['Europe/Istanbul', 'UTC', 'Europe/London', 'Europe/Berlin'], true)) {
                $timezone = 'Europe/Istanbul';
            }
            $legacyDays = implode('&', $rules[0]['days']);
            $trueType = ast_sanitize_id((string) ($_POST['true_type'] ?? 'hangup')) ?: 'hangup';
            $trueDest = ast_sanitize_id((string) ($_POST['true_dest'] ?? ''));
            $falseType = ast_sanitize_id((string) ($_POST['false_type'] ?? 'hangup')) ?: 'hangup';
            $falseDest = ast_sanitize_id((string) ($_POST['false_dest'] ?? ''));
            if (!dest_belongs_to_dept($dept, $trueType, $trueDest)
                || !dest_belongs_to_dept($dept, $falseType, $falseDest)) {
                json_out(['ok' => false, 'error' => 'Zaman koşulu hedefi seçili firmaya ait değil'], 422);
            }
            $name = trim((string) ($_POST['name'] ?? ''));
            store_upsert('timeconditions', [
                'id' => $id,
                'dept' => $dept,
                'name' => $name !== '' ? $name : $local,
                'timezone' => $timezone,
                'rules' => $rules,
                'exceptions' => $exceptions,
                'turkey_holidays' => !empty($_POST['turkey_holidays']),
                'turkey_half_days' => !empty($_POST['turkey_half_days']),
                'time' => $rules[0]['start'] . '-' . $rules[0]['end'],
                'days' => $legacyDays,
                'true_type' => $trueType,
                'true_dest' => $trueDest,
                'false_type' => $falseType,
                'false_dest' => $falseDest,
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'time_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('timeconditions', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('timeconditions', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'announcement_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($local === '') {
                json_out(['ok' => false, 'error' => 'Kod zorunlu']);
            }
            $id = str_starts_with($local, $dept . '-') ? $local : ($dept . '-' . $local);
            $sound = normalize_sound_name((string) ($_POST['sound'] ?? 'hello-world')) ?: 'hello-world';
            if (!tenant_sound_allowed($dept, $sound)) {
                json_out(['ok' => false, 'error' => 'Seçilen ses bu firmaya ait değil'], 422);
            }
            store_upsert('announcements', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'language' => ast_sanitize_id((string) ($_POST['language'] ?? 'tr')) ?: 'tr',
                'sound' => $sound,
                'dest_type' => ast_sanitize_id((string) ($_POST['dest_type'] ?? 'hangup')) ?: 'hangup',
                'dest' => ast_sanitize_id((string) ($_POST['dest'] ?? '')),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'announcement_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('announcements', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('announcements', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'conference_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $exten = preg_replace('/\D/', '', (string) ($_POST['exten'] ?? '')) ?? '';
            if ($local === '' || $exten === '') {
                json_out(['ok' => false, 'error' => 'Kod ve dahili zorunlu']);
            }
            $id = str_starts_with($local, $dept . '-') ? $local : ($dept . '-' . $local);
            $recordFormat = strtolower((string) ($_POST['record_format'] ?? 'wav'));
            if (!in_array($recordFormat, ['wav', 'gsm', 'ulaw'], true)) {
                $recordFormat = 'wav';
            }
            store_upsert('conferences', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'exten' => $exten,
                'pin' => ast_sanitize_id((string) ($_POST['pin'] ?? '')),
                'record' => !empty($_POST['record']),
                'record_format' => $recordFormat,
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'conference_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('conferences', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('conferences', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'blacklist_save':
            $dept = require_dept_id();
            $number = preg_replace('/\D+/', '', (string) ($_POST['number'] ?? '')) ?? '';
            if (strlen($number) < 3) {
                json_out(['ok' => false, 'error' => 'Numara çok kısa']);
            }
            store_upsert('blacklist', [
                'id' => $dept . '-' . $number,
                'dept' => $dept,
                'number' => $number,
                'name' => trim((string) ($_POST['name'] ?? $number)),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'blacklist_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('blacklist', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('blacklist', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'bulk_extensions':
            $dept = require_dept_id();
            $start = (int) ($_POST['start'] ?? 0);
            $end = (int) ($_POST['end'] ?? 0);
            $technology = strtolower(trim((string) ($_POST['technology'] ?? 'pjsip')));
            if (!in_array($technology, ['pjsip', 'dahdi', 'custom'], true)) {
                json_out(['ok' => false, 'error' => 'Geçersiz abone bağlantı türü'], 422);
            }
            $pass = trim((string) ($_POST['password'] ?? ''));
            if ($start < 100 || $end < $start || ($end - $start + 1) > 50) {
                json_out(['ok' => false, 'error' => 'Aralık 100+ ve en fazla 50 dahili']);
            }
            if ($technology === 'pjsip' && $pass === '') {
                $pass = 'Ast!' . bin2hex(random_bytes(6));
            }
            if ($technology === 'pjsip' && strlen($pass) < 8) {
                json_out(['ok' => false, 'error' => 'Abone parolası en az 8 karakter olmalıdır'], 422);
            }
            $dahdiGroup = null;
            if ($technology === 'dahdi') {
                $submittedGroup = trim((string) ($_POST['dahdi_group'] ?? ''));
                $groupMatch = [];
                if (preg_match('/^g?([0-9]{1,2})$/iD', $submittedGroup, $groupMatch) !== 1) {
                    json_out(['ok' => false, 'error' => 'DAHDI grubunu 2 veya g2 biçiminde girin'], 422);
                }
                $dahdiGroup = (int) $groupMatch[1];
                if ($dahdiGroup > 63) {
                    json_out(['ok' => false, 'error' => 'DAHDI grubu 0-63 arasında olmalıdır'], 422);
                }
            }
            $customTemplate = trim((string) ($_POST['custom_dial_template'] ?? ''));
            if (
                $technology === 'custom'
                && !str_contains($customTemplate, '{exten}')
                && preg_match('/xxx/i', $customTemplate) !== 1
            ) {
                json_out([
                    'ok' => false,
                    'error' => 'Custom Trunk şablonunda xxx veya {exten} bulunmalıdır',
                ], 422);
            }
            $extensionRows = store_read('extensions');
            $existingIds = array_fill_keys(array_map(
                static fn($row) => (string) ($row['id'] ?? ''),
                $extensionRows
            ), true);
            $existingAuthUsers = array_fill_keys(array_filter(array_map(
                static fn($row) => ast_sanitize_id((string) ($row['authuser'] ?? '')),
                $extensionRows
            )), true);
            $startPosition = count($extensionRows);
            $newRows = [];
            $newCount = 0;
            for ($n = $start; $n <= $end; $n++) {
                if (!isset($existingIds[ext_id($dept, (string) $n)])) {
                    $newCount++;
                }
            }
            assert_extension_capacity($dept, $newCount);
            for ($n = $start; $n <= $end; $n++) {
                $exten = (string) $n;
                $id = ext_id($dept, $exten);
                if (isset($existingIds[$id])) {
                    continue;
                }
                $sip = '';
                $auth = '';
                if ($technology === 'pjsip') {
                    $sip = pick_sip_user($dept, $exten, $exten);
                    do {
                        $auth = bin2hex(random_bytes(6));
                    } while (isset($existingAuthUsers[$auth]));
                    $existingAuthUsers[$auth] = true;
                }
                $customDial = $technology === 'custom'
                    ? preg_replace(
                        '/xxx/i',
                        $exten,
                        str_replace('{exten}', $exten, $customTemplate)
                    )
                    : '';
                if (
                    $technology === 'custom'
                    && (
                        strlen($customDial) > 160
                        || preg_match('~^[A-Za-z][A-Za-z0-9_]*/[A-Za-z0-9_+*#@.:\-/]+$~D', $customDial) !== 1
                    )
                ) {
                    json_out([
                        'ok' => false,
                        'error' => 'Custom kanal şablonu geçersiz. Örnek: local/{exten}@from-alcatel',
                    ], 422);
                }
                $newRow = [
                    'id' => $id,
                    'dept' => $dept,
                    'exten' => $exten,
                    'technology' => $technology,
                    'sipuser' => $sip,
                    'authuser' => $auth,
                    'dahdi_group' => $dahdiGroup,
                    'dahdi_dial' => $technology === 'dahdi' ? $exten : '',
                    'custom_dial' => $customDial,
                    'name' => 'Abone ' . $exten,
                    'password' => $technology === 'pjsip' ? $pass : '',
                    'max_contacts' => 1,
                    'codecs' => ['alaw', 'ulaw'],
                    'callerid' => 'Abone ' . $exten,
                    'vm_enabled' => !empty($_POST['vm_enabled']),
                    'vm_pin' => (string) random_int(100000, 999999),
                    'ringtime' => 30,
                    'followme' => '',
                    'webrtc' => false,
                    'phone_modes' => [$technology === 'pjsip' ? 'softphone' : $technology],
                    'crm_trigger' => 'disabled',
                    'outbound_enabled' => !array_key_exists('outbound_enabled', $_POST)
                        || !empty($_POST['outbound_enabled']),
                ];
                $extensionRows[] = $newRow;
                $newRows[] = $newRow;
                $existingIds[$id] = true;
            }
            store_append_rows('extensions', $extensionRows, $newRows, $startPosition);
            $applyResult = apply_to_pbx();
            if ($newRows) {
                require_once __DIR__ . '/includes/alarms.php';
                $createdExtensions = array_map(
                    static fn(array $row): string => (string) ($row['exten'] ?? ''),
                    $newRows
                );
                astera_alarm_emit_event(
                    $dept,
                    'extension_added',
                    count($newRows) . ' yeni abone toplu olarak eklendi',
                    'Aboneler: ' . implode(', ', $createdExtensions)
                        . "\nBağlantı türü: " . strtoupper($technology)
                );
            }
            json_out(array_merge([
                'ok' => true,
                'created' => count($newRows),
            ], $applyResult));

        case 'parking_save':
            $dept = require_dept_id();
            $id = $dept . '-park';
            store_upsert('parking', [
                'id' => $id,
                'dept' => $dept,
                'parkext' => ast_sanitize_id((string) ($_POST['parkext'] ?? '700')) ?: '700',
                'start' => ast_sanitize_id((string) ($_POST['start'] ?? '701')) ?: '701',
                'end' => ast_sanitize_id((string) ($_POST['end'] ?? '709')) ?: '709',
                'time' => max(15, (int) ($_POST['time'] ?? 45)),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'flow_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($local === '') {
                json_out(['ok' => false, 'error' => 'Kod zorunlu']);
            }
            $id = scoped_id($dept, $local);
            $feat = trim((string) ($_POST['feature'] ?? '*28'));
            if (!preg_match('/^\*[0-9]{2,6}$/', $feat)) {
                json_out(['ok' => false, 'error' => 'Özellik kodu * ile başlamalı (ör. *28)']);
            }
            store_upsert('flows', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'feature' => $feat,
                'true_type' => dest_allowed((string) ($_POST['true_type'] ?? 'extension')),
                'true_dest' => ast_sanitize_id((string) ($_POST['true_dest'] ?? '')),
                'false_type' => dest_allowed((string) ($_POST['false_type'] ?? 'hangup')),
                'false_dest' => ast_sanitize_id((string) ($_POST['false_dest'] ?? '')),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'flow_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('flows', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('flows', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'disa_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($local === '') {
                json_out(['ok' => false, 'error' => 'Kod zorunlu']);
            }
            $id = scoped_id($dept, $local);
            store_upsert('disas', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'pin' => ast_sanitize_id((string) ($_POST['pin'] ?? '')),
                'exten' => preg_replace('/\D/', '', (string) ($_POST['exten'] ?? '')) ?? '',
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'disa_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('disas', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('disas', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'paging_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $exten = preg_replace('/\D/', '', (string) ($_POST['exten'] ?? '')) ?? '';
            if ($local === '' || $exten === '') {
                json_out(['ok' => false, 'error' => 'Kod ve dahili zorunlu']);
            }
            $id = scoped_id($dept, $local);
            $members = $_POST['members'] ?? [];
            if (!is_array($members)) {
                $members = array_filter(array_map('trim', explode(',', (string) $members)));
            }
            if (!$members) {
                json_out(['ok' => false, 'error' => 'En az bir üye seçin']);
            }
            store_upsert('pagings', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'exten' => $exten,
                'duplex' => !empty($_POST['duplex']),
                'members' => array_values($members),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'paging_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('pagings', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('pagings', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'speed_save':
            $dept = require_dept_id();
            $code = ast_sanitize_id((string) ($_POST['code'] ?? ''));
            $number = preg_replace('/[^0-9*#]/', '', (string) ($_POST['number'] ?? '')) ?? '';
            if ($code === '' || $number === '') {
                json_out(['ok' => false, 'error' => 'Kod ve numara zorunlu']);
            }
            store_upsert('speeddials', [
                'id' => $dept . '-' . $code,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $code)),
                'code' => $code,
                'number' => $number,
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'speed_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('speeddials', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('speeddials', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'custom_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($local === '') {
                json_out(['ok' => false, 'error' => 'Kod zorunlu']);
            }
            $id = scoped_id($dept, $local);
            $sound = normalize_sound_name((string) ($_POST['sound'] ?? ''));
            if (!tenant_sound_allowed($dept, $sound)) {
                json_out(['ok' => false, 'error' => 'Seçilen ses bu firmaya ait değil'], 422);
            }
            store_upsert('customs', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'sound' => $sound,
                'goto_context' => ast_sanitize_id((string) ($_POST['goto_context'] ?? '')),
                'goto_exten' => ast_sanitize_id((string) ($_POST['goto_exten'] ?? 's')) ?: 's',
                'goto_pri' => max(1, (int) ($_POST['goto_pri'] ?? 1)),
                'dest_type' => dest_allowed((string) ($_POST['dest_type'] ?? 'hangup')),
                'dest' => ast_sanitize_id((string) ($_POST['dest'] ?? '')),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'custom_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('customs', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('customs', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'context_detail':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Yetkisiz işlem'], 403);
            }
            $contextName = trim((string) ($_POST['context'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_.-]+$/', $contextName)) {
                json_out(['ok' => false, 'error' => 'Geçersiz context'], 422);
            }
            $known = array_column(pbx_context_inventory(), null, 'name');
            if (!isset($known[$contextName])) {
                json_out(['ok' => false, 'error' => 'Context bulunamadı'], 404);
            }
            json_out([
                'ok' => true,
                'context' => $contextName,
                'output' => pbx_cli('dialplan show ' . $contextName),
            ]);

        case 'custom_context_save':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Yetkisiz işlem'], 403);
            }
            if (!custom_context_schema_ready()) {
                json_out(['ok' => false, 'error' => 'Özel context veritabanı migration’ı uygulanmamış'], 503);
            }
            $dept = require_dept_id();
            $contextId = ast_sanitize_id((string) ($_POST['context_id'] ?? ''));
            if ($contextId === '') {
                json_out(['ok' => false, 'error' => 'Context kodu zorunlu'], 422);
            }
            $description = trim((string) ($_POST['description'] ?? ''));
            if (mb_strlen($description) > 255) {
                json_out(['ok' => false, 'error' => 'Açıklama 255 karakteri aşamaz'], 422);
            }
            try {
                $steps = custom_context_parse_steps((string) ($_POST['steps_text'] ?? ''));
                custom_context_save($dept, $contextId, $description, $steps);
            } catch (InvalidArgumentException $exception) {
                json_out(['ok' => false, 'error' => $exception->getMessage()], 422);
            }
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'custom_context_delete':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Yetkisiz işlem'], 403);
            }
            if (!custom_context_schema_ready()) {
                json_out(['ok' => false, 'error' => 'Özel context veritabanı migration’ı uygulanmamış'], 503);
            }
            $dept = ast_sanitize_id((string) ($_POST['dept'] ?? ''));
            $contextId = ast_sanitize_id((string) ($_POST['context_id'] ?? ''));
            if ($dept === '' || $contextId === '' || custom_context_find($dept, $contextId) === null) {
                json_out(['ok' => false, 'error' => 'Özel context bulunamadı'], 404);
            }
            custom_context_delete($dept, $contextId);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'sound_list':
            json_out(array_merge(['ok' => true], pbx_media_with_usage(current_dept_id())));

        case 'sound_languages':
            json_out(['ok' => true, 'languages' => pbx_sound_languages()]);

        case 'sound_upload':
            $dept = require_dept_id();
            $kind = (string) ($_POST['kind'] ?? 'sound') === 'moh' ? 'moh' : 'sound';
            $res = pbx_upload_media($dept, $kind);
            json_out($res);

        case 'sound_delete':
            $path = (string) ($_POST['path'] ?? '');
            $kind = (string) ($_POST['kind'] ?? '') === 'moh' ? 'moh' : 'sound';
            $inventory = pbx_media_with_usage(current_dept_id());
            $collection = $kind === 'moh' ? $inventory['moh'] : $inventory['files'];
            $file = null;
            foreach ($collection as $candidate) {
                if ((string) ($candidate['path'] ?? '') === $path) {
                    $file = $candidate;
                    break;
                }
            }
            if ($file === null || empty($file['dept'])) {
                json_out(['ok' => false, 'error' => 'Dosya bulunamadı veya firma kapsamı dışında'], 404);
            }
            if (!empty($file['in_use'])) {
                json_out([
                    'ok' => false,
                    'error' => 'Bu ses dosyası kullanımda olduğu için silinemez',
                    'usage' => $file['usage'],
                ], 409);
            }
            json_out(pbx_delete_media($path));

        case 'logs':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            $files = asterisk_log_files();
            if (!$files) {
                json_out(['ok' => true, 'files' => [], 'file' => '', 'output' => 'Log dosyası bulunamadı.']);
            }
            $knownFiles = array_column($files, null, 'name');
            $file = trim((string) ($_POST['file'] ?? ''));
            if ($file === '' || !isset($knownFiles[$file])) {
                $file = isset($knownFiles['full.log'])
                    ? 'full.log'
                    : (string) ($files[0]['name'] ?? '');
            }
            $query = trim((string) ($_POST['query'] ?? ''));
            if (mb_strlen($query) > 200) {
                json_out(['ok' => false, 'error' => 'Arama metni 200 karakteri aşamaz'], 422);
            }
            $lines = max(50, min(2000, (int) ($_POST['lines'] ?? 200)));
            $path = '/var/log/asterisk/' . $file;
            $command = $query === ''
                ? 'tail -n ' . $lines . ' -- ' . escapeshellarg($path) . ' 2>/dev/null'
                : 'LC_ALL=C grep -iF -- ' . escapeshellarg($query) . ' '
                    . escapeshellarg($path) . ' 2>/dev/null | tail -n ' . $lines;
            $res = pbx_ssh($command);
            json_out([
                'ok' => true,
                'files' => $files,
                'file' => $file,
                'query' => $query,
                'lines' => $lines,
                'output' => (string) ($res['output'] ?? ''),
            ]);

        case 'ssl_issue':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            $domain = strtolower(trim((string) ($_POST['domain'] ?? PANEL_DOMAIN)));
            $email = trim((string) ($_POST['email'] ?? ''));
            if ($domain !== strtolower(PANEL_DOMAIN)
                || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1) {
                json_out(['ok' => false, 'error' => 'Geçersiz veya yapılandırılmamış alan adı']);
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                json_out(['ok' => false, 'error' => 'Geçerli bir bildirim e-postası girin']);
            }
            $res = pbx_ssh(
                "set -e\n"
                . 'certbot --nginx --non-interactive --agree-tos --redirect --keep-until-expiring '
                . '--email ' . escapeshellarg($email) . ' -d ' . escapeshellarg($domain)
                . "\nsystemctl enable --now certbot.timer"
                . "\nsystemctl is-active certbot.timer"
            );
            json_out([
                'ok' => !empty($res['ok']),
                'output' => (string) ($res['output'] ?? ''),
                'error' => !empty($res['ok']) ? null
                    : 'Sertifika alınamadı. DNS ile 80/443 yönlendirmesini kontrol edin.' . "\n"
                    . (string) ($res['output'] ?? ''),
            ], !empty($res['ok']) ? 200 : 422);

        case 'ssl_dns_issue':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            $baseDomain = 'asterapbx.net';
            $pbxName = strtolower(trim((string) ($_POST['pbx_name'] ?? '')));
            $email = trim((string) ($_POST['email'] ?? ''));
            $cloudflareToken = trim((string) ($_POST['cloudflare_token'] ?? ''));
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $pbxName) !== 1) {
                json_out(['ok' => false, 'error' => 'Santral adı geçersiz'], 422);
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                json_out(['ok' => false, 'error' => 'Geçerli bir bildirim e-postası girin'], 422);
            }
            if (preg_match('/^[A-Za-z0-9_-]{20,200}$/D', $cloudflareToken) !== 1) {
                json_out(['ok' => false, 'error' => 'Cloudflare API Token biçimi geçersiz'], 422);
            }
            $domain = $pbxName . '.' . $baseDomain;
            $credentialsFile = tempnam(sys_get_temp_dir(), 'astera-cf-');
            if ($credentialsFile === false
                || file_put_contents(
                    $credentialsFile,
                    'dns_cloudflare_api_token = ' . $cloudflareToken . PHP_EOL,
                    LOCK_EX
                ) === false) {
                json_out(['ok' => false, 'error' => 'Geçici Cloudflare kimlik dosyası oluşturulamadı'], 500);
            }
            @chmod($credentialsFile, 0600);
            $remoteToken = '/tmp/astera-cloudflare-' . bin2hex(random_bytes(8)) . '.ini';
            $remoteHook = '/tmp/astera-cert-deploy-' . bin2hex(random_bytes(8)) . '.sh';
            try {
                $tokenUpload = pbx_upload($credentialsFile, $remoteToken);
                $hookUpload = pbx_upload(
                    __DIR__ . '/ops/ssl/astera-cert-deploy.sh',
                    $remoteHook
                );
            } finally {
                @unlink($credentialsFile);
            }
            if (empty($tokenUpload['ok']) || empty($hookUpload['ok'])) {
                pbx_ssh('rm -f ' . escapeshellarg($remoteToken) . ' ' . escapeshellarg($remoteHook));
                json_out([
                    'ok' => false,
                    'error' => 'DNS sertifika dosyaları santrale aktarılamadı',
                ], 500);
            }
            $res = pbx_ssh(implode("\n", [
                'set -e',
                "if ! certbot plugins 2>/dev/null | grep -q 'dns-cloudflare'; then "
                    . 'apt-get update && DEBIAN_FRONTEND=noninteractive '
                    . 'apt-get install -y python3-certbot-dns-cloudflare; fi',
                'install -d -o root -g root -m 0700 /etc/letsencrypt',
                'install -o root -g root -m 0600 ' . escapeshellarg($remoteToken)
                    . ' /etc/letsencrypt/cloudflare.ini',
                'install -o root -g root -m 0750 ' . escapeshellarg($remoteHook)
                    . ' /usr/local/sbin/astera-cert-deploy.sh',
                'rm -f ' . escapeshellarg($remoteToken) . ' ' . escapeshellarg($remoteHook),
                'certbot certonly --dns-cloudflare '
                    . '--dns-cloudflare-credentials /etc/letsencrypt/cloudflare.ini '
                    . '--dns-cloudflare-propagation-seconds 30 '
                    . '--non-interactive --agree-tos --keep-until-expiring '
                    . '--email ' . escapeshellarg($email)
                    . ' --cert-name ' . escapeshellarg($domain)
                    . ' -d ' . escapeshellarg($domain)
                    . ' --deploy-hook /usr/local/sbin/astera-cert-deploy.sh',
                '/usr/local/sbin/astera-cert-deploy.sh ' . escapeshellarg($domain),
                'systemctl enable --now certbot.timer',
                'systemctl is-active certbot.timer',
            ]));
            json_out([
                'ok' => !empty($res['ok']),
                'domain' => $domain,
                'output' => (string) ($res['output'] ?? ''),
                'error' => !empty($res['ok']) ? null
                    : 'Cloudflare DNS sertifikası alınamadı.' . "\n"
                    . (string) ($res['output'] ?? ''),
            ], !empty($res['ok']) ? 200 : 422);

        case 'ssl_renew_test':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            $res = pbx_ssh('certbot renew --dry-run --no-random-sleep-on-renew');
            json_out([
                'ok' => !empty($res['ok']),
                'output' => (string) ($res['output'] ?? ''),
                'error' => !empty($res['ok']) ? null
                    : 'Yenileme testi başarısız' . "\n" . (string) ($res['output'] ?? ''),
            ], !empty($res['ok']) ? 200 : 422);

        case 'ntp_setting_apply':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            $enabled = !empty($_POST['enabled']);
            $servers = (string) ($_POST['ntp_servers'] ?? '');
            $result = ntp_apply_settings($enabled, $servers);
            json_out([
                'ok' => true,
                'message' => $enabled
                    ? 'NTP ayarları uygulandı ve eşitleme başlatıldı'
                    : 'NTP eşitlemesi kapatıldı',
                'result' => $result,
            ]);

        case 'ntp_sync_now':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            json_out([
                'ok' => true,
                'message' => 'NTP eşitlemesi yeniden başlatıldı',
                'result' => ntp_sync_now(),
            ]);

        case 'network_route_save':
            $route = network_posted_route();
            $routeId = (int) ($_POST['route_id'] ?? 0);
            $pdo = pbx_relational_pdo();
            if (!$pdo) {
                json_out(['ok' => false, 'error' => 'Routing veritabanı bağlantısı kurulamadı'], 500);
            }
            $pdo->beginTransaction();
            try {
                $enabledSql = !empty($route['enabled']) ? 'TRUE' : 'FALSE';
                $saved = $routeId > 0
                    ? (pbx_relational_json_rows(
                        "UPDATE network_static_routes SET "
                        . 'dept_id=' . pbx_sql_literal($route['dept_id']) . ','
                        . 'interface_name=' . pbx_sql_literal($route['interface_name']) . ','
                        . 'destination=' . pbx_sql_literal($route['destination']) . '::cidr,'
                        . 'gateway=' . pbx_sql_literal($route['gateway']) . '::inet,'
                        . 'metric=' . (int) $route['metric'] . ','
                        . 'description=' . pbx_sql_literal($route['description']) . ','
                        . 'enabled=' . $enabledSql . ',updated_at=now(),'
                        . 'updated_by=' . pbx_sql_literal(store_actor())
                        . ' WHERE route_id=' . $routeId . ' RETURNING route_id'
                    )[0] ?? null)
                    : (pbx_relational_json_rows(
                        "INSERT INTO network_static_routes "
                        . "(dept_id,interface_name,destination,gateway,metric,description,enabled,updated_by) VALUES ("
                        . pbx_sql_literal($route['dept_id']) . ','
                        . pbx_sql_literal($route['interface_name']) . ','
                        . pbx_sql_literal($route['destination']) . '::cidr,'
                        . pbx_sql_literal($route['gateway']) . '::inet,'
                        . (int) $route['metric'] . ','
                        . pbx_sql_literal($route['description']) . ',' . $enabledSql . ','
                        . pbx_sql_literal(store_actor()) . ') RETURNING route_id'
                    )[0] ?? null);
                if (!$saved) {
                    throw new RuntimeException('Statik rota kaydedilemedi');
                }
                $sync = network_sync_static_routes();
                $pdo->commit();
                json_out([
                    'ok' => true,
                    'route_id' => (int) $saved['route_id'],
                    'enabled' => !empty($route['enabled']),
                    'message' => !empty($route['enabled'])
                        ? 'Statik rota kaydedildi ve uygulandı'
                        : 'Rota veritabanına kaydedildi; Ethernet kartı aktif olmadığından uygulama bekliyor',
                    'output' => (string) ($sync['output'] ?? ''),
                ]);
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }

        case 'network_route_delete':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            $routeId = (int) ($_POST['route_id'] ?? 0);
            $pdo = pbx_relational_pdo();
            if (!$pdo || $routeId <= 0) {
                json_out(['ok' => false, 'error' => 'Geçersiz rota kaydı'], 422);
            }
            $pdo->beginTransaction();
            try {
                $deleted = pbx_relational_json_rows(
                    'DELETE FROM network_static_routes WHERE route_id=' . $routeId
                    . ' RETURNING route_id'
                )[0] ?? null;
                if (!$deleted) {
                    throw new RuntimeException('Statik rota bulunamadı');
                }
                $sync = network_sync_static_routes();
                $pdo->commit();
                json_out(['ok' => true, 'output' => (string) ($sync['output'] ?? '')]);
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }

        case 'network_setting_save':
            $setting = network_posted_setting();
            $existing = network_setting((string) $setting['interface_name']);
            if ($existing && !empty($existing['rollback_unit']) && empty($existing['confirmed'])) {
                json_out(['ok' => false, 'error' => 'Önce bekleyen ağ değişikliğini onaylayın veya geri alınmasını bekleyin'], 409);
            }
            network_save_setting($setting);
            json_out(['ok' => true, 'message' => 'Ethernet ayarı kaydedildi']);

        case 'network_setting_apply':
            $setting = network_posted_setting();
            $existing = network_setting((string) $setting['interface_name']);
            if ($existing && !empty($existing['rollback_unit']) && empty($existing['confirmed'])) {
                json_out(['ok' => false, 'error' => 'Bu arayüzde onay bekleyen bir ağ değişikliği var'], 409);
            }
            $interface = (string) $setting['interface_name'];
            $uuid = trim((string) $setting['connection_uuid']);
            $connectionName = '';
            foreach (network_inventory() as $liveInterface) {
                if ((string) ($liveInterface['interface_name'] ?? '') === $interface) {
                    $connectionName = trim((string) ($liveInterface['connection_name'] ?? ''));
                    break;
                }
            }
            if ($uuid === '' || $uuid === '--') {
                $connectionName = 'Astera-' . $interface;
                $profile = pbx_ssh(
                    'nmcli connection show id ' . escapeshellarg($connectionName) . " >/dev/null 2>&1 || "
                    . 'nmcli connection add type ethernet ifname ' . escapeshellarg($interface)
                    . ' con-name ' . escapeshellarg($connectionName)
                    . " ipv4.method disabled connection.autoconnect no >/dev/null\n"
                    . 'nmcli -g connection.uuid connection show id ' . escapeshellarg($connectionName)
                );
                $uuid = trim((string) ($profile['output'] ?? ''));
                if (empty($profile['ok']) || preg_match('/^[a-f0-9-]{36}$/iD', $uuid) !== 1) {
                    json_out(['ok' => false, 'error' => 'Ethernet bağlantı profili oluşturulamadı'], 422);
                }
                $setting['connection_uuid'] = $uuid;
            }
            if ($connectionName === '' || $connectionName === '--') {
                $connectionName = 'Astera-' . $interface;
            }
            $token = date('YmdHis') . '-' . bin2hex(random_bytes(3));
            $backupName = 'astera-backup-' . $interface . '-' . $token;
            $rollbackUnit = 'astera-network-rollback-' . $token;
            $applyUnit = 'astera-network-apply-' . $token;
            $applyPath = '/tmp/' . $applyUnit . '.sh';
            $rollbackPath = '/tmp/' . $rollbackUnit . '.sh';
            $rollbackScript = "#!/bin/bash\nset -e\n"
                . 'nmcli connection delete uuid ' . escapeshellarg($uuid) . " >/dev/null 2>&1 || true\n"
                . 'nmcli connection clone id ' . escapeshellarg($backupName) . ' '
                . escapeshellarg($connectionName) . " >/dev/null\n"
                . 'nmcli connection delete id ' . escapeshellarg($backupName) . " >/dev/null 2>&1 || true\n"
                . 'nmcli connection up id ' . escapeshellarg($connectionName) . "\n"
                . 'printf %s ' . escapeshellarg(base64_encode(
                    'DELETE FROM network_interface_settings WHERE interface_name='
                    . pbx_sql_literal($interface) . ";\n"
                ))
                . ' | base64 -d | sudo -u postgres psql -v ON_ERROR_STOP=1 -d '
                . escapeshellarg(PBX_DB_NAME) . " >/dev/null\n"
                . 'rm -f ' . escapeshellarg($rollbackPath) . "\n";
            $modify = 'nmcli connection modify uuid ' . escapeshellarg($uuid)
                . ' connection.autoconnect yes 802-3-ethernet.mtu ' . (int) $setting['mtu'];
            if ($setting['method'] === 'dhcp') {
                $modify .= " ipv4.method auto ipv4.addresses '' ipv4.gateway '' ipv4.dns ''"
                    . ' ipv4.ignore-auto-dns no';
            } else {
                $modify .= ' ipv4.method manual ipv4.addresses ' . escapeshellarg((string) $setting['ipv4_cidr'])
                    . ' ipv4.gateway ' . escapeshellarg((string) $setting['gateway'])
                    . ' ipv4.dns ' . escapeshellarg((string) $setting['dns_servers'])
                    . ' ipv4.ignore-auto-dns ' . ((string) $setting['dns_servers'] !== '' ? 'yes' : 'no');
            }
            $applyScript = "#!/bin/bash\nset -e\n"
                . 'trap ' . escapeshellarg('rm -f ' . $applyPath) . " EXIT\n"
                . 'nmcli connection clone uuid ' . escapeshellarg($uuid) . ' '
                . escapeshellarg($backupName) . " >/dev/null\n"
                . 'systemd-run --quiet --unit=' . escapeshellarg($rollbackUnit)
                . ' --on-active=3m /bin/bash ' . escapeshellarg($rollbackPath) . "\n"
                . $modify . "\n"
                . 'if ! nmcli connection up uuid ' . escapeshellarg($uuid) . "; then\n"
                . '  systemctl stop ' . escapeshellarg($rollbackUnit . '.timer') . " 2>/dev/null || true\n"
                . '  /bin/bash ' . escapeshellarg($rollbackPath) . "\n"
                . "  exit 1\nfi\n";
            $setting['rollback_unit'] = $rollbackUnit;
            $setting['backup_connection'] = $backupName;
            $setting['confirmed'] = false;
            $setting['applied_at'] = date(DATE_ATOM);
            network_save_setting($setting);
            $schedule = pbx_ssh(
                'printf %s ' . escapeshellarg(base64_encode($rollbackScript))
                . ' | base64 -d > ' . escapeshellarg($rollbackPath) . "\n"
                . 'printf %s ' . escapeshellarg(base64_encode($applyScript))
                . ' | base64 -d > ' . escapeshellarg($applyPath) . "\n"
                . 'chmod 0700 ' . escapeshellarg($rollbackPath) . ' ' . escapeshellarg($applyPath) . "\n"
                . 'systemd-run --quiet --unit=' . escapeshellarg($applyUnit)
                . ' --on-active=5s /bin/bash ' . escapeshellarg($applyPath)
            );
            if (empty($schedule['ok'])) {
                pbx_ssh('rm -f ' . escapeshellarg($applyPath) . ' ' . escapeshellarg($rollbackPath));
                $setting['rollback_unit'] = '';
                $setting['backup_connection'] = '';
                $setting['confirmed'] = true;
                $setting['applied_at'] = '';
                network_save_setting($setting);
                json_out([
                    'ok' => false,
                    'error' => 'Ağ değişikliği zamanlanamadı: ' . (string) ($schedule['output'] ?? ''),
                ], 422);
            }
            json_out([
                'ok' => true,
                'output' => 'Ayar 5 saniye içinde uygulanacak. 3 dakika içinde yeni bağlantı üzerinden onaylayın.',
                'rollback_unit' => $rollbackUnit,
            ]);

        case 'network_setting_confirm':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            $interface = network_interface_name((string) ($_POST['interface_name'] ?? ''));
            $setting = network_setting($interface);
            if (!$setting || empty($setting['rollback_unit']) || !empty($setting['confirmed'])) {
                json_out(['ok' => false, 'error' => 'Onay bekleyen ağ değişikliği yok'], 422);
            }
            $unit = (string) $setting['rollback_unit'];
            $backup = (string) $setting['backup_connection'];
            $result = pbx_ssh(
                'systemctl stop ' . escapeshellarg($unit . '.timer') . ' '
                . escapeshellarg($unit . '.service') . " 2>/dev/null || true\n"
                . 'systemctl reset-failed ' . escapeshellarg($unit . '.service') . " 2>/dev/null || true\n"
                . 'nmcli connection delete id ' . escapeshellarg($backup) . " >/dev/null 2>&1 || true\n"
                . 'rm -f ' . escapeshellarg('/tmp/' . $unit . '.sh')
            );
            $setting['confirmed'] = true;
            $setting['rollback_unit'] = '';
            $setting['backup_connection'] = '';
            $setting['applied_at'] = '';
            network_save_setting($setting);
            json_out(['ok' => true, 'output' => (string) ($result['output'] ?? '')]);

        case 'security_firewall_apply':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            $management = security_networks($_POST['management_networks'] ?? '');
            $sipNetworks = security_networks($_POST['sip_networks'] ?? '');
            $currentIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            if (filter_var($currentIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $management[] = $currentIp . '/32';
                $management = array_values(array_unique($management));
            }
            if (!$management) {
                json_out(['ok' => false, 'error' => 'En az bir yönetim IP/ağı gereklidir'], 422);
            }
            if (!$sipNetworks) {
                json_out(['ok' => false, 'error' => 'En az bir SIP operatörü veya telefon ağı gereklidir'], 422);
            }
            $publicWeb = !empty($_POST['public_web']);
            $commands = [
                'set -e',
                'ufw --force reset',
                'ufw default deny incoming',
                'ufw default allow outgoing',
                'ufw logging medium',
                'ufw allow in on lo',
                'ufw allow 80/tcp comment astera-http-certbot',
            ];
            $commands[] = <<<'SH'
{ printf '%s\n' 22 45555; sshd -T 2>/dev/null | awk '$1 == "port" {print $2}'; } | sort -un | while IFS= read -r ssh_port; do case "$ssh_port" in ''|*[!0-9]*) continue ;; esac; ufw allow "$ssh_port/tcp" comment astera-ssh; done
SH;
            $commands[] = <<<'SH'
for interface in $(ip -o -4 route show default | awk '{for (i=1; i<=NF; i++) if ($i == "dev") print $(i+1)}' | sort -u); do ip -o -4 route show proto kernel scope link dev "$interface"; done | awk '$1 ~ /^10\./ || $1 ~ /^192\.168\./ || $1 ~ /^172\.(1[6-9]|2[0-9]|3[01])\./ {print $1}' | sort -u | while IFS= read -r network; do ufw allow from "$network" comment astera-local-network; done
SH;
            if ($publicWeb) {
                $commands[] = 'ufw allow 443/tcp comment astera-https';
            }
            foreach ($management as $network) {
                $source = escapeshellarg($network);
                $commands[] = "ufw allow from {$source} to any port 5432 proto tcp comment astera-management-db";
                $commands[] = "ufw allow from {$source} to any port 10000 proto tcp comment astera-management-webmin";
                if (!$publicWeb) {
                    $commands[] = "ufw allow from {$source} to any port 443 proto tcp comment astera-management-web";
                }
            }
            foreach ($sipNetworks as $network) {
                $source = escapeshellarg($network);
                $commands[] = "ufw allow from {$source} to any port 5060 proto udp comment astera-sip-udp";
                $commands[] = "ufw allow from {$source} to any port 5060 proto tcp comment astera-sip-tcp";
                $commands[] = "ufw allow from {$source} to any port 5061 proto tcp comment astera-sip-tls";
            }
            foreach (array_values(array_unique(array_merge($management, $sipNetworks))) as $network) {
                $source = escapeshellarg($network);
                $commands[] = "ufw allow from {$source} to any port 8088 proto tcp comment astera-webrtc-ws";
                $commands[] = "ufw allow from {$source} to any port 8089 proto tcp comment astera-webrtc-wss";
            }
            if ($publicWeb) {
                // Public WebRTC clients use dynamic source IP addresses. SIP signalling stays
                // behind HTTPS/WSS, but their encrypted media must reach Asterisk directly.
                $commands[] = 'ufw allow 10000:20000/udp comment astera-webrtc-rtp';
            } else {
                foreach ($sipNetworks as $network) {
                    $source = escapeshellarg($network);
                    $commands[] = "ufw allow from {$source} to any port 10000:20000 proto udp comment astera-rtp";
                }
            }
            $rollbackUnit = 'astera-firewall-rollback-' . date('YmdHis');
            $commands[] = 'ufw --force enable';
            $commands[] = 'if systemctl list-unit-files astera-network-routes.service --no-legend 2>/dev/null'
                . " | grep -q '^astera-network-routes\\.service'; then "
                . 'systemctl reload astera-network-routes.service; fi';
            $commands[] = 'systemd-run --quiet --unit=' . escapeshellarg($rollbackUnit)
                . ' --on-active=5m /usr/sbin/ufw --force disable';
            $commands[] = 'ufw status numbered';
            $res = pbx_ssh(implode("\n", $commands));
            if (!empty($res['ok'])) {
                store_upsert('security_settings', [
                    'id' => 'firewall',
                    'management_networks' => $management,
                    'sip_networks' => $sipNetworks,
                    'public_web' => $publicWeb,
                    'rollback_unit' => $rollbackUnit,
                    'confirmed' => false,
                    'applied_at' => date(DATE_ATOM),
                    'applied_by' => store_actor(),
                ], 'id');
            }
            json_out([
                'ok' => !empty($res['ok']),
                'output' => (string) ($res['output'] ?? ''),
                'rollback_unit' => $rollbackUnit,
                'error' => !empty($res['ok']) ? null : 'Güvenlik duvarı uygulanamadı',
            ], !empty($res['ok']) ? 200 : 422);

        case 'security_firewall_confirm':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            $settings = find_by('security_settings', 'id', 'firewall');
            $unit = ast_sanitize_id((string) ($settings['rollback_unit'] ?? ''));
            if ($unit === '') {
                json_out(['ok' => false, 'error' => 'Onay bekleyen güvenlik duvarı işlemi yok'], 422);
            }
            $res = pbx_ssh(
                'systemctl stop ' . escapeshellarg($unit . '.timer') . ' '
                . escapeshellarg($unit . '.service') . " 2>/dev/null || true\n"
                . 'systemctl reset-failed ' . escapeshellarg($unit . '.service') . " 2>/dev/null || true\n"
                . 'ufw status numbered'
            );
            $settings['confirmed'] = true;
            $settings['confirmed_at'] = date(DATE_ATOM);
            store_upsert('security_settings', $settings, 'id');
            json_out(['ok' => true, 'output' => (string) ($res['output'] ?? '')]);

        case 'apply':
            json_out(array_merge(['ok' => true], apply_to_pbx(true)));

        case 'reload':
            $applyId = store_apply_begin();
            $res = pbx_ssh("asterisk -rx 'core reload'");
            store_apply_finish($applyId, !empty($res['ok']), (string) ($res['output'] ?? ''));
            json_out(['ok' => $res['ok'], 'output' => $res['output']]);

        default:
            json_out(['ok' => false, 'error' => 'Bilinmeyen işlem'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
