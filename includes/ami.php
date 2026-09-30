<?php
declare(strict_types=1);

function pbx_odbc_sql(string $sql, bool $delimited = false): array
{
    $sql = rtrim($sql, " \t\n\r\0\x0B;") . ";\n";
    if (strlen($sql) > 6000) {
        $local = tempnam(sys_get_temp_dir(), 'astera-sql-');
        if ($local === false || file_put_contents($local, $sql) === false) {
            return ['ok' => false, 'code' => 1, 'output' => 'Geçici SQL dosyası oluşturulamadı'];
        }
        $remote = '/tmp/astera-sql-' . bin2hex(random_bytes(8)) . '.sql';
        $upload = pbx_upload($local, $remote);
        @unlink($local);
        if (!$upload['ok']) {
            return $upload;
        }
        $result = pbx_ssh(
            'isql -b ' . ($delimited ? "-d'^' -c " : '') . 'asterisk < '
            . escapeshellarg($remote) . '; code=$?; rm -f ' . escapeshellarg($remote) . '; exit $code'
        );
        return $result;
    }
    $encoded = base64_encode($sql);
    return pbx_ssh(
        'echo ' . escapeshellarg($encoded) . ' | base64 -d | isql -b '
        . ($delimited ? "-d'^' -c " : '')
        . 'asterisk'
    );
}

function pbx_odbc_rows(string $sql, ?bool &$queryOk = null): array
{
    $res = pbx_odbc_sql($sql, true);
    $queryOk = $res['ok'] && !str_contains((string) $res['output'], '[ISQL]ERROR');
    if (!$queryOk) {
        return [];
    }
    $lines = preg_split('/\r?\n/', trim($res['output']));
    if (!$lines || count($lines) < 2) {
        return [];
    }
    $headers = str_getcsv((string) array_shift($lines), '^');
    $rows = [];
    foreach ($lines as $line) {
        $values = str_getcsv($line, '^');
        if (count($values) !== count($headers)) {
            continue;
        }
        $rows[] = array_combine($headers, $values);
    }
    return $rows;
}

function pbx_pg_admin(string $sql): array
{
    $encoded = base64_encode(rtrim($sql, " \t\n\r\0\x0B;") . ";\n");
    return pbx_ssh(
        'echo ' . escapeshellarg($encoded)
        . ' | base64 -d | sudo -u postgres psql -v ON_ERROR_STOP=1 -d '
        . escapeshellarg(PBX_DB_NAME)
    );
}

