#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/backups.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

umask(0007);
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

function backup_command(string $command): string
{
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $text = trim(implode("\n", $output));
    if ($code !== 0) {
        throw new RuntimeException($text !== '' ? $text : "Komut başarısız ({$code})");
    }
    return $text;
}

function backup_claim_job(): ?array
{
    $pdo = callcenter_pdo();
    if (!$pdo) return null;
    $pdo->beginTransaction();
    try {
        $job = callcenter_rows(
            "SELECT * FROM system_backup_jobs WHERE status='queued' "
            . 'ORDER BY requested_at,job_id FOR UPDATE SKIP LOCKED LIMIT 1'
        )[0] ?? null;
        if (!$job) {
            $pdo->commit();
            return null;
        }
        callcenter_execute(
            "UPDATE system_backup_jobs SET status='running',started_at=now() WHERE job_id=:job",
            ['job' => (int) $job['job_id']]
        );
        $pdo->commit();
        return $job;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

function backup_finish_job(int $jobId, bool $success, string $message): void
{
    callcenter_execute(
        'UPDATE system_backup_jobs SET status=:status,output_message=:output,error_message=:error,'
        . 'completed_at=now() WHERE job_id=:job',
        [
            'status' => $success ? 'completed' : 'failed',
            'output' => $success ? mb_substr($message, 0, 4000) : '',
            'error' => $success ? '' : mb_substr($message, 0, 4000),
            'job' => $jobId,
        ]
    );
}

function backup_remote_upload(array $settings, string $localPath): string
{
    $remoteDir = rtrim((string) $settings['remote_path'], '/');
    if (!is_file($localPath) || $remoteDir === '') {
        throw new RuntimeException('Uzak backup hedefi eksik.');
    }
    $commands = backup_sftp_directory_commands($remoteDir);
    $commands[] = 'put ' . backup_sftp_quote($localPath) . ' ' . backup_sftp_quote(basename($localPath));
    backup_sftp_execute($settings, $commands, '/var/backups/astera/known_hosts');
    return $remoteDir . '/' . basename($localPath);
}

function backup_remote_download(array $settings, string $remotePath, string $localPath): void
{
    backup_sftp_execute(
        $settings,
        ['get ' . backup_sftp_quote($remotePath) . ' ' . backup_sftp_quote($localPath)],
        '/var/backups/astera/known_hosts'
    );
}

function backup_remote_delete(array $settings, string $remotePath): void
{
    if ($remotePath === '') return;
    backup_sftp_execute(
        $settings,
        ['rm ' . backup_sftp_quote($remotePath)],
        '/var/backups/astera/known_hosts'
    );
}

function backup_company_filename(string $companyName): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($companyName));
    $safe = preg_replace('/[^A-Za-z0-9]+/', '', $ascii !== false ? $ascii : $companyName);
    return $safe !== '' ? substr($safe, 0, 80) : 'Firma';
}

function backup_create_archive(array $settings): array
{
    $root = rtrim((string) $settings['local_path'], '/');
    if (!str_starts_with($root, '/var/backups/astera')) {
        throw new RuntimeException('Yerel backup yolu güvenli dizin dışında.');
    }
    if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
        throw new RuntimeException('Yerel backup dizini oluşturulamadı.');
    }
    $backupId = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $companyName = trim((string) ($settings['company_name'] ?? 'Astera'));
    $backupTime = new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
    $filename = backup_company_filename($companyName) . '_' . $backupTime->format('Ymd_Hi') . '.tar.gz';
    $archivePath = $root . '/' . $filename;
    $existingName = callcenter_rows(
        'SELECT backup_id FROM system_backup_archives WHERE filename=:filename LIMIT 1',
        ['filename' => $filename]
    )[0] ?? null;
    if (is_file($archivePath) || $existingName) {
        throw new RuntimeException('Bu firma için aynı dakika içinde oluşturulmuş bir backup zaten var.');
    }
    $staging = $root . '/.staging-' . $backupId;
    mkdir($staging . '/database', 0770, true);
    mkdir($staging . '/rootfs/var/www/astera/app', 0770, true);
    try {
        backup_command(
            'sudo -n /usr/local/sbin/astera-backup-dump '
            . escapeshellarg($staging . '/database/asterisk.dump')
        );
        $includeRecordings = backup_bool($settings['include_recordings']);
        backup_command(
            'sudo -n /usr/local/sbin/astera-backup-files '
            . escapeshellarg($staging . '/rootfs') . ' '
            . escapeshellarg($includeRecordings ? 'yes' : 'no')
        );
        $manifest = implode("\n", [
            'format=astera-backup-v1',
            'created_at=' . $backupTime->format(DATE_ATOM),
            'hostname=' . (gethostname() ?: 'unknown'),
            'company_name=' . str_replace(["\r", "\n"], ' ', $companyName),
            'database=asterisk',
            'scope=full-pbx-config',
            'includes_asterisk_media=yes',
            'includes_ssl_and_firewall=yes',
            'includes_network=yes',
            'includes_recordings=' . ($includeRecordings ? 'yes' : 'no'),
            '',
        ]);
        file_put_contents($staging . '/manifest.txt', $manifest, LOCK_EX);
        backup_command('tar -C ' . escapeshellarg($staging) . ' -czf ' . escapeshellarg($archivePath) . ' .');
        $size = filesize($archivePath);
        $checksum = hash_file('sha256', $archivePath);
        if ($size === false || $checksum === false) {
            throw new RuntimeException('Backup dosyası doğrulanamadı.');
        }
        callcenter_execute(
            'INSERT INTO system_backup_archives '
            . '(backup_id,filename,local_path,storage_status,size_bytes,sha256_checksum,includes_recordings,completed_at) '
            . 'VALUES (:id,:filename,:path,:storage,:size,:checksum,:recordings,now())',
            [
                'id' => $backupId, 'filename' => $filename, 'path' => $archivePath,
                'storage' => 'local', 'size' => $size, 'checksum' => $checksum,
                'recordings' => $includeRecordings,
            ]
        );
        $remotePath = '';
        if (backup_bool($settings['remote_enabled'])) {
            $remotePath = backup_remote_upload($settings, $archivePath);
            callcenter_execute(
                "UPDATE system_backup_archives SET remote_path=:remote,storage_status='both' WHERE backup_id=:id",
                ['remote' => $remotePath, 'id' => $backupId]
            );
        }
        return ['id' => $backupId, 'path' => $archivePath, 'remote' => $remotePath, 'size' => $size];
    } finally {
        if (is_dir($staging)) backup_command('rm -rf -- ' . escapeshellarg($staging));
    }
}

