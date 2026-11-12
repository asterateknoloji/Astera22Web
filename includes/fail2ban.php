<?php
declare(strict_types=1);

function astera_fail2ban_run(string $action, array $arguments = []): string
{
    if (!in_array($action, ['status', 'configure', 'banip', 'unbanip', 'allowip'], true)) {
        throw new InvalidArgumentException('Geçersiz Fail2ban işlemi.');
    }
    $command = 'sudo -n /usr/local/sbin/astera-fail2ban ' . escapeshellarg($action);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg((string) $argument);
    }
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    $message = trim(implode("\n", $output));
    if ($exitCode !== 0) {
        $message = preg_replace('/^ERROR\t/m', '', $message) ?? $message;
        throw new RuntimeException(
            $message !== '' ? mb_substr($message, 0, 4000) : 'Fail2ban işlemi başarısız.'
        );
    }
    return $message;
}

function astera_fail2ban_status(): array
{
    $status = [
        'installed' => false,
        'service' => 'inactive',
        'jail' => 'inactive',
        'enabled' => false,
        'bantime' => 86400,
        'findtime' => 600,
        'maxretry' => 5,
        'ignoreip' => '127.0.0.0/8 ::1 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16',
        'mail_enabled' => false,
        'mail_recipient' => 'teknik@asterateknoloji.com.tr',
        'currently_banned' => 0,
        'total_banned' => 0,
        'banned_ips' => [],
    ];
    foreach (preg_split('/\R/', astera_fail2ban_run('status')) ?: [] as $line) {
        [$key, $value] = array_pad(explode("\t", $line, 2), 2, '');
        if ($key === 'banned_ip') {
            if (filter_var($value, FILTER_VALIDATE_IP)) {
                $status['banned_ips'][] = $value;
            }
            continue;
        }
        if (!array_key_exists($key, $status)) {
            continue;
        }
        $status[$key] = match ($key) {
            'installed', 'enabled', 'mail_enabled' => in_array(strtolower($value), ['yes', 'true', '1'], true),
            'bantime', 'findtime', 'maxretry', 'currently_banned', 'total_banned' => (int) $value,
            default => $value,
        };
    }
    $status['banned_ips'] = array_values(array_unique($status['banned_ips']));
    return $status;
}

function astera_fail2ban_network_valid(string $network): bool
{
    [$address, $prefix] = array_pad(explode('/', $network, 2), 2, null);
    if (filter_var($address, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    if ($prefix === null) {
        return true;
    }
    if (preg_match('/^\d{1,3}$/D', $prefix) !== 1) {
        return false;
    }
    $maximum = str_contains($address, ':') ? 128 : 32;
    return (int) $prefix >= 0 && (int) $prefix <= $maximum;
}

function astera_fail2ban_normalize_ignoreip(string $input): string
{
    $networks = preg_split('/[\s,]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($networks) > 100) {
        throw new InvalidArgumentException('En fazla 100 izinli IP/ağ tanımlanabilir.');
    }
    foreach ($networks as $network) {
        if (!astera_fail2ban_network_valid($network)) {
            throw new InvalidArgumentException('Geçersiz izinli IP/ağ: ' . $network);
        }
    }
    return implode(' ', array_values(array_unique($networks)));
}

function astera_fail2ban_configure(array $input): void
{
    $bantime = (int) ($input['bantime'] ?? 86400);
    $findtime = (int) ($input['findtime'] ?? 600);
    $maxretry = (int) ($input['maxretry'] ?? 5);
    if ($bantime < 60 || $bantime > 31536000) {
        throw new InvalidArgumentException('Engelleme süresi 60 saniye ile 1 yıl arasında olmalıdır.');
    }
    if ($findtime < 10 || $findtime > 86400) {
        throw new InvalidArgumentException('Takip süresi 10 saniye ile 1 gün arasında olmalıdır.');
    }
    if ($maxretry < 1 || $maxretry > 100) {
        throw new InvalidArgumentException('Deneme sayısı 1-100 arasında olmalıdır.');
    }
    $mailRecipients = preg_split(
        '/[\s,;]+/',
        trim((string) ($input['mail_recipient'] ?? '')),
        -1,
        PREG_SPLIT_NO_EMPTY
    ) ?: [];
    $mailRecipients = array_values(array_unique($mailRecipients));
    if (!$mailRecipients || count($mailRecipients) > 10) {
        throw new InvalidArgumentException('1-10 bildirim e-posta adresi girin.');
    }
    foreach ($mailRecipients as $mailRecipient) {
        if (filter_var($mailRecipient, FILTER_VALIDATE_EMAIL) === false || mb_strlen($mailRecipient) > 254) {
            throw new InvalidArgumentException('Geçersiz bildirim e-posta adresi: ' . $mailRecipient);
        }
    }
    astera_fail2ban_run('configure', [
        !empty($input['enabled']) ? 'yes' : 'no',
        $bantime,
        $findtime,
        $maxretry,
        astera_fail2ban_normalize_ignoreip((string) ($input['ignoreip'] ?? '')),
        !empty($input['mail_enabled']) ? 'yes' : 'no',
        implode(',', $mailRecipients),
    ]);
}

function astera_fail2ban_ip_action(string $action, string $ip): void
{
    $ip = trim($ip);
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        throw new InvalidArgumentException('Geçerli bir IP adresi girin.');
    }
    astera_fail2ban_run($action, [$ip]);
}
