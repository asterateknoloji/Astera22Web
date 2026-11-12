<?php
declare(strict_types=1);

function astera_system_power_normalize_confirmation(string $value): string
{
    $value = strtr($value, [
        'İ' => 'I', 'ı' => 'I', 'i' => 'I',
        'Ş' => 'S', 'ş' => 'S',
        'Ğ' => 'G', 'ğ' => 'G',
        'Ü' => 'U', 'ü' => 'U',
        'Ö' => 'O', 'ö' => 'O',
        'Ç' => 'C', 'ç' => 'C',
    ]);
    $value = strtoupper(trim($value));
    return preg_replace('/\s+/', ' ', $value) ?? $value;
}

function astera_system_power_run(string $action): string
{
    if (!in_array($action, ['reboot', 'poweroff'], true)) {
        throw new InvalidArgumentException('Geçersiz sistem gücü işlemi.');
    }
    $command = 'sudo -n /usr/local/sbin/astera-system-power ' . escapeshellarg($action);
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    $message = trim(implode("\n", $output));
    if ($exitCode !== 0) {
        $message = preg_replace('/^ERROR\t/m', '', $message) ?? $message;
        throw new RuntimeException($message !== '' ? mb_substr($message, 0, 2000) : 'Sistem işlemi başlatılamadı.');
    }
    $message = preg_replace('/^OK\t/m', '', $message) ?? $message;
    return trim($message);
}
