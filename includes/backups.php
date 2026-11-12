<?php
declare(strict_types=1);

require_once __DIR__ . '/alarms.php';

function backup_settings(): array
{
    return callcenter_rows(
        'SELECT * FROM system_backup_settings WHERE setting_id=1'
    )[0] ?? [
        'setting_id' => 1,
        'company_name' => 'Astera',
        'local_path' => '/var/backups/astera',
        'include_recordings' => false,
        'retention_count' => 10,
        'remote_enabled' => false,
        'remote_host' => '',
        'remote_port' => 22,
        'remote_username' => '',
        'remote_path' => '',
        'remote_auth_mode' => 'password',
        'remote_password_encrypted' => '',
        'remote_key_path' => '',
        'schedule_enabled' => false,
        'schedule_frequency' => 'daily',
        'schedule_time' => '02:00:00',
        'schedule_weekday' => 1,
        'schedule_day' => 1,
        'schedule_month' => 1,
        'schedule_once_at' => null,
        'schedule_next_run' => null,
        'schedule_last_run' => null,
    ];
}

function backup_archives(int $limit = 100): array
{
    return callcenter_rows(
        'SELECT * FROM system_backup_archives ORDER BY created_at DESC,backup_id DESC LIMIT '
        . max(1, min(500, $limit))
    );
}

function backup_jobs(int $limit = 20): array
{
    return callcenter_rows(
        'SELECT * FROM system_backup_jobs ORDER BY requested_at DESC,job_id DESC LIMIT '
        . max(1, min(100, $limit))
    );
}

function backup_bool(mixed $value): bool
{
    return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
}

function backup_schedule_next_run(
    array $settings,
    ?DateTimeImmutable $after = null,
    bool $allowRecentTime = false
): ?DateTimeImmutable
{
    if (!backup_bool($settings['schedule_enabled'] ?? false)) {
        return null;
    }
    $timezone = new DateTimeZone('Europe/Istanbul');
    $after = ($after ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
    $frequency = (string) ($settings['schedule_frequency'] ?? 'daily');
    $time = (string) ($settings['schedule_time'] ?? '02:00');
    if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $parts) !== 1) {
        throw new RuntimeException('Otomatik backup saati geçersiz.');
    }
    $hour = (int) $parts[1];
    $minute = (int) $parts[2];
    if ($hour > 23 || $minute > 59) {
        throw new RuntimeException('Otomatik backup saati geçersiz.');
    }
    $acceptCandidate = static function (DateTimeImmutable $candidate) use ($after, $allowRecentTime): ?DateTimeImmutable {
        if ($candidate > $after) return $candidate;
        if ($allowRecentTime && $candidate >= $after->modify('-5 minutes')) return $after;
        return null;
    };

    if ($frequency === 'once') {
        $onceAt = trim((string) ($settings['schedule_once_at'] ?? ''));
        if ($onceAt === '') return null;
        $candidate = new DateTimeImmutable($onceAt, $timezone);
        return $acceptCandidate($candidate);
    }
    if ($frequency === 'daily') {
        $candidate = $after->setTime($hour, $minute);
        return $acceptCandidate($candidate) ?? $candidate->modify('+1 day');
    }
    if ($frequency === 'weekly') {
        $weekday = max(1, min(7, (int) ($settings['schedule_weekday'] ?? 1)));
        $days = ($weekday - (int) $after->format('N') + 7) % 7;
        $candidate = $after->modify('+' . $days . ' days')->setTime($hour, $minute);
        return $acceptCandidate($candidate) ?? $candidate->modify('+7 days');
    }
    if ($frequency === 'monthly') {
        $day = max(1, min(31, (int) ($settings['schedule_day'] ?? 1)));
        for ($offset = 0; $offset < 120; $offset++) {
            $month = $after->modify('first day of +' . $offset . ' month');
            $yearNumber = (int) $month->format('Y');
            $monthNumber = (int) $month->format('n');
            if (!checkdate($monthNumber, $day, $yearNumber)) continue;
            $candidate = $month->setDate($yearNumber, $monthNumber, $day)->setTime($hour, $minute);
            $accepted = $acceptCandidate($candidate);
            if ($accepted) return $accepted;
        }
        return null;
    }
    if ($frequency === 'yearly') {
        $day = max(1, min(31, (int) ($settings['schedule_day'] ?? 1)));
        $month = max(1, min(12, (int) ($settings['schedule_month'] ?? 1)));
        $startYear = (int) $after->format('Y');
        for ($year = $startYear; $year <= $startYear + 20; $year++) {
            if (!checkdate($month, $day, $year)) continue;
            $candidate = $after->setDate($year, $month, $day)->setTime($hour, $minute);
            $accepted = $acceptCandidate($candidate);
            if ($accepted) return $accepted;
        }
        return null;
    }
    throw new RuntimeException('Otomatik backup sıklığı geçersiz.');
}