function backup_apply_retention(array $settings): void
{
    $keep = max(1, (int) $settings['retention_count']);
    $rows = backup_archives(500);
    foreach (array_slice($rows, $keep) as $row) {
        $localPath = (string) $row['local_path'];
        if ($localPath !== '' && str_starts_with($localPath, '/var/backups/astera/') && is_file($localPath)) {
            @unlink($localPath);
        }
        if ((string) $row['remote_path'] !== '') {
            callcenter_execute(
                "UPDATE system_backup_archives SET storage_status='remote' WHERE backup_id=:id",
                ['id' => (string) $row['backup_id']]
            );
        } else {
            callcenter_execute(
                'DELETE FROM system_backup_archives WHERE backup_id=:id',
                ['id' => (string) $row['backup_id']]
            );
        }
    }
}

function backup_process_job(array $job): string
{
    $settings = backup_settings();
    $action = (string) $job['action'];
    if ($action === 'backup') {
        $archive = backup_create_archive($settings);
        backup_apply_retention($settings);
        return 'Backup tamamlandı: ' . basename((string) $archive['path']);
    }
    $backupId = (string) ($job['backup_id'] ?? '');
    $archive = callcenter_rows(
        'SELECT * FROM system_backup_archives WHERE backup_id=:id',
        ['id' => $backupId]
    )[0] ?? null;
    if (!$archive) throw new RuntimeException('Backup kaydı bulunamadı.');

    if ($action === 'delete') {
        $localPath = (string) $archive['local_path'];
        if (is_file($localPath) && str_starts_with($localPath, '/var/backups/astera/')) unlink($localPath);
        if ((string) $archive['remote_path'] !== '') backup_remote_delete($settings, (string) $archive['remote_path']);
        callcenter_execute('DELETE FROM system_backup_archives WHERE backup_id=:id', ['id' => $backupId]);
        return 'Backup silindi.';
    }
    if ($action === 'remote_upload') {
        $localPath = (string) $archive['local_path'];
        if (!is_file($localPath)) throw new RuntimeException('Yerel backup dosyası bulunamadı.');
        $remotePath = backup_remote_upload($settings, $localPath);
        callcenter_execute(
            "UPDATE system_backup_archives SET remote_path=:remote,storage_status='both' WHERE backup_id=:id",
            ['remote' => $remotePath, 'id' => $backupId]
        );
        return 'Backup uzak hedefe aktarıldı.';
    }
    if ($action === 'restore') {
        $localPath = (string) $archive['local_path'];
        if (!is_file($localPath)) {
            if ((string) $archive['remote_path'] === '') throw new RuntimeException('Backup dosyası hiçbir depoda bulunamadı.');
            backup_remote_download($settings, (string) $archive['remote_path'], $localPath);
        }
        $checksum = hash_file('sha256', $localPath);
        if ($checksum === false || !hash_equals((string) $archive['sha256_checksum'], $checksum)) {
            throw new RuntimeException('Backup checksum doğrulaması başarısız.');
        }
        return backup_command(
            'sudo -n /usr/local/sbin/astera-backup-restore '
            . escapeshellarg($localPath) . ' ' . escapeshellarg($checksum)
        );
    }
    throw new InvalidArgumentException('Bilinmeyen backup işi.');
}

$nextScheduleCheck = 0;
while ($running) {
    if (time() >= $nextScheduleCheck) {
        try {
            backup_enqueue_due_schedule();
        } catch (Throwable $exception) {
            error_log('Otomatik backup zamanlaması kontrol edilemedi: ' . $exception->getMessage());
        }
        $nextScheduleCheck = time() + 30;
    }
    $job = backup_claim_job();
    if (!$job) {
        sleep(2);
        continue;
    }
    try {
        $message = backup_process_job($job);
        backup_finish_job((int) $job['job_id'], true, $message);
    } catch (Throwable $exception) {
        error_log('Backup işi başarısız: ' . $exception->getMessage());
        backup_finish_job((int) $job['job_id'], false, $exception->getMessage());
    }
}
