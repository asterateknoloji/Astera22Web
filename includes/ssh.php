<?php
declare(strict_types=1);

function pbx_ssh(string $script): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'ast');
    if ($tmp === false) {
        return ['ok' => false, 'code' => 1, 'output' => 'Geçici dosya oluşturulamadı'];
    }
    file_put_contents($tmp, str_replace("\r\n", "\n", trim($script)) . "\n");

    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = sprintf(
            '"%s" -ssh %s@%s -pw %s -hostkey %s -batch -m "%s"',
            PLINK,
            SSH_USER,
            PBX_SSH_HOST,
            SSH_PASS,
            PBX_HOSTKEY,
            $tmp
        );
    } else {
        $options = pbx_openssh_options();
        $cmd = pbx_openssh_prefix() . 'ssh ' . $options . ' '
            . escapeshellarg(SSH_USER . '@' . PBX_SSH_HOST)
            . ' bash -s < ' . escapeshellarg($tmp);
    }

    $lines = [];
    $code = 0;
    exec($cmd . ' 2>&1', $lines, $code);
    @unlink($tmp);

    $output = trim(implode("\n", $lines));
    return ['ok' => $code === 0, 'code' => $code, 'output' => $output];
}

function pbx_cli(string $asteriskCommand): string
{
    $safe = str_replace("'", "'\\''", $asteriskCommand);
    $res = pbx_ssh("asterisk -rx '" . $safe . "'");
    return $res['output'];
}

function pbx_upload(string $local, string $remote): array
{
    if (!is_file($local)) {
        return ['ok' => false, 'code' => 1, 'output' => 'Yerel dosya yok: ' . $local];
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = sprintf(
            '"%s" -pw %s -hostkey %s -batch "%s" %s@%s:%s',
            PSCP,
            SSH_PASS,
            PBX_HOSTKEY,
            $local,
            SSH_USER,
            PBX_SSH_HOST,
            $remote
        );
    } else {
        $cmd = pbx_openssh_prefix() . 'scp ' . pbx_openssh_options() . ' '
            . escapeshellarg($local) . ' '
            . escapeshellarg(SSH_USER . '@' . PBX_SSH_HOST . ':' . $remote);
    }

    $lines = [];
    $code = 0;
    exec($cmd . ' 2>&1', $lines, $code);
    return ['ok' => $code === 0, 'code' => $code, 'output' => trim(implode("\n", $lines))];
}

function pbx_download(string $remote, string $local): array
{
    if (!is_dir(dirname($local)) && !mkdir(dirname($local), 0770, true) && !is_dir(dirname($local))) {
        return ['ok' => false, 'code' => 1, 'output' => 'Yerel hedef dizini oluşturulamadı'];
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = sprintf(
            '"%s" -pw %s -hostkey %s -batch %s@%s:%s "%s"',
            PSCP,
            SSH_PASS,
            PBX_HOSTKEY,
            SSH_USER,
            PBX_SSH_HOST,
            $remote,
            $local
        );
    } else {
        $cmd = pbx_openssh_prefix() . 'scp ' . pbx_openssh_options() . ' '
            . escapeshellarg(SSH_USER . '@' . PBX_SSH_HOST . ':' . $remote) . ' '
            . escapeshellarg($local);
    }
    $lines = [];
    $code = 0;
    exec($cmd . ' 2>&1', $lines, $code);
    return ['ok' => $code === 0 && is_file($local), 'code' => $code, 'output' => trim(implode("\n", $lines))];
}

function pbx_openssh_options(): string
{
    $options = ['-o', SSH_KEY !== '' ? 'BatchMode=yes' : 'BatchMode=no', '-o', 'ConnectTimeout=10'];
    if (SSH_KEY !== '') {
        $options[] = '-i';
        $options[] = SSH_KEY;
    }
    if (SSH_KNOWN_HOSTS !== '') {
        $options[] = '-o';
        $options[] = 'UserKnownHostsFile=' . SSH_KNOWN_HOSTS;
        $options[] = '-o';
        $options[] = 'StrictHostKeyChecking=yes';
    } else {
        $options[] = '-o';
        $options[] = 'StrictHostKeyChecking=accept-new';
    }
    return implode(' ', array_map('escapeshellarg', $options));
}

