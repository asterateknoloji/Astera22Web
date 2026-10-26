<?php
declare(strict_types=1);

require_once __DIR__ . '/callcenter.php';

function astera_alarm_definitions(): array
{
    return [
        'cpu_usage' => ['system', 'CPU kullanımı', '%', 90, 80, 'CPU kullanımı eşikleri aştığında bildirir.'],
        'ram_usage' => ['system', 'RAM kullanımı', '%', 90, 80, 'Bellek kullanımı eşikleri aştığında bildirir.'],
        'swap_usage' => ['system', 'Swap kullanımı', '%', 80, 60, 'Swap kullanımı eşikleri aştığında bildirir.'],
        'disk_usage' => ['system', 'Disk kullanımı', '%', 90, 80, 'Sistem diski doluluk oranını izler.'],
        'outbound_bandwidth' => ['system', 'Giden bant genişliği', 'Mbit/sn', 100, 80, 'Toplam giden ağ trafiğini izler.'],
        'inbound_bandwidth' => ['system', 'Gelen bant genişliği', 'Mbit/sn', 100, 80, 'Toplam gelen ağ trafiğini izler.'],
        'clock_drift' => ['system', 'Sistem saati eşitleme hatası', '', null, null, 'NTP eşitlemesi kaybolduğunda bildirir.'],
        'critical_service_down' => ['system', 'Kritik servis durdu', '', null, null, 'Asterisk, PostgreSQL, Nginx ve PHP-FPM servislerini izler.'],
        'auto_restart_services' => ['system', 'Duran servisi otomatik başlat', '', null, null, 'Kritik servis durduğunda bir kez yeniden başlatmayı dener.'],

        'trunk_error' => ['pbx', 'Trunk hata kodu', '', null, null, 'SIP kayıt hata durumlarını bildirir.'],
        'trunk_down' => ['pbx', 'Trunk / SBC bağlantısı kesildi', '', null, null, 'Kayıtlı trunk erişilemez olduğunda bildirir.'],
        'trunk_failover' => ['pbx', 'Trunk yedekleme / azami çağrı', '', null, null, 'Trunk kapasite ve yedek geçiş olayları için alarm üretir.'],
        'dns_failure' => ['pbx', 'DNS / ağ çözümleme hatası', '', null, null, 'İnternet ve DNS çözümlemesini izler.'],
        'stun_failure' => ['pbx', 'STUN çözümleme hatası', '', null, null, 'STUN çözümleme olayları için alarm üretir.'],
        'hacking_reject' => ['pbx', 'Anti-hacking reddi', '', null, null, 'Güvenlik engelleme olaylarını bildirir.'],
        'blocked_country_call' => ['pbx', 'Engelli ülkeye çağrı / mesaj', '', null, null, 'Engelli ülke yönü denemelerini bildirir.'],
        'ip_blacklisted' => ['pbx', 'IP kara listeye alındı', '', null, null, 'Fail2ban tarafından engellenen IP olduğunda bildirir.'],
        'extension_added' => ['pbx', 'Yeni abone / kullanıcı eklendi', '', null, null, 'Yeni kullanıcı oluşturma olaylarını bildirir.'],
        'update_available' => ['pbx', 'Sistem güncellemesi mevcut', 'paket', 1, null, 'Kurulabilir sistem paketi olduğunda bildirir.'],
        'backup_completed' => ['pbx', 'Yedekleme tamamlandı / gecikti', 'saat', 26, null, 'Son başarılı yedek yaşını izler.'],
        'emergency_dialed' => ['pbx', 'Acil numara arandı', '', null, null, 'Acil numara arama olaylarını bildirir.'],
        'license_limit' => ['pbx', 'Lisans / eşzamanlı çağrı sınırı', '', null, null, 'Lisans kapasite olaylarını bildirir.'],
        'number_blocked' => ['pbx', 'Numara engellendi', '', null, null, 'Engellenen numara olaylarını bildirir.'],
        'recording_disk' => ['pbx', 'Kayıt diski kullanımı', '%', 90, 80, 'Ses kayıt dizininin disk kullanımını izler.'],
        'voicemail_disk' => ['pbx', 'Sesli mesaj diski kullanımı', '%', 90, 80, 'Sesli mesaj dizininin disk kullanımını izler.'],
        'stop_writes_on_disk_critical' => ['pbx', 'Kritik diskte kayıt/log yazmayı durdur', '%', 97, null, 'Disk kritik seviyeye ulaştığında koruma alarmı üretir.'],
    ];
}

function astera_alarm_crypto_key_path(): string
{
    return astera_env('ASTERA_ALARM_KEY_FILE', '/etc/astera/alarm.key');
}