function backup_enqueue_due_schedule(): ?int
{
    $pdo = callcenter_pdo();
    if (!$pdo) return null;
    $pdo->beginTransaction();
    try {
        $settings = callcenter_rows(
            'SELECT * FROM system_backup_settings WHERE setting_id=1 FOR UPDATE'
        )[0] ?? null;
        if (!$settings || !backup_bool($settings['schedule_enabled'] ?? false)
            || empty($settings['schedule_next_run'])) {
            $pdo->commit();
            return null;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
        $due = new DateTimeImmutable((string) $settings['schedule_next_run']);
        if ($due > $now) {
            $pdo->commit();
            return null;
        }
        $active = callcenter_rows(
            "SELECT job_id FROM system_backup_jobs WHERE status IN ('queued','running') LIMIT 1"
        )[0] ?? null;
        if ($active) {
            $pdo->commit();
            return null;
        }
        $job = callcenter_rows(
            "INSERT INTO system_backup_jobs (action,backup_id,requested_by) "
            . "VALUES ('backup',NULL,'otomatik-backup') RETURNING job_id"
        )[0] ?? [];
        if ((string) $settings['schedule_frequency'] === 'once') {
            callcenter_execute(
                'UPDATE system_backup_settings SET schedule_enabled=FALSE,schedule_last_run=now(),'
                . 'schedule_next_run=NULL,updated_at=now() WHERE setting_id=1'
            );
        } else {
            $next = backup_schedule_next_run($settings, $now);
            callcenter_execute(
                'UPDATE system_backup_settings SET schedule_last_run=now(),schedule_next_run=:next,'
                . 'updated_at=now() WHERE setting_id=1',
                ['next' => $next?->format(DATE_ATOM)]
            );
        }
        $pdo->commit();
        return (int) ($job['job_id'] ?? 0);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function backup_sftp_quote(string $value): string
{
    if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value)) {
        throw new RuntimeException('SFTP yolu boş veya geçersiz karakter içeriyor.');
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

function backup_sftp_directory_commands(string $remotePath): array
{
    $remotePath = trim(str_replace('\\', '/', $remotePath), '/');
    if ($remotePath === '') {
        throw new RuntimeException('Uzak SFTP dizini tanımlı değil.');
    }
    $commands = [];
    foreach (explode('/', $remotePath) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new RuntimeException('Uzak SFTP dizini geçersiz.');
        }
        $quoted = backup_sftp_quote($part);
        $commands[] = '-mkdir ' . $quoted;
        $commands[] = 'cd ' . $quoted;
    }
    return $commands;
}

function backup_sftp_execute(array $settings, array $commands, string $knownHostsFile): string
{
    $host = trim((string) ($settings['remote_host'] ?? ''));
    $port = (int) ($settings['remote_port'] ?? 22);
    $username = trim((string) ($settings['remote_username'] ?? ''));
    if ($host === '' || $username === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('Uzak SFTP hedefi eksik veya geçersiz.');
    }
    if (!$commands) {
        throw new RuntimeException('SFTP komut listesi boş.');
    }

    $commandFile = tempnam(sys_get_temp_dir(), 'astera-sftp-');
    if ($commandFile === false) {
        throw new RuntimeException('Geçici SFTP komut dosyası oluşturulamadı.');
    }
    try {
        if (file_put_contents($commandFile, implode("\n", $commands) . "\nquit\n", LOCK_EX) === false) {
            throw new RuntimeException('Geçici SFTP komut dosyası yazılamadı.');
        }
        chmod($commandFile, 0600);
        $options = '-P ' . $port
            . ' -o BatchMode=no -o ConnectTimeout=15'
            . ' -o HostKeyAlgorithms=+ssh-rsa'
            . ' -o StrictHostKeyChecking=accept-new'
            . ' -o UserKnownHostsFile=' . escapeshellarg($knownHostsFile);
        if ((string) ($settings['remote_auth_mode'] ?? 'password') === 'key') {
            $keyPath = (string) ($settings['remote_key_path'] ?? '');
            if ($keyPath === '' || !is_readable($keyPath)) {
                throw new RuntimeException('Uzak backup SSH anahtarı okunamıyor.');
            }
            $prefix = 'sftp -i ' . escapeshellarg($keyPath) . ' ' . $options;
        } else {
            $encrypted = (string) ($settings['remote_password_encrypted'] ?? '');
            if ($encrypted === '') {
                throw new RuntimeException('Uzak backup parolası tanımlı değil.');
            }
            $password = astera_alarm_decrypt($encrypted);
            $prefix = 'SSHPASS=' . escapeshellarg($password) . ' sshpass -e sftp ' . $options;
        }
        // OpenSSH sftp -b forces BatchMode=yes and prevents sshpass authentication.
        // Feed commands over stdin instead, then inspect SFTP's stable error messages.
        $command = $prefix . ' ' . escapeshellarg($username . '@' . $host)
            . ' < ' . escapeshellarg($commandFile) . ' 2>&1';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        $message = trim(implode("\n", $output));
        $hasSftpError = preg_match(
            '/Permission denied|not found|No such file|Couldn.t|Invalid command|'
            . 'Connection (?:closed|refused|timed out)|Host key verification failed|Could not resolve/i',
            $message
        ) === 1;
        if ($exitCode !== 0 || $hasSftpError) {
            $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '';
            throw new RuntimeException($message !== '' ? mb_substr($message, 0, 1000) : 'SFTP işlemi başarısız.');
        }
        return $message;
    } finally {
        @unlink($commandFile);
    }
}

function backup_remote_connection_test(array $settings): string
{
    $host = trim((string) ($settings['remote_host'] ?? ''));
    $port = (int) ($settings['remote_port'] ?? 22);
    $username = trim((string) ($settings['remote_username'] ?? ''));
    $remotePath = rtrim(trim((string) ($settings['remote_path'] ?? '')), '/');
    if ($host === '' || $username === '' || $remotePath === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('Uzak SFTP hedefi eksik veya geçersiz.');
    }
    $testFile = tempnam(sys_get_temp_dir(), 'astera-sftp-test-');
    if ($testFile === false) {
        throw new RuntimeException('SFTP test dosyası oluşturulamadı.');
    }
    $remoteTestName = '.astera-write-test-' . bin2hex(random_bytes(6));
    try {
        file_put_contents($testFile, "Astera SFTP test\n", LOCK_EX);
        $commands = backup_sftp_directory_commands($remotePath);
        $commands[] = 'put ' . backup_sftp_quote($testFile) . ' ' . backup_sftp_quote($remoteTestName);
        $commands[] = 'ls -l ' . backup_sftp_quote($remoteTestName);
        $commands[] = 'rm ' . backup_sftp_quote($remoteTestName);
        backup_sftp_execute($settings, $commands, '/tmp/astera-backup-known-hosts');
    } catch (Throwable $exception) {
        throw new RuntimeException(
            'Uzak SFTP bağlantısı başarısız (' . $host . ':' . $port . ').'
            . ' ' . $exception->getMessage(),
            0,
            $exception
        );
    } finally {
        @unlink($testFile);
    }
    return $host . ':' . $port . ' bağlantısı ve ' . $remotePath . ' yazma izni doğrulandı.';
}

function backup_format_bytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 ** 2) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    if ($bytes < 1024 ** 3) return number_format($bytes / 1024 ** 2, 1, ',', '.') . ' MB';
    return number_format($bytes / 1024 ** 3, 2, ',', '.') . ' GB';
}