function pbx_openssh_prefix(): string
{
    if (SSH_KEY === '' && SSH_PASS !== '') {
        return 'sshpass -p ' . escapeshellarg(SSH_PASS) . ' ';
    }
    return '';
}

function pbx_apply_files(array $files, array $beforeReload = []): array
{
    $log = [];
    $hashCommands = [];
    foreach ($files as $remote) {
        $hashCommands[] = 'test -f ' . escapeshellarg($remote)
            . ' && md5sum ' . escapeshellarg($remote) . ' || true';
    }
    $remoteHashResult = pbx_ssh(implode("\n", $hashCommands));
    $remoteHashes = [];
    foreach (preg_split('/\r?\n/', (string) ($remoteHashResult['output'] ?? '')) as $line) {
        if (preg_match('/^([a-f0-9]{32})\s+(.+)$/i', trim($line), $match)) {
            $remoteHashes[trim($match[2])] = strtolower($match[1]);
        }
    }

    $changed = [];
    foreach ($files as $local => $remote) {
        $localHash = md5_file($local);
        if ($localHash !== false && ($remoteHashes[$remote] ?? '') === strtolower($localHash)) {
            $log[] = basename($remote) . ': değişiklik yok';
            continue;
        }
        $up = pbx_upload($local, $remote);
        $log[] = basename($remote) . ': ' . ($up['ok'] ? 'yüklendi' : $up['output']);
        if (!$up['ok']) {
            return ['ok' => false, 'output' => implode("\n", $log)];
        }
        $changed[] = basename($remote);
    }

    if (!$changed) {
        return ['ok' => true, 'output' => trim(implode("\n", $log))];
    }

    $commands = array_merge($beforeReload, [
        'chown asterisk:asterisk /etc/asterisk/pjsip.conf /etc/asterisk/pjsip_endpoints.conf /etc/asterisk/pjsip_trunks.conf /etc/asterisk/extensions.conf /etc/asterisk/queues_web.conf /etc/asterisk/voicemail_web.conf /etc/asterisk/confbridge_web.conf /etc/asterisk/res_parking_web.conf /etc/asterisk/musiconhold_web.conf /etc/asterisk/features_web.conf /etc/asterisk/http.conf 2>/dev/null || true',
    ]);
    if (array_intersect($changed, ['extensions.conf'])) {
        $commands[] = "asterisk -rx 'dialplan reload'";
    }
    if (array_intersect($changed, ['pjsip.conf', 'pjsip_endpoints.conf', 'pjsip_trunks.conf'])) {
        $commands[] = "asterisk -rx 'pjsip reload'";
    }
    if (in_array('queues_web.conf', $changed, true)) {
        $commands[] = "asterisk -rx 'module reload app_queue.so'";
    }
    if (in_array('voicemail_web.conf', $changed, true)) {
        $commands[] = "asterisk -rx 'voicemail reload'";
    }
    if (in_array('confbridge_web.conf', $changed, true)) {
        $commands[] = "asterisk -rx 'module reload app_confbridge.so'";
    }
    if (in_array('res_parking_web.conf', $changed, true)) {
        $commands[] = "asterisk -rx 'module reload res_parking.so'";
    }
    if (in_array('musiconhold_web.conf', $changed, true)) {
        $commands[] = "asterisk -rx 'moh reload'";
    }
    if (in_array('features_web.conf', $changed, true)) {
        $commands[] = "asterisk -rx 'core reload'";
    }
    $reload = pbx_ssh(implode("\n", $commands));

    $log[] = $reload['output'];
    return ['ok' => $reload['ok'], 'output' => trim(implode("\n", $log))];
}