function pbx_sql_literal(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function pbx_status(?string $deptId = null): array
{
    $blob = pbx_ssh(implode("\n", [
        "echo '===UPTIME==='",
        "asterisk -rx 'core show uptime'",
        "echo '===ENDPOINTS==='",
        "asterisk -rx 'pjsip show endpoints'",
        "echo '===CHANNELS==='",
        "asterisk -rx 'core show channels'",
        "echo '===CHANNELS_CONCISE==='",
        "asterisk -rx 'core show channels concise'",
        "echo '===REGS==='",
        "asterisk -rx 'pjsip show registrations'",
    ]));

    $text = $blob['output'];
    $uptime = section($text, 'UPTIME', 'ENDPOINTS');
    $endpoints = section($text, 'ENDPOINTS', 'CHANNELS');
    $channels = section($text, 'CHANNELS', 'CHANNELS_CONCISE');
    $channelsConcise = section($text, 'CHANNELS_CONCISE', 'REGS');
    $regs = section($text, 'REGS', null);

    $registered = [];
    if (preg_match_all('/Contact:\s+([^\/\s<]+)\//', $endpoints, $m)) {
        $registered = array_values(array_unique($m[1]));
    }

    $sipAllow = null;
    $channelAllow = null;
    if ($deptId) {
        $sipAllow = [];
        foreach (store_read('extensions') as $ext) {
            if ((string) ($ext['dept'] ?? '') === $deptId) {
                $sipAllow[] = sip_user($ext);
            }
        }
        $registered = array_values(array_intersect($registered, $sipAllow));
        $channelAllow = $sipAllow;
        foreach (store_read('trunks') as $trunk) {
            if ((string) ($trunk['dept'] ?? '') === $deptId && !empty($trunk['enabled'])) {
                $channelAllow[] = (string) ($trunk['id'] ?? '');
            }
        }
    }

    $activeCalls = 0;
    if (preg_match('/(\d+)\s+active call/', $channels, $m)) {
        $activeCalls = (int) $m[1];
    }

    $conciseRows = [];
    foreach (preg_split('/\r?\n/', $channelsConcise) as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, '!')) {
            continue;
        }
        $parts = explode('!', $line);
        $channel = (string) ($parts[0] ?? '');
        $context = (string) ($parts[1] ?? '');
        if ($channelAllow !== null) {
            $keep = in_array(dept_code_of($deptId), $parts, true);
            foreach ($channelAllow as $sip) {
                if (str_contains($channel, 'PJSIP/' . $sip)) {
                    $keep = true;
                    break;
                }
            }
            if (!$keep && !str_contains($context, '-' . dept_code_of($deptId))) {
                continue;
            }
        }
        $conciseRows[] = $parts;
    }
    // Asterisk can retain already hung-up channel objects in the concise
    // output even though it reports no active calls. Do not present those
    // stale dialplan objects as live conversations.
    if ($activeCalls === 0) {
        $conciseRows = [];
    }

    $ringingExtensions = [];
    foreach ($conciseRows as $parts) {
        $state = strtolower((string) ($parts[4] ?? ''));
        if (str_contains($state, 'ring')) {
            $ringingExtensions[(string) ($parts[2] ?? '')] = true;
        }
    }

    $ivrContexts = [];
    foreach (store_read('ivrs') as $ivr) {
        if ($deptId !== null && (string) ($ivr['dept'] ?? '') !== $deptId) {
            continue;
        }
        $ivrContexts['ivr-' . (string) ($ivr['id'] ?? '')] = $ivr;
    }
    $queueNames = [];
    $queuesByExtension = [];
    foreach (store_read('queues') as $queue) {
        if ($deptId !== null && (string) ($queue['dept'] ?? '') !== $deptId) {
            continue;
        }
        $queueNames[(string) ($queue['id'] ?? '')] = (string) ($queue['name'] ?? $queue['id'] ?? '');
        $queueExtension = (string) ($queue['exten'] ?? '');
        if ($queueExtension !== '') {
            $queuesByExtension[$queueExtension] = $queue;
        }
    }
    $ringGroupsByExtension = [];
    foreach (store_read('ringgroups') as $ringGroup) {
        if ($deptId !== null && (string) ($ringGroup['dept'] ?? '') !== $deptId) {
            continue;
        }
        $ringGroupExtension = (string) ($ringGroup['exten'] ?? '');
        if ($ringGroupExtension !== '') {
            $ringGroupsByExtension[$ringGroupExtension] = $ringGroup;
        }
    }
    $extensionsBySip = [];
    foreach (store_read('extensions') as $extensionRow) {
        if ($deptId !== null && (string) ($extensionRow['dept'] ?? '') !== $deptId) {
            continue;
        }
        $sip = sip_user($extensionRow);
        if ($sip !== '') {
            $extensionsBySip[$sip] = $extensionRow;
        }
    }
    $extensionForChannel = static function (string $channel) use ($extensionsBySip): ?array {
        if (!preg_match('#^PJSIP/(.+)-[A-Fa-f0-9]+$#', $channel, $channelMatch)) {
            return null;
        }
        return $extensionsBySip[$channelMatch[1]] ?? null;
    };

    $channelRows = [];
    $queueCalls = [];
    $ringGroupCalls = [];
    $directCalls = [];
    $outboundCalls = [];
    foreach ($conciseRows as $parts) {
        $channel = (string) ($parts[0] ?? '');
        $context = (string) ($parts[1] ?? '');
        $extension = (string) ($parts[2] ?? '');
        $state = (string) ($parts[4] ?? '');
        $application = (string) ($parts[5] ?? '');
        $data = (string) ($parts[6] ?? '');
        $caller = trim((string) ($parts[7] ?? ''));
        if (
            $application === 'AppDial'
            || $application === 'NoOp'
            || $application === 'Return'
            || $application === '(None)'
            || $application === ''
            || $extension === 'h'
            || $extension === 'transfer-preserve'
        ) {
            continue;
        }
        $callerDigits = preg_replace('/\D+/', '', $caller) ?? '';
        $callerLabel = $callerDigits !== '' ? $callerDigits : ($caller !== '' ? $caller : 'Bilinmeyen');

        if (isset($ivrContexts[$context])) {
            $ivr = $ivrContexts[$context];
            $ivrName = trim((string) ($ivr['name'] ?? $ivr['id'] ?? 'IVR'));
            $sound = trim((string) ($ivr['sound'] ?? ''));
            if ($application === 'Background' && $data !== '') {
                $sound = $data;
            }
            $detail = $application === 'WaitExten'
                ? 'Tuşlama bekleniyor'
                : ($sound !== '' ? 'Çalan dosya: ' . $sound : 'IVR aktif');
            $channelRows[] = "{$callerLabel} → IVR: {$ivrName} · {$detail}";
            continue;
        }
        if ($application === 'Queue' || $application === 'AppQueue') {
            $queueId = explode(',', $data, 2)[0];
            $queue = $queuesByExtension[$extension] ?? null;
            $queueName = trim((string) ($queue['name'] ?? ($queueNames[$queueId] ?? '')));
            $queueExtension = (string) ($queue['exten'] ?? $extension);
            $queueLabel = trim($queueExtension . ' ' . $queueName);

            $agent = $extensionForChannel($channel);
            $detail = 'Gelen: ' . $callerLabel . ' → Kuyruk: '
                . ($queueLabel !== '' ? $queueLabel : ($queueId !== '' ? $queueId : 'Bilinmeyen'));
            if ($agent !== null) {
                $agentExtension = (string) ($agent['exten'] ?? '');
                $agentName = trim((string) ($agent['name'] ?? ''));
                $agentLabel = trim($agentExtension . ' ' . $agentName);
                $bridgeId = (string) ($parts[12] ?? '');
                $talking = strtolower($state) === 'up' && $bridgeId !== '';
                $detail .= ' → Dahili ' . $agentLabel . ($talking ? ' ile görüşüyor' : ' çalıyor');
            } else {
                $detail .= ' → Kuyrukta bekliyor';
            }
            $callKey = $callerDigits . '|' . $queueExtension;
            if (!isset($queueCalls[$callKey]) || $agent !== null) {
                $queueCalls[$callKey] = $detail;
            }
            continue;
        }
        $outboundSource = $extensionForChannel($channel);
        $outboundTarget = preg_replace('/\D+/', '', $extension) ?? '';
        if (
            $application === 'Dial'
            && (
                str_starts_with($context, 'out-')
                || ($outboundSource !== null && strlen($outboundTarget) >= 7)
            )
        ) {
            $source = $outboundSource;
            $sourceExtension = (string) ($source['exten'] ?? '');
            $sourceName = trim((string) ($source['name'] ?? ''));
            $sourceLabel = trim($sourceExtension . ' ' . $sourceName);
            $target = $outboundTarget;
            if ($target === '' && preg_match('/PJSIP\/([^@,!]+)/', $data, $targetMatch)) {
                $target = preg_replace('/\D+/', '', $targetMatch[1]) ?? '';
            }
            $bridgeId = (string) ($parts[12] ?? '');
            $talking = $bridgeId !== '';
            $detail = 'Giden: Dahili ' . ($sourceLabel !== '' ? $sourceLabel : 'Bilinmeyen')
                . ' → Telefon: ' . ($target !== '' ? $target : 'Bilinmeyen')
                . ($talking ? ' ile görüşüyor' : ' çalıyor');
            $duration = max(0, (int) ($parts[11] ?? 0));
            $rank = ($talking ? 1000000000 : 0) - $duration;
            $callKey = $sourceExtension . '|' . $target;
            if (!isset($outboundCalls[$callKey]) || $rank > $outboundCalls[$callKey]['rank']) {
                $outboundCalls[$callKey] = ['rank' => $rank, 'detail' => $detail];
            }
            continue;
        }
        if ($application === 'Dial' && isset($ringGroupsByExtension[$extension])) {
            $ringGroup = $ringGroupsByExtension[$extension];
            $ringGroupName = trim((string) ($ringGroup['name'] ?? ''));
            $ringGroupLabel = trim($extension . ' ' . $ringGroupName);
            $bridgeId = (string) ($parts[12] ?? '');
            $agent = null;
            if ($bridgeId !== '') {
                foreach ($conciseRows as $linkedParts) {
                    if (
                        (string) ($linkedParts[0] ?? '') === $channel
                        || (string) ($linkedParts[12] ?? '') !== $bridgeId
                    ) {
                        continue;
                    }
                    $agent = $extensionForChannel((string) ($linkedParts[0] ?? ''));
                    if ($agent !== null) {
                        break;
                    }
                }
            }
            if ($agent === null && preg_match('/PJSIP\/([^\/@,!&]+)/', $data, $targetMatch)) {
                $agent = $extensionsBySip[$targetMatch[1]] ?? null;
            }

            $detail = 'Gelen: ' . $callerLabel . ' → Ring grup: ' . $ringGroupLabel;
            if ($agent !== null) {
                $agentExtension = (string) ($agent['exten'] ?? '');
                $agentName = trim((string) ($agent['name'] ?? ''));
                $agentLabel = trim($agentExtension . ' ' . $agentName);
                $detail .= ' → Dahili ' . $agentLabel
                    . ($bridgeId !== '' ? ' ile görüşüyor' : ' çalıyor');
            } else {
                $detail .= ' → Çalıyor';
            }
            $ringGroupCalls[$callerDigits . '|' . $extension] = $detail;
            continue;
        }
        if ($application === 'Dial' && $extension !== '') {
            $bridgeId = (string) ($parts[12] ?? '');
            $talking = $bridgeId !== '';
            $extensionRow = null;
            foreach ($extensionsBySip as $candidate) {
                if ((string) ($candidate['exten'] ?? '') === $extension) {
                    $extensionRow = $candidate;
                    break;
                }
            }
            $extensionName = trim((string) ($extensionRow['name'] ?? ''));
            $extensionLabel = trim($extension . ' ' . $extensionName);
            $detail = 'Gelen: ' . $callerLabel . ' → Dahili ' . $extensionLabel
                . ($talking ? ' ile görüşüyor' : ' çalıyor');
            $duration = max(0, (int) ($parts[11] ?? 0));
            // Aktarımda eski Dial ayağı kısa süre daha görünebilir. Köprüdeki
            // ve daha yeni olan ayağı aynı aramanın güncel hedefi kabul et.
            $rank = ($talking ? 1000000000 : 0) - $duration;
            $callKey = $callerDigits !== '' ? $callerDigits : $callerLabel;
            if (!isset($directCalls[$callKey]) || $rank > $directCalls[$callKey]['rank']) {
                $directCalls[$callKey] = ['rank' => $rank, 'detail' => $detail];
            }
            continue;
        }
        $channelRows[] = "{$callerLabel} → {$extension} · {$state}"
            . ($application !== '' ? " · {$application}" : '');
    }
    foreach (array_keys($directCalls) as $callerKey) {
        foreach (array_keys($queueCalls) as $queueKey) {
            if (str_starts_with($queueKey, $callerKey . '|')) {
                unset($queueCalls[$queueKey]);
            }
        }
        foreach (array_keys($ringGroupCalls) as $ringGroupKey) {
            if (str_starts_with($ringGroupKey, $callerKey . '|')) {
                unset($ringGroupCalls[$ringGroupKey]);
            }
        }
    }
    $channelRows = array_merge(
        $channelRows,
        array_values($queueCalls),
        array_values($ringGroupCalls),
        array_column(array_values($directCalls), 'detail'),
        array_column(array_values($outboundCalls), 'detail')
    );
    $channelRows = array_values(array_unique($channelRows));

    $endpointState = [];
    foreach (preg_split('/\r?\n/', $endpoints) as $line) {
        if (preg_match('/Endpoint:\s+(\S+)\s+(\S.+?)\s+(\d+)\s+of/', $line, $m)) {
            $name = explode('/', $m[1], 2)[0];
            $endpointState[$name] = trim($m[2]);
        }
    }

    return [
        'host' => PBX_HOST,
        'version' => '22.11.0',
        'uptime' => trim($uptime),
        'registered' => $registered,
        'endpoint_state' => $endpointState,
        'active_calls' => $activeCalls,
        'channels' => $channelRows,
        'registrations' => trim($regs),
        'ok_ssh' => $blob['ok'],
        'dept' => $deptId,
    ];
}

function pbx_ami_core_channels(): array
{
    $errorNumber = 0;
    $errorMessage = '';
    $socket = @fsockopen(PBX_HOST, AMI_PORT, $errorNumber, $errorMessage, 1.5);
    if (!is_resource($socket)) {
        return [];
    }
    stream_set_timeout($socket, 2);
    $actionId = 'astera-blf-' . bin2hex(random_bytes(5));
    fwrite($socket, "Action: Login\r\nUsername: " . AMI_USER
        . "\r\nSecret: " . AMI_SECRET . "\r\nEvents: off\r\n\r\n");

    $readMessage = static function ($stream): ?array {
        $lines = [];
        while (!feof($stream)) {
            $line = fgets($stream);
            if ($line === false) {
                return null;
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                break;
            }
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $lines[strtolower(trim($key))] = trim($value);
            }
        }
        return $lines ?: null;
    };

    $login = $readMessage($socket);
    if (strtolower((string) ($login['response'] ?? '')) !== 'success') {
        fclose($socket);
        return [];
    }
    fwrite($socket, "Action: CoreShowChannels\r\nActionID: {$actionId}\r\n\r\n");
    $events = [];
    while (!feof($socket)) {
        $message = $readMessage($socket);
        if ($message === null) {
            break;
        }
        $event = strtolower((string) ($message['event'] ?? ''));
        if ($event === 'coreshowchannel') {
            $events[] = $message;
        }
        if ($event === 'coreshowchannelscomplete') {
            break;
        }
    }
    fwrite($socket, "Action: Logoff\r\n\r\n");
    fclose($socket);
    return $events;
}

