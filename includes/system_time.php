<?php
declare(strict_types=1);

function ntp_default_servers(): array
{
    return ['tr.pool.ntp.org', 'pool.ntp.org', 'time.cloudflare.com'];
}

function ntp_normalize_servers(string|array $value): array
{
    $parts = is_array($value)
        ? $value
        : preg_split('/[\s,;]+/', trim($value));
    $servers = [];
    foreach ((array) $parts as $part) {
        $server = strtolower(trim((string) $part));
        if ($server === '') {
            continue;
        }
        $validIp = filter_var($server, FILTER_VALIDATE_IP) !== false;
        $validHost = preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*'
            . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',
            $server
        ) === 1;
        if (!$validIp && !$validHost) {
            throw new RuntimeException('Geçersiz NTP sunucusu: ' . $server);
        }
        $servers[$server] = $server;
        if (count($servers) > 8) {
            throw new RuntimeException('En fazla 8 NTP sunucusu tanımlanabilir');
        }
    }
    return array_values($servers);
}

function ntp_settings(): array
{
    $rows = pbx_relational_json_rows(
        "SELECT enabled,array_to_string(ntp_servers, ',') AS ntp_servers,"
        . "updated_at,updated_by FROM system_ntp_settings WHERE setting_id=1 LIMIT 1"
    );
    if (!$rows) {
        return [
            'enabled' => true,
            'ntp_servers' => implode(', ', ntp_default_servers()),
            'updated_at' => '',
            'updated_by' => '',
        ];
    }
    return $rows[0];
}

function ntp_live_status(): array
{
    $script = <<<'SH'
set -u
timezone="$(timedatectl show -p Timezone --value 2>/dev/null || true)"
enabled="$(timedatectl show -p NTP --value 2>/dev/null || true)"
synchronized="$(timedatectl show -p NTPSynchronized --value 2>/dev/null || true)"
service="$(systemctl is-active systemd-timesyncd.service 2>/dev/null || true)"
server="$(timedatectl show-timesync -p ServerName --value 2>/dev/null || true)"
address="$(timedatectl show-timesync -p ServerAddress --value 2>/dev/null || true)"
printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$timezone" "$enabled" "$synchronized" "$service" "$server" "$address" \
    "$(date --iso-8601=seconds)"
SH;
    $result = pbx_ssh($script);
    if (empty($result['ok'])) {
        return [
            'ok' => false,
            'error' => trim((string) ($result['output'] ?? 'NTP durumu okunamadı')),
        ];
    }
    $parts = array_pad(explode("\t", trim((string) ($result['output'] ?? ''))), 7, '');
    return [
        'ok' => true,
        'timezone' => $parts[0],
        'enabled' => strtolower($parts[1]) === 'yes',
        'synchronized' => strtolower($parts[2]) === 'yes',
        'service' => $parts[3],
        'server' => $parts[4],
        'address' => $parts[5],
        'system_time' => $parts[6],
    ];
}

function ntp_save_settings(bool $enabled, array $servers): void
{
    $serverSql = $servers
        ? 'ARRAY[' . implode(',', array_map('pbx_sql_literal', $servers)) . ']::text[]'
        : 'ARRAY[]::text[]';
    $sql = "INSERT INTO system_ntp_settings "
        . "(setting_id,enabled,ntp_servers,updated_at,updated_by) VALUES "
        . "(1," . ($enabled ? 'TRUE' : 'FALSE') . ',' . $serverSql . ',now(),'
        . pbx_sql_literal(store_actor()) . ') '
        . "ON CONFLICT (setting_id) DO UPDATE SET enabled=EXCLUDED.enabled,"
        . "ntp_servers=EXCLUDED.ntp_servers,updated_at=now(),updated_by=EXCLUDED.updated_by";
    $result = pbx_odbc_sql($sql);
    if (empty($result['ok']) || str_contains((string) ($result['output'] ?? ''), '[ISQL]ERROR')) {
        throw new RuntimeException('NTP ayarı veritabanına kaydedilemedi: '
            . trim((string) ($result['output'] ?? '')));
    }
}

function ntp_apply_settings(bool $enabled, string|array $serverInput): array
{
    $servers = ntp_normalize_servers($serverInput);
    if ($enabled && !$servers) {
        $servers = ntp_default_servers();
    }
    $config = "[Time]\nNTP=" . implode(' ', $servers) . "\n"
        . "FallbackNTP=ntp.ubuntu.com time.cloudflare.com\n";
    $encoded = base64_encode($config);
    $commands = [
        'install -d -m 0755 -o root -g root /etc/systemd/timesyncd.conf.d',
        'printf %s ' . escapeshellarg($encoded)
            . ' | base64 -d > /etc/systemd/timesyncd.conf.d/astera.conf',
        'chmod 0644 /etc/systemd/timesyncd.conf.d/astera.conf',
        'timedatectl set-ntp ' . ($enabled ? 'true' : 'false'),
    ];
    if ($enabled) {
        $commands[] = 'systemctl enable --now systemd-timesyncd.service';
        $commands[] = 'systemctl restart systemd-timesyncd.service';
    } else {
        $commands[] = 'systemctl disable --now systemd-timesyncd.service';
    }
    $commands[] = 'timedatectl show -p Timezone -p NTP -p NTPSynchronized';
    $result = pbx_ssh(implode("\n", $commands));
    if (empty($result['ok'])) {
        throw new RuntimeException('NTP ayarı uygulanamadı: '
            . trim((string) ($result['output'] ?? '')));
    }
    ntp_save_settings($enabled, $servers);
    return [
        'servers' => $servers,
        'output' => trim((string) ($result['output'] ?? '')),
        'status' => ntp_live_status(),
    ];
}

function ntp_sync_now(): array
{
    $settings = ntp_settings();
    if (empty($settings['enabled'])) {
        throw new RuntimeException('Önce NTP eşitlemesini etkinleştirin');
    }
    $result = pbx_ssh(
        "timedatectl set-ntp true\n"
        . "systemctl restart systemd-timesyncd.service\n"
        . "sleep 2\n"
        . "timedatectl timesync-status --no-pager"
    );
    if (empty($result['ok'])) {
        throw new RuntimeException('NTP eşitlemesi başlatılamadı: '
            . trim((string) ($result['output'] ?? '')));
    }
    return [
        'output' => trim((string) ($result['output'] ?? '')),
        'status' => ntp_live_status(),
    ];
}