function astera_alarm_crypto_key(): string
{
    $path = astera_alarm_crypto_key_path();
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        throw new RuntimeException('SMTP parola anahtarı bulunamadı. Kurulum betiğini yeniden çalıştırın.');
    }
    return hash('sha256', trim($raw), true);
}

function astera_alarm_encrypt(string $plain): string
{
    if ($plain === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', astera_alarm_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('SMTP parolası şifrelenemedi.');
    return base64_encode($iv . $tag . $cipher);
}

function astera_alarm_decrypt(string $encoded): string
{
    if ($encoded === '') return '';
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('SMTP parola kaydı geçersiz.');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', astera_alarm_crypto_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) throw new RuntimeException('SMTP parolası çözülemedi.');
    return $plain;
}

function astera_alarm_ensure_rules(string $deptId): void
{
    foreach (astera_alarm_definitions() as $key => $definition) {
        callcenter_execute(
            'INSERT INTO callcenter_alarm_rules '
            . '(dept_id,alarm_key,enabled,threshold_value,warning_value) '
            . 'VALUES (:dept,:alarm,FALSE,:threshold,:warning) ON CONFLICT (dept_id,alarm_key) DO NOTHING',
            ['dept' => $deptId, 'alarm' => $key, 'threshold' => $definition[3], 'warning' => $definition[4]]
        );
    }
}

function astera_alarm_smtp_command($socket, string $command, array $expected): string
{
    if ($command !== '') {
        if (fwrite($socket, $command . "\r\n") === false) throw new RuntimeException('SMTP bağlantısına yazılamadı.');
    }
    $response = '';
    do {
        $line = fgets($socket, 4096);
        if ($line === false) throw new RuntimeException('SMTP sunucusundan yanıt alınamadı.');
        $response .= $line;
    } while (isset($line[3]) && $line[3] === '-');
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('SMTP hatası (' . $code . '): ' . trim(preg_replace('/[\r\n]+/', ' ', $response)));
    }
    return $response;
}