function pbx_blf_status(string $deptId): array
{
    $extensions = array_values(array_filter(
        store_read('extensions'),
        static fn($row) => (string) ($row['dept'] ?? '') === $deptId
    ));
    $bySip = [];
    foreach ($extensions as $extension) {
        $sip = sip_user($extension);
        if ($sip !== '') {
            $bySip[$sip] = $extension;
        }
    }
    $trunkNumbers = [];
    foreach (store_read('trunks') as $trunk) {
        if ((string) ($trunk['dept'] ?? '') !== $deptId) {
            continue;
        }
        foreach (['from_user', 'username', 'id'] as $field) {
            $number = preg_replace('/\D+/', '', (string) ($trunk[$field] ?? '')) ?? '';
            if ($number !== '') {
                $trunkNumbers[$number] = true;
            }
        }
    }

    $result = pbx_ssh(implode("\n", [
        "echo '===ENDPOINTS==='",
        "asterisk -rx 'pjsip show endpoints'",
        "echo '===CHANNELS==='",
        "asterisk -rx 'core show channels concise'",
    ]));
    $text = (string) ($result['output'] ?? '');
    $endpointText = section($text, 'ENDPOINTS', 'CHANNELS');
    $channelText = section($text, 'CHANNELS', null);
    $registered = [];
    if (preg_match_all('/Contact:\s+([^\/\s<]+)\//', $endpointText, $matches)) {
        $registered = array_fill_keys(array_unique($matches[1]), true);
    }

    $states = [];
    $sipByExtension = [];
    foreach ($bySip as $sip => $extension) {
        $extensionNumber = (string) ($extension['exten'] ?? '');
        if ($extensionNumber !== '') {
            $sipByExtension[$extensionNumber] = $sip;
        }
        $states[$sip] = [
            'extension' => $extensionNumber,
            'name' => (string) ($extension['name'] ?? ''),
            'state' => isset($registered[$sip]) ? 'available' : 'offline',
            'label' => isset($registered[$sip]) ? 'Müsait' : 'Çevrimdışı',
            'peer' => '',
            'call_id' => '',
            'call_age' => 0,
            'direction' => '',
        ];
    }

    $channels = [];
    foreach (preg_split('/\r?\n/', $channelText) as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, '!')) {
            continue;
        }
        $parts = explode('!', $line);
        $channel = (string) ($parts[0] ?? '');
        if ($channel !== '') {
            $channels[$channel] = $parts;
        }
    }
    $amiChannels = [];
    foreach (pbx_ami_core_channels() as $amiChannel) {
        $name = (string) ($amiChannel['channel'] ?? '');
        if ($name !== '') {
            $amiChannels[$name] = $amiChannel;
        }
    }

    $channelSip = static function (string $channel) use ($bySip): string {
        if (!str_starts_with($channel, 'PJSIP/')) {
            return '';
        }
        $endpoint = preg_replace('/-[0-9a-f]+$/i', '', substr($channel, 6)) ?? '';
        return isset($bySip[$endpoint]) ? $endpoint : '';
    };
    $digits = static function (mixed $value): string {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    };

    foreach ($channels as $channel => $parts) {
        $sip = $channelSip($channel);
        if ($sip === '') {
            continue;
        }
        $ownExtension = (string) ($bySip[$sip]['exten'] ?? '');
        $state = strtolower((string) ($parts[4] ?? ''));
        $application = (string) ($parts[5] ?? '');
        $data = (string) ($parts[6] ?? '');
        $bridgedChannel = (string) ($parts[12] ?? '');
        $amiChannel = $amiChannels[$channel] ?? [];
        $amiState = strtolower((string) ($amiChannel['channelstatedesc'] ?? ''));
        $amiBridge = (string) ($amiChannel['bridgeid'] ?? '');
        $callId = (string) (
            ($amiChannel['linkedid'] ?? '')
            ?: ($parts[14] ?? '')
            ?: ($amiChannel['uniqueid'] ?? '')
            ?: ($parts[13] ?? '')
        );
        $durationText = (string) ($amiChannel['duration'] ?? '');
        $callAge = max(0, (int) ($parts[11] ?? 0));
        if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', $durationText, $durationMatch)) {
            $callAge = ((int) $durationMatch[1] * 3600)
                + ((int) $durationMatch[2] * 60)
                + (int) $durationMatch[3];
        }
        $amiExtension = $digits($amiChannel['exten'] ?? '');
        $incomingCaller = $digits($amiChannel['calleridnum'] ?? '');
        if (
            strlen($incomingCaller) < 7
            || isset($trunkNumbers[$incomingCaller])
            || ($application === 'Dial' && $amiExtension !== $ownExtension)
        ) {
            $incomingCaller = '';
        }
        if ($incomingCaller === '' && $callId !== '' && $application !== 'Dial') {
            foreach ($amiChannels as $linkedChannel) {
                if ((string) ($linkedChannel['linkedid'] ?? '') !== $callId) {
                    continue;
                }
                $candidate = $digits($linkedChannel['calleridnum'] ?? '');
                if (strlen($candidate) >= 7 && !isset($trunkNumbers[$candidate])) {
                    $incomingCaller = $candidate;
                    break;
                }
            }
        }
        $peer = '';

        $peerSip = $channelSip($bridgedChannel);
        if ($peerSip !== '') {
            $peerExtension = $bySip[$peerSip];
            $peer = (string) ($peerExtension['exten'] ?? '');
        } elseif ($bridgedChannel !== '' && isset($channels[$bridgedChannel])) {
            $peerParts = $channels[$bridgedChannel];
            foreach ([$peerParts[2] ?? '', $peerParts[7] ?? ''] as $candidate) {
                $candidate = $digits($candidate);
                if ($candidate !== '' && $candidate !== $ownExtension) {
                    $peer = $candidate;
                    break;
                }
            }
        }
        if ($peer === '') {
            $linkedId = (string) ($parts[14] ?? '');
            if ($linkedId !== '') {
                foreach ($channels as $otherChannel => $otherParts) {
                    if (
                        $otherChannel === $channel
                        || (string) ($otherParts[14] ?? '') !== $linkedId
                    ) {
                        continue;
                    }
                    $linkedSip = $channelSip($otherChannel);
                    if ($linkedSip !== '' && $linkedSip !== $sip) {
                        $linkedExtension = $bySip[$linkedSip];
                        $peer = (string) ($linkedExtension['exten'] ?? '');
                        break;
                    }
                    foreach ([$otherParts[2] ?? '', $otherParts[7] ?? ''] as $candidate) {
                        $candidate = $digits($candidate);
                        if ($candidate !== '' && $candidate !== $ownExtension) {
                            $peer = $candidate;
                            break 2;
                        }
                    }
                }
            }
        }
        if ($peer === '' && preg_match('/PJSIP\/([^\/@,!]+)/', $data, $match)) {
            $targetSip = (string) $match[1];
            $candidate = isset($bySip[$targetSip])
                ? (string) ($bySip[$targetSip]['exten'] ?? '')
                : $digits($targetSip);
            if ($candidate !== '' && $candidate !== $ownExtension) {
                $peer = $candidate;
            }
        }
        if ($peer === '') {
            foreach ([
                $amiChannel['connectedlinenum'] ?? '',
                $amiChannel['exten'] ?? '',
                $amiChannel['calleridnum'] ?? '',
            ] as $candidate) {
                $candidate = $digits($candidate);
                if ($candidate !== '' && $candidate !== $ownExtension) {
                    $peer = $candidate;
                    break;
                }
            }
        }
        if ($peer === '') {
            $amiData = (string) ($amiChannel['applicationdata'] ?? '');
            if (preg_match('/PJSIP\/([^\/@,!]+)/', $amiData, $match)) {
                $targetSip = (string) $match[1];
                $candidate = isset($bySip[$targetSip])
                    ? (string) ($bySip[$targetSip]['exten'] ?? '')
                    : $digits($targetSip);
                if ($candidate !== '' && $candidate !== $ownExtension) {
                    $peer = $candidate;
                }
            }
        }
        if ($peer === '') {
            foreach ([$parts[2] ?? '', $parts[7] ?? ''] as $candidate) {
                $candidate = $digits($candidate);
                if ($candidate !== '' && $candidate !== $ownExtension) {
                    $peer = $candidate;
                    break;
                }
            }
        }
        if ($peer !== '' && isset($trunkNumbers[$peer])) {
            $peer = '';
        }
        if ($peer === '') {
            foreach ([$parts[2] ?? '', $parts[7] ?? ''] as $candidate) {
                $candidate = $digits($candidate);
                if (
                    $candidate !== ''
                    && $candidate !== $ownExtension
                    && !isset($trunkNumbers[$candidate])
                ) {
                    $peer = $candidate;
                    break;
                }
            }
        }
        if ($incomingCaller !== '') {
            // Aktarılan dış çağrılarda köprü/trunk adı yerine çağrının ilk
            // harici CallerID numarası her dahili için korunur.
            $peer = $incomingCaller;
        }
        $direction = $incomingCaller !== ''
            ? 'incoming'
            : ($application === 'Dial' ? 'outgoing' : 'incoming');

        if ($bridgedChannel !== '' || $amiBridge !== '') {
            $states[$sip]['state'] = 'talking';
            $states[$sip]['peer'] = $peer;
            $states[$sip]['label'] = 'Görüşmede';
            $states[$sip]['call_id'] = $callId;
            $states[$sip]['call_age'] = $callAge;
            $states[$sip]['direction'] = $direction;
        } elseif (str_contains($state, 'ring') || str_contains($amiState, 'ring') || $application === 'Dial') {
            $states[$sip]['state'] = 'ringing';
            $states[$sip]['peer'] = $peer;
            $states[$sip]['label'] = $application === 'Dial' ? 'Aranıyor' : 'Çalıyor';
            $states[$sip]['call_id'] = $callId;
            $states[$sip]['call_age'] = $callAge;
            $states[$sip]['direction'] = $direction;
        }
    }

    // Dial sırasında Asterisk bazı cihazlarda aranan PJSIP kanalını kısa süre
    // kanal listesine eklemeyebilir. Arayan kanalın hedefinden aranan tarafın
    // "Çalıyor" durumunu tamamla; aksi halde yalnızca arayan taraf görünür.
    foreach ($states as $callerSip => $callerState) {
        if (
            ($callerState['state'] ?? '') !== 'ringing'
            || ($callerState['direction'] ?? '') !== 'outgoing'
        ) {
            continue;
        }
        $targetSip = $sipByExtension[(string) ($callerState['peer'] ?? '')] ?? '';
        if (
            $targetSip === ''
            || $targetSip === $callerSip
            || ($states[$targetSip]['state'] ?? '') === 'talking'
        ) {
            continue;
        }
        $states[$targetSip]['state'] = 'ringing';
        $states[$targetSip]['label'] = 'Çalıyor';
        $states[$targetSip]['peer'] = (string) ($callerState['extension'] ?? '');
        $states[$targetSip]['call_id'] = (string) ($callerState['call_id'] ?? '');
        $states[$targetSip]['call_age'] = max(0, (int) ($callerState['call_age'] ?? 0));
        $states[$targetSip]['direction'] = 'incoming';
    }

    return [
        'ok' => !empty($result['ok']),
        'extensions' => $states,
        'updated_at' => date(DATE_ATOM),
    ];
}

