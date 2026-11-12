<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/store.php';
require_once $root . '/includes/ssh.php';
require_once $root . '/includes/generator.php';
require_once $root . '/includes/tenant.php';
require_once $root . '/includes/ami.php';
require_once $root . '/includes/url_trigger.php';
store_init();

set_time_limit(0);
$previous = [];
$fired = [];

while (true) {
    $configs = array_values(array_filter(
        store_read('url_triggers'),
        static fn($row) =>
            in_array((string) ($row['mode'] ?? 'both'), ['server', 'both'], true)
    ));
    $extensionsByDeptSip = [];
    foreach (store_read('extensions') as $extensionRow) {
        $extensionDept = (string) ($extensionRow['dept'] ?? '');
        $extensionSip = sip_user($extensionRow);
        if ($extensionDept !== '' && $extensionSip !== '') {
            $extensionsByDeptSip[$extensionDept][$extensionSip] = $extensionRow;
        }
    }
    $activeDepartments = [];
    foreach ($configs as $config) {
        $dept = (string) ($config['dept'] ?? '');
        if ($dept === '') {
            continue;
        }
        $activeDepartments[$dept] = true;
        $status = pbx_blf_status($dept);
        if (empty($status['ok'])) {
            continue;
        }
        $current = (array) ($status['extensions'] ?? []);
        if (!isset($previous[$dept])) {
            $previous[$dept] = $current;
            continue;
        }
        foreach ($current as $sip => $row) {
            $extensionConfig = $extensionsByDeptSip[$dept][$sip] ?? null;
            if (!url_trigger_enabled_for_extension($config, $extensionConfig)) {
                continue;
            }
            $state = (string) ($row['state'] ?? '');
            $label = (string) ($row['label'] ?? '');
            $peer = (string) ($row['peer'] ?? '');
            $callId = (string) ($row['call_id'] ?? '');
            $extension = (string) ($row['extension'] ?? '');
            $direction = (string) ($row['direction'] ?? '');
            if (!url_trigger_caller_allowed($peer, $config)) {
                continue;
            }
            $before = (array) ($previous[$dept][$sip] ?? []);
            $event = '';

            if (
                $state === 'ringing'
                && $direction === 'incoming'
                && $peer !== ''
                && in_array((string) ($config['trigger'] ?? 'ring'), ['ring', 'both'], true)
                && (
                    (string) ($before['state'] ?? '') !== 'ringing'
                    || (string) ($before['call_id'] ?? '') !== $callId
                )
            ) {
                $event = 'ring';
            } elseif (
                $state === 'talking'
                && $direction === 'incoming'
                && $peer !== ''
                && in_array((string) ($config['trigger'] ?? 'ring'), ['answer', 'both'], true)
                && (
                    (string) ($before['state'] ?? '') !== 'talking'
                    || (string) ($before['call_id'] ?? '') !== $callId
                )
            ) {
                $event = 'answer';
            }
            if ($event === '') {
                continue;
            }
            $dedupe = hash('sha256', $dept . '|' . $sip . '|' . $event . '|' . $callId);
            if (isset($fired[$dedupe])) {
                continue;
            }
            $url = url_trigger_build_url($config, $peer, $extension, $event, $callId);
            $result = url_trigger_request($url);
            $fired[$dedupe] = time();
            echo json_encode([
                'time' => date(DATE_ATOM),
                'dept' => $dept,
                'extension' => $extension,
                'event' => $event,
                'caller' => $peer,
                'http_code' => $result['code'] ?? 0,
                'ok' => !empty($result['ok']),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }
        $previous[$dept] = $current;
    }
    foreach (array_keys($previous) as $dept) {
        if (!isset($activeDepartments[$dept])) {
            unset($previous[$dept]);
        }
    }
    $cutoff = time() - 3600;
    $fired = array_filter($fired, static fn($timestamp) => $timestamp >= $cutoff);
    sleep(2);
}