function astera_alarm_send_email(array $smtp, array $recipients, string $subject, string $html): void
{
    if (!$recipients) throw new RuntimeException('Aktif alarm e-posta alıcısı yok.');
    $host = trim((string) ($smtp['smtp_host'] ?? ''));
    $port = (int) ($smtp['smtp_port'] ?? 587);
    $encryption = (string) ($smtp['encryption'] ?? 'tls');
    if ($host === '') throw new RuntimeException('SMTP sunucusu boş.');
    $target = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($target, $errno, $error, (int) ($smtp['timeout_seconds'] ?? 15), STREAM_CLIENT_CONNECT);
    if (!$socket) throw new RuntimeException("SMTP bağlantısı kurulamadı: {$error} ({$errno})");
    stream_set_timeout($socket, (int) ($smtp['timeout_seconds'] ?? 15));
    try {
        astera_alarm_smtp_command($socket, '', [220]);
        astera_alarm_smtp_command($socket, 'EHLO ' . (gethostname() ?: 'astera-pbx'), [250]);
        if ($encryption === 'tls') {
            astera_alarm_smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP TLS bağlantısı başlatılamadı.');
            }
            astera_alarm_smtp_command($socket, 'EHLO ' . (gethostname() ?: 'astera-pbx'), [250]);
        }
        $username = (string) ($smtp['username'] ?? '');
        if ($username !== '') {
            astera_alarm_smtp_command($socket, 'AUTH LOGIN', [334]);
            astera_alarm_smtp_command($socket, base64_encode($username), [334]);
            astera_alarm_smtp_command($socket, base64_encode(astera_alarm_decrypt((string) ($smtp['password_encrypted'] ?? ''))), [235]);
        }
        $fromEmail = trim((string) ($smtp['from_email'] ?? ''));
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Gönderen e-posta adresi geçersiz.');
        astera_alarm_smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        foreach ($recipients as $recipient) {
            astera_alarm_smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        }
        astera_alarm_smtp_command($socket, 'DATA', [354]);
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . sprintf('"%s" <%s>', addcslashes((string) ($smtp['from_name'] ?? 'Astera PBX'), '"\\'), $fromEmail),
            'To: ' . implode(', ', $recipients),
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $body = implode("\r\n", str_split(base64_encode($html), 76));
        astera_alarm_smtp_command($socket, implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.", [250]);
        astera_alarm_smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function astera_alarm_emit_event(
    string $deptId,
    string $key,
    string $summary,
    string $details = '',
    string $severity = 'info'
): bool {
    try {
        $definition = astera_alarm_definitions()[$key] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException('Bilinmeyen alarm anahtarı: ' . $key);
        }
        $rule = callcenter_rows(
            'SELECT enabled FROM callcenter_alarm_rules WHERE dept_id=:dept AND alarm_key=:alarm',
            ['dept' => $deptId, 'alarm' => $key]
        )[0] ?? null;
        if ($rule === null || !in_array(strtolower((string) $rule['enabled']), ['1', 't', 'true', 'yes', 'on'], true)) {
            return false;
        }

        $event = callcenter_rows(
            'INSERT INTO callcenter_alarm_events '
            . '(dept_id,alarm_key,event_type,severity,summary,details) '
            . 'VALUES (:dept,:alarm,:type,:severity,:summary,:details) RETURNING event_id',
            [
                'dept' => $deptId,
                'alarm' => $key,
                'type' => 'triggered',
                'severity' => $severity,
                'summary' => mb_substr($summary, 0, 500),
                'details' => $details,
            ]
        )[0] ?? [];
        $eventId = (int) ($event['event_id'] ?? 0);
        $smtp = callcenter_rows(
            'SELECT * FROM callcenter_alarm_smtp_settings WHERE dept_id=:dept AND enabled',
            ['dept' => $deptId]
        )[0] ?? null;
        $recipients = array_column(callcenter_rows(
            'SELECT email FROM callcenter_alarm_recipients '
            . 'WHERE dept_id=:dept AND active ORDER BY email',
            ['dept' => $deptId]
        ), 'email');
        if (!$smtp || !$recipients || $eventId <= 0) {
            return true;
        }

        $subject = '[Astera PBX] Olay: ' . $definition[1];
        $html = '<h2>' . e($subject) . '</h2>'
            . '<p><strong>Firma:</strong> ' . e($deptId) . '</p>'
            . '<p><strong>Olay:</strong> ' . e($summary) . '</p>'
            . ($details !== '' ? '<p><strong>Detay:</strong> ' . nl2br(e($details)) . '</p>' : '')
            . '<p><strong>Zaman:</strong> ' . date('d.m.Y H:i:s') . '</p>';
        try {
            astera_alarm_send_email($smtp, $recipients, $subject, $html);
            $deliveryStatus = 'sent';
            $deliveryError = '';
        } catch (Throwable $exception) {
            $deliveryStatus = 'failed';
            $deliveryError = mb_substr($exception->getMessage(), 0, 1000);
            error_log("Astera olay alarmı e-posta hatası [{$deptId}/{$key}]: {$deliveryError}");
        }
        foreach ($recipients as $recipient) {
            callcenter_execute(
                'INSERT INTO callcenter_alarm_delivery_attempts '
                . '(dept_id,event_id,recipient_email,status,error_message) '
                . 'VALUES (:dept,:event,:email,:status,:error)',
                [
                    'dept' => $deptId,
                    'event' => $eventId,
                    'email' => $recipient,
                    'status' => $deliveryStatus,
                    'error' => $deliveryError,
                ]
            );
        }
        return $deliveryStatus === 'sent';
    } catch (Throwable $exception) {
        error_log("Astera olay alarmı üretilemedi [{$deptId}/{$key}]: " . $exception->getMessage());
        return false;
    }
}

function astera_alarm_shell(string $command): string
{
    $output = [];
    $code = 0;
    exec($command . ' 2>/dev/null', $output, $code);
    return $code === 0 ? trim(implode("\n", $output)) : '';
}

function astera_alarm_cpu_percent(): float
{
    $read = static function (): array {
        $line = (string) @file('/proc/stat')[0];
        $parts = array_map('intval', preg_split('/\s+/', trim(substr($line, 3))) ?: []);
        return [array_sum($parts), ($parts[3] ?? 0) + ($parts[4] ?? 0)];
    };
    [$total1, $idle1] = $read();
    usleep(250000);
    [$total2, $idle2] = $read();
    return $total2 > $total1 ? round(100 * (1 - (($idle2 - $idle1) / ($total2 - $total1))), 2) : 0.0;
}

function astera_alarm_memory(string $kind): float
{
    $values = [];
    foreach ((array) @file('/proc/meminfo') as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $match)) $values[$match[1]] = (float) $match[2];
    }
    if ($kind === 'swap') {
        $total = $values['SwapTotal'] ?? 0;
        return $total > 0 ? round(100 * ($total - ($values['SwapFree'] ?? 0)) / $total, 2) : 0.0;
    }
    $total = $values['MemTotal'] ?? 0;
    return $total > 0 ? round(100 * ($total - ($values['MemAvailable'] ?? 0)) / $total, 2) : 0.0;
}

function astera_alarm_disk(string $path): float
{
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    return $total && $free !== false ? round(100 * ($total - $free) / $total, 2) : 0.0;
}