function section(string $text, string $from, ?string $to): string
{
    $start = strpos($text, '===' . $from . '===');
    if ($start === false) {
        return '';
    }
    $start = strpos($text, "\n", $start);
    if ($start === false) {
        return '';
    }
    $start++;
    if ($to === null) {
        return substr($text, $start);
    }
    $end = strpos($text, '===' . $to . '===', $start);
    if ($end === false) {
        return substr($text, $start);
    }
    return substr($text, $start, $end - $start);
}

function pbx_cdr_has_native_tenant(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    $ok = false;
    $rows = pbx_odbc_rows(
        "SELECT EXISTS (SELECT 1 FROM information_schema.columns "
        . "WHERE table_schema = 'public' AND table_name = 'cdr' AND column_name = 'dept_id') "
        . "AS available",
        $ok
    );
    $value = strtolower((string) ($rows[0]['available'] ?? ''));
    $available = $ok && !in_array($value, ['', '0', 'f', 'false'], true);
    return $available;
}

function pbx_cdr(int $limit = 400, ?string $deptId = null, array $filters = []): array
{
    $limit = max(1, min(5000, $limit));
    $clauses = [];
    if ($deptId !== null) {
        if (pbx_cdr_has_native_tenant()) {
            $clauses[] = 'dept_id = ' . pbx_sql_literal($deptId);
        } else {
            $code = dept_code_of($deptId);
            $quoted = pbx_sql_literal($code);
            $clauses[] = "(accountcode = {$quoted} OR userfield = {$quoted} OR userfield LIKE "
                . pbx_sql_literal($code . '|%') . " OR dcontext LIKE "
                . pbx_sql_literal('%-' . $code) . ')';
        }
    }
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $clauses[] = 'calldate >= ' . pbx_sql_literal($dateFrom . ' 00:00:00') . '::timestamp';
    }
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $clauses[] = 'calldate < (' . pbx_sql_literal($dateTo . ' 00:00:00') . '::timestamp + interval \'1 day\')';
    }
    foreach (['src', 'dst', 'uniqueid'] as $field) {
        $value = trim((string) ($filters[$field] ?? ''));
        if ($value !== '') {
            $clauses[] = "{$field} ILIKE " . pbx_sql_literal('%' . $value . '%');
        }
    }
    $disposition = strtoupper(trim((string) ($filters['disposition'] ?? '')));
    if (in_array($disposition, ['ANSWERED', 'NO ANSWER', 'BUSY', 'FAILED', 'CONGESTION'], true)) {
        $clauses[] = 'disposition = ' . pbx_sql_literal($disposition);
    }
    $internal = preg_replace('/\D+/', '', (string) ($filters['internal'] ?? '')) ?? '';
    if ($internal !== '') {
        $sipUsers = [];
        foreach (store_read('extensions') as $extension) {
            if ((string) ($extension['exten'] ?? '') === $internal
                && ($deptId === null || (string) ($extension['dept'] ?? '') === $deptId)) {
                $sipUsers[] = sip_user($extension);
            }
        }
        $sipUsers[] = $internal;
        $channelClauses = [];
        foreach (array_unique($sipUsers) as $sipUser) {
            $channelClauses[] = 'channel ILIKE ' . pbx_sql_literal('%/' . $sipUser . '-%');
        }
        $clauses[] = '(' . implode(' OR ', $channelClauses) . ')';
    }
    $where = $clauses ? ' WHERE ' . implode(' AND ', $clauses) : '';
    $dbQueryOk = false;
    $dbRows = pbx_odbc_rows(
        "SELECT clid, src, dst, dcontext, channel, dstchannel, lastapp, lastdata, "
        . "to_char(COALESCE(start, calldate), 'YYYY-MM-DD HH24:MI:SS') AS start, "
        . "to_char(answer, 'YYYY-MM-DD HH24:MI:SS') AS answer, "
        . "to_char(COALESCE(call_end, calldate + (duration * interval '1 second')), "
        . "'YYYY-MM-DD HH24:MI:SS') AS end, "
        . "duration, billsec, disposition, amaflags, accountcode, peeraccount, uniqueid, "
        . "userfield, sequence, linkedid, recordingfile "
        . "FROM cdr{$where} ORDER BY calldate DESC LIMIT {$limit}",
        $dbQueryOk
    );
    if ($dbQueryOk) {
        return $dbRows;
    }

    $res = pbx_ssh('tail -n ' . $limit . ' /var/log/asterisk/cdr-csv/Master.csv 2>/dev/null');
    $code = $deptId ? dept_code_of($deptId) : null;
    $rows = [];
    foreach (preg_split('/\r?\n/', $res['output']) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $cols = str_getcsv($line);
        if (count($cols) < 14) {
            continue;
        }
        $account = $cols[0] ?? '';
        $userfield = $cols[17] ?? '';
        if ($code && $account !== $code && $userfield !== $code && !str_starts_with($userfield, $code . '|')) {
            $ctx = $cols[3] ?? '';
            if (!str_contains($ctx, $code)) {
                continue;
            }
        }
        $rows[] = [
            'accountcode' => $account,
            'src' => $cols[1] ?? '',
            'dst' => $cols[2] ?? '',
            'dcontext' => $cols[3] ?? '',
            'clid' => $cols[4] ?? '',
            'channel' => $cols[5] ?? '',
            'dstchannel' => $cols[6] ?? '',
            'lastapp' => $cols[7] ?? '',
            'lastdata' => $cols[8] ?? '',
            'start' => $cols[9] ?? '',
            'answer' => $cols[10] ?? '',
            'end' => $cols[11] ?? '',
            'duration' => $cols[12] ?? '',
            'billsec' => $cols[13] ?? '',
            'disposition' => $cols[14] ?? '',
            'amaflags' => $cols[15] ?? '',
            'peeraccount' => $cols[18] ?? '',
            'uniqueid' => $cols[16] ?? '',
            'userfield' => $userfield,
            'sequence' => $cols[20] ?? '',
            'linkedid' => $cols[19] ?? ($cols[16] ?? ''),
            'recordingfile' => '',
        ];
    }
    return array_reverse($rows);
}

function cdr_summary(array $rows): array
{
    $sum = [
        'total' => count($rows),
        'answered' => 0,
        'outbound' => 0,
        'internal' => 0,
        'billsec' => 0,
    ];
    foreach ($rows as $row) {
        if (($row['disposition'] ?? '') === 'ANSWERED') {
            $sum['answered']++;
        }
        $sum['billsec'] += (int) ($row['billsec'] ?? 0);
        $dst = (string) ($row['dst'] ?? '');
        if (strlen($dst) > 6 || str_starts_with($dst, '9')) {
            $sum['outbound']++;
        } else {
            $sum['internal']++;
        }
    }
    return $sum;
}

