<?php
declare(strict_types=1);

function astera_system_update_run(string $action, array $packages = []): string
{
    if (!in_array($action, ['list', 'refresh', 'apply'], true)) {
        throw new InvalidArgumentException('Geçersiz güncelleme işlemi.');
    }
    $arguments = [$action];
    foreach ($packages as $package) {
        $package = trim((string) $package);
        if (preg_match('/^[a-z0-9][a-z0-9+.-]*$/D', $package) !== 1) {
            throw new InvalidArgumentException('Geçersiz paket adı.');
        }
        $arguments[] = $package;
    }
    if ($action === 'apply' && (count($arguments) < 2 || count($arguments) > 101)) {
        throw new InvalidArgumentException('Bir işlemde 1-100 paket seçilebilir.');
    }
    $command = 'sudo -n /usr/local/sbin/astera-system-update '
        . implode(' ', array_map('escapeshellarg', $arguments));
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    $message = trim(implode("\n", $output));
    if ($exitCode !== 0) {
        $message = preg_replace('/^ERROR\t/m', '', $message) ?? $message;
        throw new RuntimeException($message !== '' ? mb_substr($message, 0, 4000) : 'Güncelleme işlemi başarısız.');
    }
    return $message;
}

function astera_system_updates(): array
{
    $output = astera_system_update_run('list');
    if ($output === '') return [];
    $updates = [];
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        $parts = explode("\t", $line, 7);
        if (count($parts) !== 7) continue;
        [$package, $installed, $candidate, $service, $risk, $reason, $necessity] = $parts;
        if (preg_match('/^[a-z0-9][a-z0-9+.-]*$/D', $package) !== 1) continue;
        $updates[] = [
            'package' => $package,
            'installed' => $installed,
            'candidate' => $candidate,
            'service' => $service,
            'blocked' => $risk === 'blocked',
            'reason' => $reason,
            'necessity' => $necessity,
        ];
    }
    return $updates;
}