function astera_alarm_network_rates(): array
{
    static $rates;
    if (is_array($rates)) return $rates;
    $rx = 0;
    $tx = 0;
    foreach (glob('/sys/class/net/*/statistics/rx_bytes') ?: [] as $file) {
        if (str_contains($file, '/lo/')) continue;
        $rx += (int) @file_get_contents($file);
        $txFile = dirname($file) . '/tx_bytes';
        $tx += (int) @file_get_contents($txFile);
    }
    $now = microtime(true);
    $path = '/var/lib/astera-alarms/network-sample.json';
    $previous = json_decode((string) @file_get_contents($path), true);
    @file_put_contents($path, json_encode(['at' => $now, 'rx' => $rx, 'tx' => $tx]), LOCK_EX);
    $seconds = $now - (float) ($previous['at'] ?? $now);
    if ($seconds <= 0 || $seconds > 600) return $rates = ['in' => 0.0, 'out' => 0.0];
    return $rates = [
        'in' => round(max(0, $rx - (int) ($previous['rx'] ?? $rx)) * 8 / $seconds / 1000000, 3),
        'out' => round(max(0, $tx - (int) ($previous['tx'] ?? $tx)) * 8 / $seconds / 1000000, 3),
    ];
}

function astera_alarm_evaluate(string $key): ?array
{
    if ($key === 'cpu_usage') return [astera_alarm_cpu_percent(), 'CPU kullanımı ölçüldü'];
    if ($key === 'ram_usage') return [astera_alarm_memory('ram'), 'RAM kullanımı ölçüldü'];
    if ($key === 'swap_usage') return [astera_alarm_memory('swap'), 'Swap kullanımı ölçüldü'];
    if ($key === 'disk_usage') return [astera_alarm_disk('/'), 'Sistem diski ölçüldü'];
    if ($key === 'recording_disk') return [astera_alarm_disk(is_dir('/var/spool/asterisk/monitor') ? '/var/spool/asterisk/monitor' : '/'), 'Kayıt diski ölçüldü'];
    if ($key === 'voicemail_disk') return [astera_alarm_disk(is_dir('/var/spool/asterisk/voicemail') ? '/var/spool/asterisk/voicemail' : '/'), 'Sesli mesaj diski ölçüldü'];
    if ($key === 'stop_writes_on_disk_critical') return [astera_alarm_disk('/'), 'Kritik disk seviyesi ölçüldü'];
    if ($key === 'inbound_bandwidth') {
        $value = astera_alarm_network_rates()['in'];
        return [$value, "Gelen trafik: {$value} Mbit/sn"];
    }
    if ($key === 'outbound_bandwidth') {
        $value = astera_alarm_network_rates()['out'];
        return [$value, "Giden trafik: {$value} Mbit/sn"];
    }
    if ($key === 'clock_drift') {
        $synced = astera_alarm_shell('timedatectl show -p NTPSynchronized --value');
        return [$synced === 'yes' ? 0.0 : 1.0, $synced === 'yes' ? 'Sistem saati eşitlenmiş' : 'NTP eşitlemesi yok'];
    }
    if ($key === 'critical_service_down') {
        $services = ['asterisk', 'postgresql', 'nginx'];
        $failed = array_values(array_filter($services, static fn(string $service): bool => astera_alarm_shell('systemctl is-active ' . escapeshellarg($service)) !== 'active'));
        return [$failed ? 1.0 : 0.0, $failed ? 'Duran servisler: ' . implode(', ', $failed) : 'Kritik servisler çalışıyor'];
    }
    if ($key === 'dns_failure') {
        $resolved = gethostbyname('apt.postgresql.org');
        return [$resolved === 'apt.postgresql.org' ? 1.0 : 0.0, $resolved === 'apt.postgresql.org' ? 'DNS çözümlemesi başarısız' : 'DNS çalışıyor'];
    }
    if ($key === 'trunk_down' || $key === 'trunk_error') {
        $output = astera_alarm_shell('asterisk -rx ' . escapeshellarg('pjsip show registrations'));
        $bad = preg_match('/\b(Rejected|Unregistered|Timeout|No Authentication)\b/i', $output) === 1;
        return [$bad ? 1.0 : 0.0, $bad ? 'Kayıtsız veya hatalı trunk bulundu' : 'Trunk kayıt hatası bulunmadı'];
    }
    if ($key === 'ip_blacklisted' || $key === 'hacking_reject') {
        $output = astera_alarm_shell('fail2ban-client status asterisk');
        preg_match('/Currently banned:\s*(\d+)/i', $output, $match);
        $count = (float) ($match[1] ?? 0);
        return [$count, $count > 0 ? "{$count} IP engelli" : 'Engelli IP yok'];
    }
    if ($key === 'update_available') {
        $count = (float) astera_alarm_shell("bash -lc \"apt list --upgradable 2>/dev/null | awk 'NR>1{n++} END{print n+0}'\"");
        return [$count, "{$count} güncelleme mevcut"];
    }
    return null; // Olay tabanlı alarmlar PBX olay üreticileri tarafından beslenir.
}