function pbx_daily_call_summary(?string $deptId = null): array
{
    $clauses = [
        'calldate >= CURRENT_DATE',
        "calldate < CURRENT_DATE + interval '1 day'",
    ];
    if ($deptId !== null) {
        if (pbx_cdr_has_native_tenant()) {
            $clauses[] = 'dept_id = ' . pbx_sql_literal($deptId);
        } else {
            $code = dept_code_of($deptId);
            $quoted = pbx_sql_literal($code);
            $clauses[] = "(accountcode = {$quoted} OR userfield = {$quoted} OR userfield LIKE "
                . pbx_sql_literal($code . '|%') . " OR dcontext LIKE "
                . pbx_sql_literal('%-' . $code) . ')';
        }
    }
    $where = implode(' AND ', $clauses);
    $queryOk = false;
    $callId = "COALESCE(NULLIF(linkedid, ''), uniqueid)";
    $incoming = "(recordingfile ILIKE '%-yon_gelen-%' OR "
        . "(dcontext LIKE 'int-%' AND length(regexp_replace(src, '[^0-9]', '', 'g')) > 6))";
    $outgoing = "(recordingfile ILIKE '%-yon_giden-%' OR "
        . "(dcontext LIKE 'from-%' AND length(regexp_replace(dst, '[^0-9]', '', 'g')) > 6))";
    $rows = pbx_odbc_rows(
        "SELECT "
        . "COUNT(DISTINCT {$callId}) FILTER (WHERE {$incoming}) AS incoming, "
        . "COUNT(DISTINCT {$callId}) FILTER (WHERE {$incoming} AND disposition = 'ANSWERED') AS incoming_answered, "
        . "COUNT(DISTINCT {$callId}) FILTER (WHERE {$outgoing}) AS outgoing, "
        . "COUNT(DISTINCT {$callId}) FILTER (WHERE {$outgoing} AND disposition = 'ANSWERED') AS outgoing_answered "
        . "FROM cdr WHERE {$where}",
        $queryOk
    );
    if ($queryOk && isset($rows[0])) {
        return array_map('intval', $rows[0]);
    }

    $today = date('Y-m-d');
    $calls = [];
    foreach (pbx_cdr(5000, $deptId, ['date_from' => $today, 'date_to' => $today]) as $row) {
        $callId = (string) (($row['linkedid'] ?? '') ?: ($row['uniqueid'] ?? ''));
        if ($callId === '') {
            continue;
        }
        $recording = (string) ($row['recordingfile'] ?? '');
        $dcontext = (string) ($row['dcontext'] ?? '');
        $src = preg_replace('/\D+/', '', (string) ($row['src'] ?? '')) ?? '';
        $dst = preg_replace('/\D+/', '', (string) ($row['dst'] ?? '')) ?? '';
        $calls[$callId] ??= ['incoming' => false, 'outgoing' => false, 'answered' => false];
        $calls[$callId]['incoming'] = $calls[$callId]['incoming']
            || str_contains($recording, '-yon_gelen-')
            || (str_starts_with($dcontext, 'int-') && strlen($src) > 6);
        $calls[$callId]['outgoing'] = $calls[$callId]['outgoing']
            || str_contains($recording, '-yon_giden-')
            || (str_starts_with($dcontext, 'from-') && strlen($dst) > 6);
        $calls[$callId]['answered'] = $calls[$callId]['answered']
            || (string) ($row['disposition'] ?? '') === 'ANSWERED';
    }
    $summary = ['incoming' => 0, 'incoming_answered' => 0, 'outgoing' => 0, 'outgoing_answered' => 0];
    foreach ($calls as $call) {
        if ($call['outgoing']) {
            $summary['outgoing']++;
            $summary['outgoing_answered'] += $call['answered'] ? 1 : 0;
        } elseif ($call['incoming']) {
            $summary['incoming']++;
            $summary['incoming_answered'] += $call['answered'] ? 1 : 0;
        }
    }
    return $summary;
}

function ensure_reporting_database(): array
{
    $schema = pbx_pg_admin(<<<SQL
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS clid VARCHAR(255);
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS src VARCHAR(80);
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS dst VARCHAR(80);
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS dcontext VARCHAR(80);
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS channel VARCHAR(255);
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS dstchannel VARCHAR(255);
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS lastapp VARCHAR(80);
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS lastdata VARCHAR(255);
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS start TIMESTAMP WITHOUT TIME ZONE;
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS answer TIMESTAMP WITHOUT TIME ZONE;
DO \$\$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'cdr' AND column_name = 'end'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'cdr' AND column_name = 'call_end'
    ) THEN
        ALTER TABLE cdr RENAME COLUMN "end" TO call_end;
    END IF;
END;
\$\$;
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS call_end TIMESTAMP WITHOUT TIME ZONE;
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS duration INTEGER;
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS billsec INTEGER;
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS disposition VARCHAR(45);
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS amaflags INTEGER;
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS accountcode VARCHAR(80);
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS peeraccount VARCHAR(80);
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS uniqueid VARCHAR(80);
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS userfield VARCHAR(255);
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS sequence INTEGER;
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS linkedid VARCHAR(80);

CREATE OR REPLACE FUNCTION astera_fill_cdr_times()
RETURNS trigger AS \$\$
BEGIN
    NEW.start := COALESCE(NEW.start, NEW.calldate);
    NEW.cnum := COALESCE(NULLIF(NEW.cnum, ''), NEW.src);
    NEW.cnam := COALESCE(
        NULLIF(NEW.cnam, ''),
        NULLIF(BTRIM(SPLIT_PART(COALESCE(NEW.clid, ''), '<', 1), ' "'), '')
    );
    IF COALESCE(NEW.did, '') = '' AND NEW.dcontext = 'ext-did' THEN
        NEW.did := NEW.dst;
    ELSIF COALESCE(NEW.did, '') = ''
          AND NEW.dcontext LIKE 'int-%'
          AND LENGTH(REGEXP_REPLACE(COALESCE(NEW.src, ''), '[^0-9]', '', 'g')) > 6 THEN
        NEW.did := SPLIT_PART(SPLIT_PART(COALESCE(NEW.channel, ''), '/', 2), '-', 1);
    END IF;
    NEW.call_end := COALESCE(
        NEW.call_end,
        NEW.calldate + make_interval(secs => COALESCE(NEW.duration, 0))
    );
    IF NEW.answer IS NULL
       AND (COALESCE(NEW.billsec, 0) > 0 OR UPPER(COALESCE(NEW.disposition, '')) = 'ANSWERED') THEN
        NEW.answer := NEW.call_end - make_interval(secs => COALESCE(NEW.billsec, 0));
    END IF;
    RETURN NEW;
END;
\$\$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS astera_fill_cdr_times_trigger ON cdr;
CREATE TRIGGER astera_fill_cdr_times_trigger
BEFORE INSERT OR UPDATE ON cdr
FOR EACH ROW EXECUTE FUNCTION astera_fill_cdr_times();

UPDATE cdr
SET start = COALESCE(start, calldate),
    call_end = COALESCE(call_end, calldate + make_interval(secs => COALESCE(duration, 0))),
    answer = COALESCE(
        answer,
        CASE
            WHEN COALESCE(billsec, 0) > 0 OR UPPER(COALESCE(disposition, '')) = 'ANSWERED'
            THEN calldate + make_interval(secs => GREATEST(COALESCE(duration, 0) - COALESCE(billsec, 0), 0))
            ELSE NULL
        END
    )
WHERE start IS NULL OR call_end IS NULL
   OR (answer IS NULL AND (COALESCE(billsec, 0) > 0 OR UPPER(COALESCE(disposition, '')) = 'ANSWERED'));

UPDATE cdr
SET cnum = COALESCE(NULLIF(cnum, ''), src),
    cnam = COALESCE(
        NULLIF(cnam, ''),
        NULLIF(BTRIM(SPLIT_PART(COALESCE(clid, ''), '<', 1), ' "'), '')
    ),
    did = COALESCE(
        NULLIF(did, ''),
        CASE
            WHEN dcontext = 'ext-did' THEN dst
            WHEN dcontext LIKE 'int-%'
                 AND LENGTH(REGEXP_REPLACE(COALESCE(src, ''), '[^0-9]', '', 'g')) > 6
            THEN SPLIT_PART(SPLIT_PART(COALESCE(channel, ''), '/', 2), '-', 1)
            ELSE NULL
        END
    )
WHERE COALESCE(cnum, '') = '' OR COALESCE(cnam, '') = '' OR COALESCE(did, '') = '';

CREATE TABLE IF NOT EXISTS queue_log (
    id BIGSERIAL PRIMARY KEY,
    time TIMESTAMP WITHOUT TIME ZONE DEFAULT now() NOT NULL,
    callid VARCHAR(80) NOT NULL DEFAULT '',
    queuename VARCHAR(80) NOT NULL DEFAULT '',
    agent VARCHAR(80) NOT NULL DEFAULT '',
    event VARCHAR(40) NOT NULL DEFAULT '',
    data1 VARCHAR(255) NOT NULL DEFAULT '',
    data2 VARCHAR(255) NOT NULL DEFAULT '',
    data3 VARCHAR(255) NOT NULL DEFAULT '',
    data4 VARCHAR(255) NOT NULL DEFAULT '',
    data5 VARCHAR(255) NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS queue_log_time_idx ON queue_log (time DESC);
CREATE INDEX IF NOT EXISTS queue_log_callid_idx ON queue_log (callid);
CREATE INDEX IF NOT EXISTS queue_log_queue_idx ON queue_log (queuename);
GRANT SELECT, INSERT, UPDATE, DELETE ON queue_log TO asterisk;
GRANT USAGE, SELECT ON SEQUENCE queue_log_id_seq TO asterisk;
SQL);
    if (!$schema['ok']) {
        return $schema;
    }

    $config = pbx_ssh(implode("\n", [
        "grep -q '^alias end => call_end$' /etc/asterisk/cdr_adaptive_odbc.conf || sed -i '/^table=cdr/a alias end => call_end' /etc/asterisk/cdr_adaptive_odbc.conf",
        "grep -q '^queue_log => odbc,asterisk,queue_log' /etc/asterisk/extconfig.conf || sed -i '/^\\[settings\\]/a queue_log => odbc,asterisk,queue_log' /etc/asterisk/extconfig.conf",
        "grep -q '^queue_adaptive_realtime *= *yes' /etc/asterisk/logger.conf || sed -i '/^\\[general\\]/a queue_adaptive_realtime = yes' /etc/asterisk/logger.conf",
        "sed -i 's/^;queue_log_to_file *= *yes/queue_log_to_file = yes/' /etc/asterisk/logger.conf",
        "asterisk -rx 'module reload cdr_adaptive_odbc.so'",
        "asterisk -rx 'logger reload'",
    ]));
    if (!$config['ok']) {
        return $config;
    }
    return ['ok' => true, 'code' => 0, 'output' => 'CDR ve queue_log PostgreSQL raporlama etkin'];
}

