<?php
declare(strict_types=1);

function network_interface_name(string $value): string
{
    $value = trim($value);
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,30}$/D', $value) === 1 ? $value : '';
}

function network_live_primary_ipv4(): string
{
    $output = [];
    $exitCode = 0;
    exec('/usr/sbin/ip -4 route get 1.1.1.1 2>/dev/null', $output, $exitCode);
    if ($exitCode === 0 && preg_match('/\bsrc\s+([0-9.]+)/', implode(' ', $output), $match)) {
        $ip = (string) ($match[1] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }
    }
    $requestHost = preg_replace('/:\d+$/', '', trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    return filter_var($requestHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        ? $requestHost
        : PBX_HOST;
}

function network_sidebar_addresses(): array
{
    $output = [];
    $exitCode = 0;
    exec('/usr/sbin/ip -j -4 address show up 2>/dev/null', $output, $exitCode);
    $decoded = $exitCode === 0 ? json_decode(implode("\n", $output), true) : null;
    $addresses = [];
    $displayNames = [];
    try {
        foreach (network_settings() as $setting) {
            $displayNames[(string) ($setting['interface_name'] ?? '')]
                = trim((string) ($setting['display_name'] ?? ''));
        }
    } catch (Throwable) {
        $displayNames = [];
    }
    $primaryIp = network_live_primary_ipv4();
    if (is_array($decoded)) {
        foreach ($decoded as $interface) {
            $name = network_interface_name((string) ($interface['ifname'] ?? ''));
            $interfaceType = $name !== '' ? @file_get_contents('/sys/class/net/' . $name . '/type') : false;
            if ($name === '' || $name === 'lo' || trim((string) $interfaceType) !== '1') {
                continue;
            }
            $selectedIp = '';
            foreach ((array) ($interface['addr_info'] ?? []) as $address) {
                $ip = (string) ($address['local'] ?? '');
                if (
                    (string) ($address['family'] ?? '') === 'inet'
                    && (string) ($address['scope'] ?? '') === 'global'
                    && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                ) {
                    if ($selectedIp === '' || empty($address['secondary'])) {
                        $selectedIp = $ip;
                    }
                    if ($ip === $primaryIp) {
                        $selectedIp = $ip;
                        break;
                    }
                }
            }
            if ($selectedIp !== '') {
                $addresses[] = [
                    'interface' => $name,
                    'display_name' => $displayNames[$name] ?? '',
                    'ip' => $selectedIp,
                ];
            }
        }
    }
    return $addresses ?: [['interface' => 'Santral', 'display_name' => '', 'ip' => $primaryIp]];
}

function network_ipv4_network_cidr(string $cidr): string
{
    if (!preg_match('/^([0-9.]+)\/(\d{1,2})$/D', trim($cidr), $match)) {
        return '';
    }
    $ip = (string) $match[1];
    $prefix = (int) $match[2];
    $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        ? inet_pton($ip)
        : false;
    if ($packed === false || $prefix < 0 || $prefix > 32) {
        return '';
    }
    $value = unpack('N', $packed)[1];
    $mask = $prefix === 0 ? 0 : ((0xffffffff << (32 - $prefix)) & 0xffffffff);
    return long2ip($value & $mask) . '/' . $prefix;
}

function network_sync_pbx_identity(string $interface, string $ipv4Cidr): void
{
    $interface = network_interface_name($interface);
    if (
        $interface === ''
        || !preg_match('/^([0-9.]+)\/\d{1,2}$/D', trim($ipv4Cidr), $match)
        || filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
    ) {
        throw new RuntimeException('Santral ağ kimliği için geçerli statik IPv4 bilgisi gerekli.');
    }
    $host = (string) $match[1];
    $localNetwork = network_ipv4_network_cidr($ipv4Cidr);
    if ($localNetwork === '') {
        throw new RuntimeException('Santral yerel ağı hesaplanamadı.');
    }
    $script = <<<'SH'
set -e
default_interface="$(ip -4 route show default | awk 'NR==1{print $5}')"
[ "$default_interface" = "$ASTERA_INTERFACE" ] || exit 0
install -d -m 0750 -o root -g astera-panel /etc/astera
touch /etc/astera/astera.env
sed -i \
    -e '/^ASTERA_PBX_HOST=/d' \
    -e '/^ASTERA_PBX_LOCAL_NET=/d' \
    /etc/astera/astera.env
printf 'ASTERA_PBX_HOST=%s\nASTERA_PBX_LOCAL_NET=%s\n' \
    "$ASTERA_HOST" "$ASTERA_LOCAL_NET" >>/etc/astera/astera.env
chown root:astera-panel /etc/astera/astera.env
chmod 0640 /etc/astera/astera.env
if [ -f /etc/nginx/sites-available/astera ]; then
    sed -i '/^[[:space:]]*fastcgi_param[[:space:]]\+ASTERA_PBX_HOST[[:space:]]/d' \
        /etc/nginx/sites-available/astera
    nginx -t
    systemctl reload nginx
fi
php_fpm_service="$(systemctl list-unit-files --type=service --no-legend 'php*-fpm.service' \
    | awk 'NR==1{sub(/\.service$/, "", $1); print $1}')"
[ -z "$php_fpm_service" ] || systemd-run --quiet --collect --on-active=2s \
    /bin/systemctl restart "$php_fpm_service"
SH;
    $result = pbx_ssh(
        'ASTERA_INTERFACE=' . escapeshellarg($interface) . ' '
        . 'ASTERA_HOST=' . escapeshellarg($host) . ' '
        . 'ASTERA_LOCAL_NET=' . escapeshellarg($localNetwork) . " \\\n"
        . "/bin/bash -s <<'ASTERA_NETWORK_IDENTITY'\n"
        . $script . "\nASTERA_NETWORK_IDENTITY"
    );
    if (empty($result['ok'])) {
        throw new RuntimeException(
            'Santral ağ kimliği güncellenemedi: ' . (string) ($result['output'] ?? '')
        );
    }
}

function network_inventory(): array
{
    $script = <<<'SH'
set -u
for iface in $(nmcli -t -f DEVICE,TYPE device status 2>/dev/null | awk -F: '$2=="ethernet"{print $1}'); do
    [ "$iface" = "lo" ] && continue
    uuid="$(nmcli -g GENERAL.CON-UUID device show "$iface" 2>/dev/null | head -n1)"
    connection="$(nmcli -g GENERAL.CONNECTION device show "$iface" 2>/dev/null | head -n1)"
    state="$(nmcli -g GENERAL.STATE device show "$iface" 2>/dev/null | head -n1)"
    mac="$(cat "/sys/class/net/$iface/address" 2>/dev/null || true)"
    mtu="$(cat "/sys/class/net/$iface/mtu" 2>/dev/null || true)"
    addresses="$(ip -o -4 address show dev "$iface" scope global 2>/dev/null | awk '{print $4}' | paste -sd, -)"
    gateway="$(ip -4 route show default dev "$iface" 2>/dev/null | awk 'NR==1{print $3}')"
    dns="$(nmcli -g IP4.DNS device show "$iface" 2>/dev/null | paste -sd, -)"
    method=""
    if [ -n "$uuid" ] && [ "$uuid" != "--" ]; then
        method="$(nmcli -g ipv4.method connection show uuid "$uuid" 2>/dev/null | head -n1)"
    fi
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
        "$iface" "$mac" "$state" "$connection" "$uuid" "$method" \
        "$addresses" "$gateway" "$dns" "$mtu" | tr '\r\n' '  '
    printf '\n'
done
SH;
    $result = pbx_ssh($script);
    if (empty($result['ok'])) {
        throw new RuntimeException('Ethernet arayüzleri okunamadı: ' . (string) ($result['output'] ?? ''));
    }
    $interfaces = [];
    foreach (preg_split('/\r?\n/', trim((string) ($result['output'] ?? ''))) as $line) {
        if ($line === '') {
            continue;
        }
        $parts = array_pad(explode("\t", $line), 10, '');
        $name = network_interface_name($parts[0]);
        if ($name === '') {
            continue;
        }
        $interfaces[$name] = [
            'interface_name' => $name,
            'mac_address' => trim($parts[1]),
            'state' => trim($parts[2]),
            'connection_name' => trim($parts[3]),
            'connection_uuid' => trim($parts[4]),
            'method' => trim($parts[5]) === 'auto' ? 'dhcp' : 'manual',
            'ipv4_cidr' => trim(explode(',', $parts[6])[0] ?? ''),
            'gateway' => trim($parts[7]),
            'dns_servers' => trim($parts[8]),
            'mtu' => max(0, (int) $parts[9]),
        ];
    }
    return array_values($interfaces);
}

function network_settings(): array
{
    return pbx_relational_json_rows(
        "SELECT interface_name, display_name, dept_id, method, ipv4_cidr, gateway, "
        . "array_to_string(dns_servers, ',') AS dns_servers, mtu, connection_uuid, "
        . "rollback_unit, backup_connection, confirmed, applied_at, updated_at, updated_by "
        . "FROM network_interface_settings ORDER BY interface_name"
    );
}

function network_setting(string $interface): ?array
{
    $interface = network_interface_name($interface);
    if ($interface === '') {
        return null;
    }
    $rows = pbx_relational_json_rows(
        "SELECT interface_name, display_name, dept_id, method, ipv4_cidr, gateway, "
        . "array_to_string(dns_servers, ',') AS dns_servers, mtu, connection_uuid, "
        . "rollback_unit, backup_connection, confirmed, applied_at, updated_at, updated_by "
        . "FROM network_interface_settings WHERE interface_name = " . pbx_sql_literal($interface)
        . " LIMIT 1"
    );
    return $rows[0] ?? null;
}

function network_save_setting(array $setting): void
{
    $dns = array_values(array_filter(array_map('trim', explode(',', (string) ($setting['dns_servers'] ?? '')))));
    $dnsSql = $dns
        ? 'ARRAY[' . implode(',', array_map('pbx_sql_literal', $dns)) . ']::inet[]'
        : 'ARRAY[]::inet[]';
    $sql = "INSERT INTO network_interface_settings "
        . "(interface_name,display_name,dept_id,method,ipv4_cidr,gateway,dns_servers,mtu,connection_uuid,"
        . "rollback_unit,backup_connection,confirmed,applied_at,updated_at,updated_by) VALUES ("
        . pbx_sql_literal((string) $setting['interface_name']) . ','
        . pbx_sql_literal((string) ($setting['display_name'] ?? '')) . ','
        . pbx_sql_literal((string) $setting['dept_id']) . ','
        . pbx_sql_literal((string) $setting['method']) . ','
        . ((string) ($setting['ipv4_cidr'] ?? '') !== ''
            ? pbx_sql_literal((string) $setting['ipv4_cidr']) . '::inet' : 'NULL') . ','
        . ((string) ($setting['gateway'] ?? '') !== ''
            ? pbx_sql_literal((string) $setting['gateway']) . '::inet' : 'NULL') . ','
        . $dnsSql . ',' . max(576, min(9000, (int) ($setting['mtu'] ?? 1500))) . ','
        . pbx_sql_literal((string) ($setting['connection_uuid'] ?? '')) . ','
        . pbx_sql_literal((string) ($setting['rollback_unit'] ?? '')) . ','
        . pbx_sql_literal((string) ($setting['backup_connection'] ?? '')) . ','
        . (!empty($setting['confirmed']) ? 'TRUE' : 'FALSE') . ','
        . (!empty($setting['applied_at']) ? 'now()' : 'NULL') . ',now(),'
        . pbx_sql_literal(store_actor()) . ') '
        . "ON CONFLICT (interface_name) DO UPDATE SET "
        . "display_name=EXCLUDED.display_name,dept_id=EXCLUDED.dept_id,"
        . "method=EXCLUDED.method,ipv4_cidr=EXCLUDED.ipv4_cidr,"
        . "gateway=EXCLUDED.gateway,dns_servers=EXCLUDED.dns_servers,mtu=EXCLUDED.mtu,"
        . "connection_uuid=EXCLUDED.connection_uuid,rollback_unit=EXCLUDED.rollback_unit,"
        . "backup_connection=EXCLUDED.backup_connection,confirmed=EXCLUDED.confirmed,"
        . "applied_at=EXCLUDED.applied_at,updated_at=now(),updated_by=EXCLUDED.updated_by";
    $result = pbx_odbc_sql($sql);
    if (empty($result['ok']) || str_contains((string) ($result['output'] ?? ''), '[ISQL]ERROR')) {
        throw new RuntimeException('Ağ ayarı veritabanına kaydedilemedi: ' . (string) ($result['output'] ?? ''));
    }
}

function network_live_routes(): array
{
    $result = pbx_ssh('ip -j -4 route show table all');
    if (empty($result['ok'])) {
        throw new RuntimeException('Routing tablosu okunamadı: ' . (string) ($result['output'] ?? ''));
    }
    $decoded = json_decode(trim((string) ($result['output'] ?? '')), true);
    if (!is_array($decoded)) {
        return [];
    }
    $routes = [];
    foreach ($decoded as $route) {
        if (!is_array($route)) {
            continue;
        }
        $routes[] = [
            'destination' => (string) ($route['dst'] ?? 'default'),
            'gateway' => (string) ($route['gateway'] ?? ''),
            'interface' => (string) ($route['dev'] ?? ''),
            'source' => (string) ($route['prefsrc'] ?? ''),
            'metric' => isset($route['metric']) ? (string) $route['metric'] : '',
            'protocol' => (string) ($route['protocol'] ?? ''),
            'scope' => (string) ($route['scope'] ?? ''),
            'table' => (string) ($route['table'] ?? 'main'),
        ];
    }
    return $routes;
}

function network_live_rules(): array
{
    $result = pbx_ssh('ip -4 rule show');
    if (empty($result['ok'])) {
        return [];
    }
    return array_values(array_filter(array_map(
        'trim',
        preg_split('/\r?\n/', trim((string) ($result['output'] ?? '')))
    )));
}

function network_static_routes(): array
{
    return pbx_relational_json_rows(
        "SELECT route.route_id,route.dept_id,tenant.name AS dept_name,"
        . "route.interface_name,host(route.destination) || '/' || masklen(route.destination) AS destination,"
        . "host(route.gateway) AS gateway,route.metric,route.description,route.enabled,"
        . "route.created_at,route.updated_at,route.updated_by "
        . "FROM network_static_routes route "
        . "JOIN astera_tenants tenant ON tenant.dept_id=route.dept_id "
        . "ORDER BY route.destination,route.metric,route.route_id"
    );
}

function network_sync_static_routes(): array
{
    $rows = pbx_relational_json_rows(
        "SELECT host(destination) || '/' || masklen(destination) AS destination,"
        . "host(gateway) AS gateway,interface_name,metric "
        . "FROM network_static_routes WHERE enabled=TRUE "
        . "ORDER BY destination,metric,route_id"
    );
    $lines = [];
    foreach ($rows as $row) {
        $interface = network_interface_name((string) ($row['interface_name'] ?? ''));
        $destination = trim((string) ($row['destination'] ?? ''));
        $gateway = trim((string) ($row['gateway'] ?? ''));
        $metric = max(0, min(65535, (int) ($row['metric'] ?? 100)));
        if ($interface === '' || $destination === '' || $gateway === '') {
            throw new RuntimeException('Kalıcı rota kaydı eksik veya geçersiz.');
        }
        $lines[] = $destination . '|' . $gateway . '|' . $interface . '|' . $metric;
    }
    $encoded = base64_encode($lines ? implode("\n", $lines) . "\n" : '');
    $result = pbx_ssh(
        'install -d -m 0750 -o root -g astera-panel /etc/astera' . "\n"
        . 'old_config=$(mktemp)' . "\n"
        . 'test ! -f /etc/astera/network-routes.conf '
        . '|| cp /etc/astera/network-routes.conf "$old_config"' . "\n"
        . 'printf %s ' . escapeshellarg($encoded) . ' | base64 -d > /tmp/astera-network-routes.conf' . "\n"
        . 'install -m 0640 -o root -g astera-panel /tmp/astera-network-routes.conf '
        . '/etc/astera/network-routes.conf' . "\n"
        . 'rm -f /tmp/astera-network-routes.conf' . "\n"
        . 'if ! systemctl restart astera-network-routes.service; then '
        . 'if test -s "$old_config"; then install -m 0640 -o root -g astera-panel "$old_config" '
        . '/etc/astera/network-routes.conf; else : > /etc/astera/network-routes.conf; fi; '
        . 'systemctl restart astera-network-routes.service || true; rm -f "$old_config"; exit 1; fi' . "\n"
        . 'rm -f "$old_config"' . "\n"
        . 'ip -4 route show table main'
    );
    if (empty($result['ok'])) {
        throw new RuntimeException('Kalıcı rotalar uygulanamadı: ' . (string) ($result['output'] ?? ''));
    }
    return $result;
}
