<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Cache-Control: no-store, private');

$extensionId = (string) ($_SESSION['webphone_extension_id'] ?? '');
if ($extensionId === '') {
    json_out(['ok' => false, 'error' => 'WebPhone oturumu gerekli'], 401);
}
$extension = find_by('extensions', 'id', $extensionId);
if (!$extension || empty($extension['webrtc'])) {
    unset($_SESSION['webphone_extension_id']);
    json_out(['ok' => false, 'error' => 'WebPhone erişimi kapalı'], 401);
}
$dept = ast_sanitize_id((string) ($extension['dept'] ?? ''));
if ($dept === '') {
    json_out(['ok' => false, 'error' => 'Firma bilgisi bulunamadı'], 422);
}

$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'astera-webphone-blf-' . hash('sha256', $dept . '|' . $extensionId) . '.json';
if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) <= 2) {
    $cached = json_decode((string) file_get_contents($cacheFile), true);
    if (is_array($cached)) {
        json_out($cached);
    }
}

function webphone_apply_blf_timers(array $status, string $dept): array
{
    $timerFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'astera-webphone-blf-timers-' . hash('sha256', $dept) . '.json';
    $handle = fopen($timerFile, 'c+');
    if ($handle === false) {
        return $status;
    }
    flock($handle, LOCK_EX);
    rewind($handle);
    $timers = json_decode((string) stream_get_contents($handle), true);
    if (!is_array($timers)) {
        $timers = [];
    }
    $now = time();
    $activeTimers = [];
    if (!isset($status['extensions']) || !is_array($status['extensions'])) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return $status;
    }
    foreach ($status['extensions'] as $sip => &$row) {
        $state = (string) ($row['state'] ?? '');
        $callId = (string) ($row['call_id'] ?? '');
        $callAge = max(0, min(86400, (int) ($row['call_age'] ?? 0)));
        $previous = is_array($timers[$sip] ?? null) ? $timers[$sip] : [];
        $sameCall = $callId !== ''
            && hash_equals((string) ($previous['call_id'] ?? ''), $callId);
        $since = 0;

        if ($state === 'ringing') {
            $since = $sameCall && (string) ($previous['state'] ?? '') === 'ringing'
                ? (int) ($previous['since'] ?? $now)
                : $now - $callAge;
        } elseif ($state === 'talking') {
            $since = $sameCall && (string) ($previous['state'] ?? '') === 'talking'
                ? (int) ($previous['since'] ?? $now)
                : $now;
        }

        if ($since > 0) {
            $activeTimers[$sip] = [
                'state' => $state,
                'call_id' => $callId,
                'since' => $since,
                'seen_at' => $now,
            ];
            $row['state_since'] = gmdate('c', $since);
            $row['elapsed_seconds'] = max(0, $now - $since);
        } else {
            $row['state_since'] = null;
            $row['elapsed_seconds'] = 0;
        }
        $row['event_id'] = (string) ($row['call_id'] ?? '');
        unset($row['call_id'], $row['call_age']);
    }
    unset($row);

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($activeTimers, JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $status;
}

function webphone_recent_calls(array $extension, string $dept): array
{
    $sip = sip_user($extension);
    $exten = preg_replace('/\D+/', '', (string) ($extension['exten'] ?? '')) ?? '';
    if ($sip === '' || $exten === '') {
        return [];
    }
    $today = date('Y-m-d');
    $calls = [];
    foreach (pbx_cdr(500, $dept, ['date_from' => $today, 'date_to' => $today]) as $row) {
        $src = preg_replace('/\D+/', '', (string) ($row['src'] ?? '')) ?? '';
        $dst = preg_replace('/\D+/', '', (string) ($row['dst'] ?? '')) ?? '';
        $channel = (string) ($row['channel'] ?? '');
        $dstChannel = (string) ($row['dstchannel'] ?? '');
        $recording = (string) ($row['recordingfile'] ?? '');
        $ownChannel = str_contains($channel, 'PJSIP/' . $sip . '-');
        $ownDestination = str_contains($dstChannel, 'PJSIP/' . $sip . '-');
        $recordedForExtension = str_contains($recording, '-dahili_' . $exten . '-');
        $direction = '';
        $number = '';
        if ($ownChannel && strlen($dst) > 6) {
            $direction = 'outgoing';
            $number = $dst;
        } elseif (($ownDestination || $recordedForExtension) && strlen($src) > 6) {
            $direction = 'incoming';
            $number = $src;
        }
        if ($direction === '' || $number === '') {
            continue;
        }
        $key = crm_call_key($row);
        if (isset($calls[$key])) {
            continue;
        }
        $calls[$key] = [
            'dir' => $direction === 'incoming' ? '↙' : '↗',
            'direction' => $direction,
            'num' => $number,
            'ok' => (string) ($row['disposition'] ?? '') === 'ANSWERED',
            'at' => substr((string) ($row['start'] ?? ''), 11, 8),
        ];
        if (count($calls) >= 10) {
            break;
        }
    }
    return array_values($calls);
}

$status = pbx_blf_status($dept);
$status = webphone_apply_blf_timers($status, $dept);
$status['recent_calls'] = webphone_recent_calls($extension, $dept);
$urlTrigger = find_by('url_triggers', 'dept', $dept);
if (
    $urlTrigger
    && !empty($urlTrigger['enabled'])
    && in_array((string) ($urlTrigger['mode'] ?? 'both'), ['browser', 'both'], true)
) {
    $status['url_trigger'] = [
        'url_template' => (string) ($urlTrigger['url_template'] ?? ''),
        'trigger' => (string) ($urlTrigger['trigger'] ?? 'ring'),
        'number_format' => (string) ($urlTrigger['number_format'] ?? 'digits'),
        'dept' => $dept,
    ];
} else {
    $status['url_trigger'] = null;
}
@file_put_contents(
    $cacheFile,
    json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);
json_out($status, !empty($status['ok']) ? 200 : 503);