function import_cdr_csv_file(): array
{
    $res = pbx_ssh('cat /var/log/asterisk/cdr-csv/Master.csv 2>/dev/null');
    if (!$res['ok'] || trim((string) ($res['output'] ?? '')) === '') {
        return ['ok' => true, 'code' => 0, 'output' => 'Aktarılacak CDR kaydı yok'];
    }
    $statements = [];
    foreach (preg_split('/\r?\n/', (string) $res['output']) as $line) {
        $cols = str_getcsv(trim($line));
        if (count($cols) < 18 || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $cols[9])) {
            continue;
        }
        $timestamp = static function (string $value): string {
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
                return 'NULL';
            }
            return '(' . pbx_sql_literal($value) . '::timestamp + interval \'3 hours\')';
        };
        $clid = (string) ($cols[4] ?? '');
        $cnam = '';
        if (preg_match('/^\s*"?([^"<]*)"?\s*</', $clid, $nameMatch)) {
            $cnam = trim((string) $nameMatch[1]);
        }
        $src = (string) ($cols[1] ?? '');
        $dst = (string) ($cols[2] ?? '');
        $dcontext = (string) ($cols[3] ?? '');
        $channel = (string) ($cols[5] ?? '');
        $dstchannel = (string) ($cols[6] ?? '');
        $did = '';
        if ($dcontext === 'ext-did') {
            $did = $dst;
        } elseif (str_starts_with($dcontext, 'int-') && strlen(preg_replace('/\D+/', '', $src) ?? '') > 6
            && preg_match('#^PJSIP/([^-]+)-#', $channel, $endpointMatch)) {
            $did = (string) $endpointMatch[1];
        }
        $uniqueid = (string) ($cols[16] ?? '');
        $values = [
            pbx_sql_literal($clid),
            pbx_sql_literal($src),
            pbx_sql_literal($dst),
            pbx_sql_literal($dcontext),
            pbx_sql_literal($channel),
            pbx_sql_literal($dstchannel),
            pbx_sql_literal((string) ($cols[7] ?? '')),
            pbx_sql_literal((string) ($cols[8] ?? '')),
            max(0, (int) ($cols[12] ?? 0)),
            max(0, (int) ($cols[13] ?? 0)),
            pbx_sql_literal((string) ($cols[14] ?? '')),
            3,
            pbx_sql_literal((string) ($cols[0] ?? '')),
            pbx_sql_literal($uniqueid),
            pbx_sql_literal((string) ($cols[17] ?? '')),
            pbx_sql_literal($did),
            pbx_sql_literal($src),
            pbx_sql_literal($cnam),
            pbx_sql_literal($uniqueid),
            $timestamp((string) $cols[9]),
            $timestamp((string) $cols[9]),
            $timestamp((string) ($cols[10] ?? '')),
            $timestamp((string) ($cols[11] ?? '')),
        ];
        $statements[] = 'INSERT INTO cdr '
            . '(clid,src,dst,dcontext,channel,dstchannel,lastapp,lastdata,duration,billsec,'
            . 'disposition,amaflags,accountcode,uniqueid,userfield,did,cnum,cnam,linkedid,'
            . 'calldate,start,answer,call_end) SELECT ' . implode(',', $values)
            . ' WHERE NOT EXISTS (SELECT 1 FROM cdr WHERE uniqueid = ' . pbx_sql_literal($uniqueid)
            . ' AND channel = ' . pbx_sql_literal($channel)
            . ' AND COALESCE(dstchannel, \'\') = ' . pbx_sql_literal($dstchannel) . ')';
    }
    if (!$statements) {
        return ['ok' => true, 'code' => 0, 'output' => 'Aktarılacak CDR kaydı yok'];
    }
    return pbx_odbc_sql(implode(";\n", $statements));
}

function pbx_backfill_outbound_identity(): array
{
    $statements = [];
    foreach (store_read('extensions') as $extension) {
        $number = trim((string) ($extension['exten'] ?? ''));
        if ($number === '') {
            continue;
        }
        $endpoint = sip_user($extension);
        $name = trim((string) ($extension['name'] ?? ''));
        $statements[] = 'UPDATE cdr SET outbound_cnum = ' . pbx_sql_literal($number)
            . ', outbound_cnam = ' . pbx_sql_literal($name)
            . ' WHERE dcontext LIKE \'from-%\' AND channel LIKE '
            . pbx_sql_literal('PJSIP/' . $endpoint . '-%');
    }
    if (!$statements) {
        return ['ok' => true, 'code' => 0, 'output' => 'Güncellenecek abone CDR kaydı yok'];
    }
    return pbx_odbc_sql(implode(";\n", $statements));
}

function import_queue_log_file(): array
{
    $res = pbx_ssh('cat /var/log/asterisk/queue_log 2>/dev/null');
    if (!$res['ok'] || trim($res['output']) === '') {
        return ['ok' => true, 'code' => 0, 'output' => 'Aktarılacak eski queue_log yok'];
    }
    $statements = [];
    foreach (preg_split('/\r?\n/', $res['output']) as $line) {
        $parts = explode('|', trim($line));
        if (count($parts) < 5 || !ctype_digit((string) $parts[0])) {
            continue;
        }
        $data = array_pad(array_slice($parts, 5, 5), 5, '');
        $values = [
            'to_timestamp(' . (int) $parts[0] . ')',
            pbx_sql_literal((string) ($parts[1] ?? '')),
            pbx_sql_literal((string) ($parts[2] ?? '')),
            pbx_sql_literal((string) ($parts[3] ?? '')),
            pbx_sql_literal((string) ($parts[4] ?? '')),
            ...array_map(static fn($value) => pbx_sql_literal((string) $value), $data),
        ];
        $checks = [
            'time = ' . $values[0],
            'callid = ' . $values[1],
            'queuename = ' . $values[2],
            'agent = ' . $values[3],
            'event = ' . $values[4],
            'data1 = ' . $values[5],
            'data2 = ' . $values[6],
            'data3 = ' . $values[7],
            'data4 = ' . $values[8],
            'data5 = ' . $values[9],
        ];
        $statements[] = 'INSERT INTO queue_log (time,callid,queuename,agent,event,data1,data2,data3,data4,data5) '
            . 'SELECT ' . implode(',', $values)
            . ' WHERE NOT EXISTS (SELECT 1 FROM queue_log WHERE ' . implode(' AND ', $checks) . ')';
    }
    if (!$statements) {
        return ['ok' => true, 'code' => 0, 'output' => 'Aktarılacak queue_log satırı yok'];
    }
    $local = tempnam(sys_get_temp_dir(), 'queue-log-');
    if ($local === false) {
        return ['ok' => false, 'code' => 1, 'output' => 'Queue log aktarım dosyası oluşturulamadı'];
    }
    file_put_contents($local, implode(";\n", $statements) . ";\n");
    $remote = '/tmp/astera-queue-log-import.sql';
    $upload = pbx_upload($local, $remote);
    @unlink($local);
    if (!$upload['ok']) {
        return $upload;
    }
    $insert = pbx_ssh("isql -b asterisk < {$remote}\nrm -f {$remote}");
    if (!$insert['ok']) {
        return $insert;
    }
    return ['ok' => true, 'code' => 0, 'output' => count($statements) . ' queue_log satırı kontrol edildi'];
}

function pbx_queue_log_rows(int $limit = 1000, ?string $deptId = null, array $filters = []): array
{
    $limit = max(1, min(5000, $limit));
    $clauses = [];
    if ($deptId !== null) {
        $queueIds = [];
        foreach (store_read('queues') as $queue) {
            if ((string) ($queue['dept'] ?? '') === $deptId) {
                $queueIds[] = pbx_sql_literal((string) ($queue['id'] ?? ''));
            }
        }
        if (!$queueIds) {
            return [];
        }
        $clauses[] = 'queuename IN (' . implode(',', $queueIds) . ')';
    }
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $clauses[] = 'time >= ' . pbx_sql_literal($dateFrom . ' 00:00:00') . '::timestamp';
    }
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $clauses[] = 'time < (' . pbx_sql_literal($dateTo . ' 00:00:00') . '::timestamp + interval \'1 day\')';
    }
    foreach (['callid', 'queuename', 'agent', 'event'] as $field) {
        $value = trim((string) ($filters[$field] ?? ''));
        if ($value !== '') {
            $clauses[] = "{$field} ILIKE " . pbx_sql_literal('%' . $value . '%');
        }
    }
    $data = trim((string) ($filters['data'] ?? ''));
    if ($data !== '') {
        $search = pbx_sql_literal('%' . $data . '%');
        $clauses[] = "(data1 ILIKE {$search} OR data2 ILIKE {$search} OR data3 ILIKE {$search} "
            . "OR data4 ILIKE {$search} OR data5 ILIKE {$search})";
    }
    $where = $clauses ? ' WHERE ' . implode(' AND ', $clauses) : '';
    return pbx_odbc_rows(
        "SELECT id, to_char(time, 'YYYY-MM-DD HH24:MI:SS') AS time, callid, queuename, "
        . "agent, event, data1, data2, data3, data4, data5 "
        . "FROM queue_log{$where} ORDER BY time DESC, id DESC LIMIT {$limit}"
    );
}

