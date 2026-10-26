<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$download = ($_GET['download'] ?? '') === '1';
$requiredPermission = $download ? 'recording_download' : 'recording_play';
supervisor_require_permission($requiredPermission);

$recordingId = max(0, (int) ($_GET['id'] ?? 0));
if ($recordingId === 0) {
    http_response_code(400);
    exit('Geçersiz kayıt');
}

$agentEndpointMatch = static function (string $column): string {
    return "split_part(split_part(COALESCE({$column},''),'/',2),'-',1) "
        . "IN (a.extension,a.dept_id||'_'||a.extension)";
};
$visibleAgentSql = '('
    . 'a.extension=c.src OR a.extension=c.dst OR '
    . $agentEndpointMatch('c.channel') . ' OR ' . $agentEndpointMatch('c.dstchannel')
    . ')';
$rows = callcenter_rows(
    'SELECT r.id,r.object_key,r.format,r.size_bytes,r.linkedid,r.uniqueid '
    . 'FROM call_recordings r WHERE r.dept_id=:dept AND r.id=:id AND EXISTS ('
    . 'SELECT 1 FROM cdr c JOIN callcenter_agents a ON a.dept_id=c.dept_id AND '
    . $visibleAgentSql
    . ' WHERE c.dept_id=r.dept_id AND ('
    . "(r.linkedid<>'' AND c.linkedid=r.linkedid) OR "
    . "(r.uniqueid<>'' AND c.uniqueid=r.uniqueid) OR c.recordingfile=r.object_key"
    . ') AND ' . supervisor_agent_sql('a') . ') LIMIT 1',
    ['dept' => $deptId, 'id' => $recordingId]
);
$recording = $rows[0] ?? null;
if (!$recording) {
    http_response_code(404);
    exit('Ses kaydı bulunamadı veya bu kayıt için yetkiniz yok');
}

$relative = str_replace('\\', '/', trim((string) $recording['object_key'], '/'));
$file = basename($relative);
if ($file === '' || str_contains($relative, '..')
    || !preg_match('#^(?:(?:\d{4})/(?:\d{2})/(?:\d{2})/)?[\w.\-]+\.(wav|WAV|mp3|gsm|ulaw)$#', $relative)) {
    http_response_code(400);
    exit('Geçersiz kayıt yolu');
}

$remote = '/var/spool/asterisk/monitor/' . dept_code_of($deptId) . '/' . $relative;
$localDir = DATA_PATH . DIRECTORY_SEPARATOR . 'recordings';
$local = $localDir . DIRECTORY_SEPARATOR . $deptId . '-' . sha1($relative) . '-' . $file;
$result = pbx_fetch_recording($remote, $local);
if (empty($result['ok']) || !is_file($local)) {
    http_response_code(404);
    exit('Ses kaydı santralden alınamadı');
}

callcenter_audit_write(
    $supervisor,
    $download ? 'audio_download' : 'audio_play',
    'agentdetay',
    $download ? 'Ses kaydı indirildi' : 'Ses kaydı dinlendi',
    'Kayıt #' . $recordingId . ' · ' . $file,
    'callcenter'
);

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$contentTypes = [
    'wav' => 'audio/wav',
    'mp3' => 'audio/mpeg',
    'gsm' => 'audio/gsm',
    'ulaw' => 'audio/basic',
];
header('Content-Type: ' . ($contentTypes[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($local));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $file . '"');
header('X-Content-Type-Options: nosniff');
readfile($local);
exit;
