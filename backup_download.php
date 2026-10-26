<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/backups.php';
require_login();

if (!is_super()) {
    http_response_code(403);
    exit('Bu işlem yalnızca sistem yöneticisine açıktır.');
}

$backupId = trim((string) ($_GET['id'] ?? ''));
$archive = callcenter_rows(
    'SELECT filename,local_path,size_bytes FROM system_backup_archives WHERE backup_id=:id',
    ['id' => $backupId]
)[0] ?? null;
if (!$archive) {
    http_response_code(404);
    exit('Backup bulunamadı.');
}
$path = realpath((string) $archive['local_path']);
$root = realpath('/var/backups/astera');
if ($path === false || $root === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('Yerel backup dosyası bulunamadı.');
}

header('Content-Type: application/gzip');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . basename((string) $archive['filename']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