function pbx_queue_answers(int $limit = 2000): array
{
    $dbRows = pbx_odbc_rows(
        "SELECT callid, agent FROM queue_log WHERE event = 'CONNECT' ORDER BY time DESC LIMIT "
        . max(100, min(10000, $limit))
    );
    if ($dbRows) {
        $answers = [];
        foreach ($dbRows as $row) {
            $uniqueId = trim((string) ($row['callid'] ?? ''));
            $endpoint = preg_replace('#^PJSIP/#', '', trim((string) ($row['agent'] ?? ''))) ?? '';
            if ($uniqueId !== '' && $endpoint !== '') {
                $answers[$uniqueId] = $endpoint;
            }
        }
        return $answers;
    }

    $res = pbx_ssh('tail -n ' . max(100, $limit) . ' /var/log/asterisk/queue_log 2>/dev/null');
    $answers = [];
    foreach (preg_split('/\r?\n/', $res['output']) as $line) {
        $parts = explode('|', trim($line));
        if (count($parts) < 5 || ($parts[4] ?? '') !== 'CONNECT') {
            continue;
        }
        $uniqueId = trim((string) ($parts[1] ?? ''));
        $endpoint = preg_replace('#^PJSIP/#', '', trim((string) ($parts[3] ?? ''))) ?? '';
        if ($uniqueId !== '' && $endpoint !== '') {
            $answers[$uniqueId] = $endpoint;
        }
    }
    return $answers;
}

function pbx_migrate_recording_tree(): array
{
    return pbx_ssh(<<<'SH'
for dept in /var/spool/asterisk/monitor/*; do
    [ -d "$dept" ] || continue
    find "$dept" -maxdepth 1 -type f -print0 | while IFS= read -r -d '' file; do
        name="$(basename "$file")"
        if [[ "$name" =~ ^([0-9]{4})([0-9]{2})([0-9]{2}) ]]; then
            target="$dept/${BASH_REMATCH[1]}/${BASH_REMATCH[2]}/${BASH_REMATCH[3]}"
            mkdir -p "$target"
            mv -n "$file" "$target/$name"
        fi
    done
done
SH);
}

function pbx_recordings(?string $deptId, int $limit = 80, ?array $cdrRows = null): array
{
    $depts = $deptId ? [dept_by_id($deptId)] : departments();
    $queueAnswers = pbx_queue_answers();
    $cdrIndex = [];
    $cdrByUniqueId = [];
    $cdrByRecordingFile = [];
    foreach (($cdrRows ?? pbx_cdr(800, $deptId)) as $cdr) {
        $uniqueId = trim((string) ($cdr['uniqueid'] ?? ''));
        if ($uniqueId !== '') {
            $cdrByUniqueId[$uniqueId] = $cdr;
        }
        $recordingFile = trim((string) ($cdr['recordingfile'] ?? ''), '/');
        if ($recordingFile !== '') {
            $cdrByRecordingFile[$recordingFile] = $cdr;
        }
        $started = strtotime((string) ($cdr['start'] ?? ''));
        $src = preg_replace('/\D+/', '', (string) ($cdr['src'] ?? '')) ?? '';
        if ($started === false || $src === '') {
            continue;
        }
        foreach ([$started, $started + 10800] as $recordingTime) {
            $key = date('Ymd-His', $recordingTime) . '|' . $src;
            if (!isset($cdrIndex[$key]) || (int) ($cdr['duration'] ?? 0) > (int) ($cdrIndex[$key]['duration'] ?? 0)) {
                $cdrIndex[$key] = $cdr;
            }
        }
    }
    $files = [];
    foreach ($depts as $dept) {
        if (!$dept) {
            continue;
        }
        $code = dept_code_of((string) $dept['id']);
        $extensionNumbers = [];
        foreach (store_read('extensions') as $extension) {
            if ((string) ($extension['dept'] ?? '') === (string) $dept['id']) {
                $extensionNumbers[] = (string) ($extension['exten'] ?? '');
            }
        }
        $monitorRoot = '/var/spool/asterisk/monitor/' . $code;
        $res = pbx_ssh("find {$monitorRoot} -type f -printf '%T@|%s|%p\n' 2>/dev/null | sort -t'|' -k1,1nr | head -n {$limit}");
        foreach (preg_split('/\r?\n/', $res['output']) as $line) {
            $line = trim($line);
            if ($line === '' || substr_count($line, '|') < 2) {
                continue;
            }
            [, $size, $path] = explode('|', $line, 3);
            $size = max(0, (int) $size);
            $file = basename($path);
            $relativePath = ltrim(substr($path, strlen($monitorRoot)), '/');
            $stamp = $recordingUniqueId = $caller = $destination = '';
            $direction = $recordType = $parsedInternal = $parsedExternal = '';
            if (preg_match('/^(\d{8})-(\d{6})-yon_([A-Za-z]+)-tip_([A-Za-z]+)-dahili_([A-Za-z0-9_]*)-numara_([A-Za-z0-9_]*)-uid_(.+)\.(wav|gsm|ulaw)$/i', $file, $match)) {
                [, $datePart, $timePart, $direction, $recordType, $parsedInternal, $parsedExternal, $recordingUniqueId] = $match;
                $stamp = $datePart . '-' . $timePart;
                $caller = strtolower($direction) === 'giden' ? $parsedInternal : $parsedExternal;
                $destination = strtolower($direction) === 'giden' ? $parsedExternal : $parsedInternal;
            } elseif (preg_match('/^(\d{8}-\d{6})-uid_([A-Za-z0-9.]+)-([^-]+)-([^.]+)\.(wav|gsm|ulaw)$/i', $file, $match)) {
                [, $stamp, $recordingUniqueId, $caller, $destination] = $match;
            } elseif (preg_match('/^(\d{8}-\d{6})-([^-]+)-([^.]+)\.(wav|gsm|ulaw)$/i', $file, $match)) {
                [, $stamp, $caller, $destination] = $match;
            }
            $callerDigits = preg_replace('/\D+/', '', $caller) ?? '';
            $cdr = $cdrByRecordingFile[$relativePath] ?? (
                $recordingUniqueId !== ''
                    ? ($cdrByUniqueId[$recordingUniqueId] ?? null)
                    : ($cdrIndex[$stamp . '|' . $callerDigits] ?? null)
            );
            if (($destination === '' || strtolower($destination) === 's') && $cdr) {
                $destination = (string) ($cdr['dst'] ?? $destination);
            }
            if (strtolower($destination) === 's') {
                $destination = '';
            }
            $duration = (int) ($cdr['duration'] ?? 0);
            if ($duration <= 0 && $size > 64) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $bytesPerSecond = match ($extension) {
                    'ulaw' => 8000,
                    'gsm' => 1650,
                    default => 16000,
                };
                $duration = max(1, (int) round(max(0, $size - ($extension === 'wav' ? 44 : 0)) / $bytesPerSecond));
            }
            if ($parsedInternal !== '' || $parsedExternal !== '') {
                $internalNumber = $parsedInternal;
                $externalNumber = $parsedExternal;
            } elseif (in_array($callerDigits, $extensionNumbers, true)) {
                $internalNumber = $callerDigits;
                $externalNumber = $destination;
            } else {
                $externalNumber = $callerDigits ?: $caller;
                $internalNumber = $destination;
            }
            $answeredByEndpoint = '';
            if ($cdr) {
                $dstChannel = (string) ($cdr['dstchannel'] ?? '');
                if (preg_match('#^PJSIP/(.+)-[A-Fa-f0-9]+$#', $dstChannel, $channelMatch)) {
                    $answeredByEndpoint = $channelMatch[1];
                }
            }
            if ($answeredByEndpoint === '' && $recordingUniqueId !== '') {
                $answeredByEndpoint = $queueAnswers[$recordingUniqueId] ?? '';
            }
            $answeredBy = $answeredByEndpoint;
            if ($answeredByEndpoint !== '') {
                $matchedExtension = false;
                foreach (store_read('extensions') as $extension) {
                    if (sip_user($extension) === $answeredByEndpoint) {
                        $matchedExtension = true;
                        $answeredBy = trim(
                            (string) ($extension['exten'] ?? '') . ' '
                            . (string) ($extension['name'] ?? '')
                        );
                        break;
                    }
                }
                if (!$matchedExtension) {
                    $answeredByEndpoint = '';
                    $answeredBy = '';
                }
            }
            $files[] = [
                'dept' => $dept['id'],
                'dept_name' => $dept['name'] ?? $code,
                'file' => $file,
                'path' => $path,
                'relative_path' => $relativePath,
                'size' => $size,
                'empty' => $size <= 64,
                'duration' => $duration,
                'external_number' => $externalNumber,
                'internal_number' => $internalNumber,
                'started_key' => $stamp,
                'caller_number' => $callerDigits,
                'destination_number' => $destination,
                'uniqueid' => $recordingUniqueId,
                'direction' => $direction,
                'record_type' => $recordType,
                'answered_by_endpoint' => $answeredByEndpoint,
                'answered_by' => $answeredBy,
            ];
        }
    }
    return $files;
}

function pbx_fetch_recording(string $remotePath, string $local): array
{
    return pbx_download($remotePath, $local);
}

function pbx_custom_sounds(?string $deptId = null): array
{
    $root = '/var/lib/asterisk/sounds/custom';
    $search = $root;
    if ($deptId !== null && ($dept = dept_by_id($deptId))) {
        $search .= '/' . dept_code_of((string) $dept['id']);
    }
    $res = pbx_ssh("find {$search} -type f \\( -iname '*.wav' -o -iname '*.gsm' -o -iname '*.ulaw' \\) 2>/dev/null | sort");
    $files = [];
    foreach (preg_split('/\r?\n/', $res['output']) as $path) {
        $path = trim($path);
        if ($path === '') {
            continue;
        }
        $relative = ltrim(substr($path, strlen($root)), '/');
        $playback = 'custom/' . preg_replace('/\.[^.]+$/', '', $relative);
        $parts = explode('/', $relative);
        $fileDept = null;
        if (count($parts) > 1) {
            foreach (departments() as $dept) {
                if (dept_code_of((string) $dept['id']) === $parts[0]) {
                    $fileDept = (string) $dept['id'];
                    break;
                }
            }
        }
        $files[] = [
            'dept' => $fileDept,
            'file' => basename($path),
            'path' => $path,
            'playback' => $playback,
        ];
    }
    return $files;
}

function pbx_sound_languages(): array
{
    $res = pbx_ssh("find /var/lib/asterisk/sounds -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null");
    $languages = ['en'];
    foreach (preg_split('/\r?\n/', $res['output']) as $name) {
        $name = ast_sanitize_id(trim($name));
        if ($name !== '' && $name !== 'custom') {
            $languages[] = $name;
        }
    }
    sort($languages);
    return array_values(array_unique($languages));
}

function pbx_moh_files(?string $deptId): array
{
    $depts = $deptId ? [dept_by_id($deptId)] : departments();
    $files = [];
    foreach ($depts as $dept) {
        if (!$dept) {
            continue;
        }
        $code = dept_code_of((string) $dept['id']);
        $res = pbx_ssh("find /var/lib/asterisk/moh/{$code} -type f 2>/dev/null | sort");
        foreach (preg_split('/\r?\n/', $res['output']) as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            $files[] = [
                'dept' => $dept['id'],
                'file' => basename($path),
                'path' => $path,
            ];
        }
    }
    return $files;
}

function pbx_upload_media(string $dept, string $kind): array
{
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        return ['ok' => false, 'error' => 'Dosya seçin (wav/gsm)'];
    }
    $orig = (string) ($_FILES['file']['name'] ?? 'ses.wav');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['wav', 'gsm', 'ulaw'], true)) {
        return ['ok' => false, 'error' => 'Yalnızca wav, gsm veya ulaw'];
    }
    if ((int) ($_FILES['file']['size'] ?? 0) > 8 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Dosya 8 MB üstü olamaz'];
    }
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', pathinfo($orig, PATHINFO_FILENAME)) ?: 'ses';
    $code = dept_code_of($dept);
    $name = $safe . '.' . $ext;
    $localDir = DATA_PATH . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($localDir)) {
        mkdir($localDir, 0777, true);
    }
    $local = $localDir . DIRECTORY_SEPARATOR . $code . '-' . $name;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $local)) {
        return ['ok' => false, 'error' => 'Yükleme başarısız'];
    }
    if ($kind === 'moh') {
        $remoteDir = '/var/lib/asterisk/moh/' . $code;
        $remote = $remoteDir . '/' . $name;
    } else {
        $remoteDir = '/var/lib/asterisk/sounds/custom/' . $code;
        $remote = $remoteDir . '/' . $name;
    }
    $mediaId = $dept . '-' . $kind . '-' . $safe;
    $mediaRow = [
        'id' => $mediaId,
        'dept' => $dept,
        'kind' => $kind,
        'name' => $name,
        'path' => $remote,
        'status' => 'pending',
        'updated_at' => date(DATE_ATOM),
    ];
    store_upsert('media', $mediaRow, 'id');
    pbx_ssh("mkdir -p {$remoteDir} && chown asterisk:asterisk {$remoteDir}");
    $up = pbx_upload($local, $remote);
    if (!$up['ok']) {
        $mediaRow['status'] = 'failed';
        $mediaRow['error'] = (string) ($up['output'] ?? '');
        $mediaRow['updated_at'] = date(DATE_ATOM);
        store_upsert('media', $mediaRow, 'id');
        return ['ok' => false, 'error' => $up['output'] ?: 'Santrale kopyalanamadı'];
    }
    $remote = pbx_normalize_wav49($remote);
    $name = basename($remote);
    $mediaRow['name'] = $name;
    $mediaRow['path'] = $remote;
    pbx_ssh("chown asterisk:asterisk {$remote}");
    $mediaRow['status'] = 'active';
    $mediaRow['updated_at'] = date(DATE_ATOM);
    store_upsert('media', $mediaRow, 'id');
    if ($kind !== 'moh') {
        $playback = 'custom/' . $code . '/' . pathinfo($name, PATHINFO_FILENAME);
        store_upsert('sounds', [
            'id' => $dept . '-' . $safe,
            'dept' => $dept,
            'name' => $safe,
            'playback' => $playback,
            'file' => $code . '/' . $name,
            'path' => $remote,
        ], 'id');
        pbx_ssh("asterisk -rx 'module reload sounds'");
        return ['ok' => true, 'playback' => $playback, 'output' => 'Ses yüklendi: ' . $playback];
    }
    pbx_ssh("asterisk -rx 'moh reload'");
    return ['ok' => true, 'output' => 'Bekleme müziği yüklendi'];
}

function pbx_normalize_wav49(string $remote): string
{
    if (strtolower(pathinfo($remote, PATHINFO_EXTENSION)) !== 'wav'
        || pathinfo($remote, PATHINFO_EXTENSION) === 'WAV') {
        return $remote;
    }
    $info = pbx_ssh('file --brief ' . $remote);
    if (!$info['ok'] || !str_contains((string) ($info['output'] ?? ''), 'GSM 6.10')) {
        return $remote;
    }
    $normalized = substr($remote, 0, -4) . '.WAV';
    $move = pbx_ssh('mv -f ' . $remote . ' ' . $normalized);
    return $move['ok'] ? $normalized : $remote;
}

function pbx_normalize_stored_sounds(): array
{
    $sounds = store_read('sounds');
    $media = store_read('media');
    $changed = 0;
    foreach ($sounds as $index => $sound) {
        $oldPath = (string) ($sound['path'] ?? '');
        if ($oldPath === '') {
            continue;
        }
        $newPath = pbx_normalize_wav49($oldPath);
        if ($newPath === $oldPath) {
            continue;
        }
        $sounds[$index]['path'] = $newPath;
        $sounds[$index]['file'] = dirname((string) ($sound['file'] ?? '')) . '/' . basename($newPath);
        foreach ($media as $mediaIndex => $mediaRow) {
            if ((string) ($mediaRow['path'] ?? '') === $oldPath) {
                $media[$mediaIndex]['path'] = $newPath;
                $media[$mediaIndex]['name'] = basename($newPath);
            }
        }
        $changed++;
    }
    if ($changed > 0) {
        store_write('sounds', $sounds);
        store_write('media', $media);
    }
    return ['ok' => true, 'changed' => $changed];
}

function pbx_delete_media(string $path): array
{
    $path = str_replace('\\', '/', $path);
    $ok = (bool) preg_match('#^/var/lib/asterisk/(sounds/custom(?:/[A-Za-z0-9_-]+)?|moh/[A-Za-z0-9_-]+)/[A-Za-z0-9._-]+$#', $path);
    if (!$ok) {
        return ['ok' => false, 'error' => 'Geçersiz yol'];
    }
    $mediaRow = null;
    foreach (store_read('media') as $row) {
        if ((string) ($row['path'] ?? '') === $path) {
            $mediaRow = $row;
            break;
        }
    }
    if (!$mediaRow) {
        $deptId = current_dept_id() ?? '';
        if ($deptId === '' && preg_match('#/(?:sounds/custom|moh)/([A-Za-z0-9_-]+)/#', $path, $match)) {
            foreach (departments() as $department) {
                if ((string) ($department['code'] ?? $department['id'] ?? '') === (string) $match[1]) {
                    $deptId = (string) ($department['id'] ?? '');
                    break;
                }
            }
        }
        if ($deptId === '') {
            return ['ok' => false, 'error' => 'Dosyanın ait olduğu firma belirlenemedi'];
        }
        $mediaRow = [
            'id' => 'delete-' . substr(sha1($path), 0, 20),
            'dept' => $deptId,
            'kind' => str_contains($path, '/moh/') ? 'moh' : 'sound',
            'name' => basename($path),
            'path' => $path,
        ];
    }
    $mediaRow['status'] = 'deleting';
    $mediaRow['updated_at'] = date(DATE_ATOM);
    store_upsert('media', $mediaRow, 'id');
    $res = pbx_ssh('rm -f ' . $path);
    if (!$res['ok']) {
        $mediaRow['status'] = 'delete_failed';
        $mediaRow['error'] = (string) ($res['output'] ?? '');
        $mediaRow['updated_at'] = date(DATE_ATOM);
        store_upsert('media', $mediaRow, 'id');
        return ['ok' => false, 'output' => $res['output']];
    }
    if (str_contains($path, '/sounds/custom/')) {
        $rows = array_values(array_filter(store_read('sounds'), static function ($r) use ($path) {
            if ((string) ($r['path'] ?? '') === $path) {
                return false;
            }
            $playbackPath = '/var/lib/asterisk/sounds/' . (string) ($r['playback'] ?? '');
            return !str_starts_with($path, $playbackPath . '.');
        }));
        store_write('sounds', $rows);
    }
    store_delete('media', 'id', (string) $mediaRow['id']);
    return ['ok' => $res['ok'], 'output' => $res['ok'] ? 'Silindi' : $res['output']];
}
