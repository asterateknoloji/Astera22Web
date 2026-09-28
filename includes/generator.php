<?php
declare(strict_types=1);

function ast_sanitize_id(string $value): string
{
    return preg_replace('/[^A-Za-z0-9_-]/', '', $value) ?? '';
}

function ast_quote(string $value): string
{
    return str_replace(["\r", "\n"], '', $value);
}

function generate_all_configs(): array
{
    $files = [
        'pjsip.conf' => generate_pjsip_base(),
        'pjsip_endpoints.conf' => generate_pjsip_endpoints(),
        'pjsip_trunks.conf' => generate_pjsip_trunks(),
        'extensions.conf' => generate_extensions(),
        'queues_web.conf' => generate_queues(),
        'voicemail_web.conf' => generate_voicemail(),
        'confbridge_web.conf' => generate_confbridge(),
        'res_parking_web.conf' => generate_parking(),
        'musiconhold_web.conf' => generate_moh(),
        'features_web.conf' => generate_features_conf(),
        'http.conf' => generate_http_conf(),
    ];

    $paths = [];
    foreach ($files as $name => $body) {
        $path = GEN_PATH . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $body);
        $paths[$path] = '/etc/asterisk/' . $name;
    }
    return $paths;
}

function apply_to_pbx(bool $force = false): array
{
    if (!$force) {
        $dept = ast_sanitize_id((string) ($_POST['dept'] ?? ''));
        if ($dept === '') {
            $dept = (string) (current_dept_id() ?? '*');
        }
        $action = ast_sanitize_id((string) ($_POST['action'] ?? 'change')) ?: 'change';
        store_mark_pending($dept, $action);
        return [
            'ok' => true,
            'pending' => true,
            'pending_count' => store_pending_count(),
            'output' => 'Değişiklik veritabanına kaydedildi; santrale henüz uygulanmadı.',
        ];
    }
    $applyId = store_apply_begin();
    try {
    $paths = generate_all_configs();
    $dirs = [];
    foreach (departments() as $dept) {
        $code = dept_code_of((string) $dept['id']);
        if ($code !== '') {
            $dirs[] = '/var/spool/asterisk/monitor/' . $code;
        }
    }
    $prep = [
        "touch /etc/asterisk/queues.conf /etc/asterisk/voicemail.conf /etc/asterisk/confbridge.conf /etc/asterisk/res_parking.conf /etc/asterisk/musiconhold.conf /etc/asterisk/features.conf",
        "grep -q 'queues_web.conf' /etc/asterisk/queues.conf || echo '#include queues_web.conf' >> /etc/asterisk/queues.conf",
        "grep -q 'voicemail_web.conf' /etc/asterisk/voicemail.conf || echo '#include voicemail_web.conf' >> /etc/asterisk/voicemail.conf",
        "grep -q 'confbridge_web.conf' /etc/asterisk/confbridge.conf || echo '#include confbridge_web.conf' >> /etc/asterisk/confbridge.conf",
        "grep -q 'res_parking_web.conf' /etc/asterisk/res_parking.conf || echo '#include res_parking_web.conf' >> /etc/asterisk/res_parking.conf",
        "grep -q 'musiconhold_web.conf' /etc/asterisk/musiconhold.conf || echo '#include musiconhold_web.conf' >> /etc/asterisk/musiconhold.conf",
        "grep -q 'features_web.conf' /etc/asterisk/features.conf || echo '#include features_web.conf' >> /etc/asterisk/features.conf",
        'mkdir -p /etc/asterisk/keys /root/asterisk-web-backup',
        'test -f /root/asterisk-web-backup/http.conf || cp /etc/asterisk/http.conf /root/asterisk-web-backup/http.conf',
        'if [ ! -f /etc/asterisk/keys/asterisk.crt ]; then openssl req -x509 -nodes -days 3650 -newkey rsa:2048 -keyout /etc/asterisk/keys/asterisk.key -out /etc/asterisk/keys/asterisk.crt -subj "/CN=' . PBX_HOST . '"; cat /etc/asterisk/keys/asterisk.crt /etc/asterisk/keys/asterisk.key > /etc/asterisk/keys/asterisk.pem; chown asterisk:asterisk /etc/asterisk/keys/asterisk.crt /etc/asterisk/keys/asterisk.key /etc/asterisk/keys/asterisk.pem; chmod 640 /etc/asterisk/keys/asterisk.key /etc/asterisk/keys/asterisk.pem; fi',
        "grep -q '^icesupport' /etc/asterisk/rtp.conf || echo 'icesupport=yes' >> /etc/asterisk/rtp.conf",
        'mkdir -p /var/lib/asterisk/sounds/custom',
        'chown -R asterisk:asterisk /var/lib/asterisk/sounds/custom',
    ];
    if ($dirs) {
        $prep[] = 'mkdir -p ' . implode(' ', $dirs);
        $prep[] = 'chown -R asterisk:asterisk /var/spool/asterisk/monitor';
    }
    $mohDirs = [];
    $soundDirs = [];
    foreach (departments() as $dept) {
        $code = dept_code_of((string) $dept['id']);
        if ($code !== '') {
            $mohDirs[] = '/var/lib/asterisk/moh/' . $code;
            $soundDirs[] = '/var/lib/asterisk/sounds/custom/' . $code;
        }
    }
    if ($mohDirs) {
        $prep[] = 'mkdir -p ' . implode(' ', $mohDirs);
        $prep[] = 'chown -R asterisk:asterisk /var/lib/asterisk/moh';
    }
    if ($soundDirs) {
        $prep[] = 'mkdir -p ' . implode(' ', $soundDirs);
        $prep[] = 'chown -R asterisk:asterisk /var/lib/asterisk/sounds/custom';
    }
    $inc = pbx_ssh(implode("\n", $prep));
    $apply = pbx_apply_files($paths);
    if (preg_match('/^pjsip(?:_endpoints|_trunks)?\.conf: yüklendi$/m', (string) ($apply['output'] ?? ''))) {
        $enabledRegistrations = [];
        foreach (store_read('trunks') as $trunk) {
            if (!empty($trunk['enabled']) && (string) ($trunk['type'] ?? 'register') === 'register') {
                $enabledRegistrations[] = ast_sanitize_id((string) ($trunk['id'] ?? ''));
            }
        }
        $enabledRegistrations = array_values(array_filter($enabledRegistrations));
        if ($enabledRegistrations) {
            $registrationStatus = '';
            for ($attempt = 0; $attempt < 3; $attempt++) {
                usleep(750000);
                $commands = array_map(
                    static fn(string $id): string => "asterisk -rx 'pjsip send register {$id}'",
                    $enabledRegistrations
                );
                pbx_ssh(implode("\n", $commands));
                usleep(750000);
                $registrationStatus = pbx_cli('pjsip show registrations');
                $allRegistered = true;
                foreach ($enabledRegistrations as $id) {
                    if (!preg_match('/^\s*' . preg_quote($id, '/') . '\/\S+\s+\S+\s+Registered\b/m', $registrationStatus)) {
                        $allRegistered = false;
                        break;
                    }
                }
                if ($allRegistered) {
                    break;
                }
            }
            $apply['output'] = trim($apply['output'] . "\nTrunk kayıtları yenilendi.\n" . $registrationStatus);
        }
    }
    $bl = ["asterisk -rx 'database deltree BL'"];
    foreach (store_read('blacklist') as $row) {
        $bcode = dept_code_of((string) ($row['dept'] ?? ''));
        $num = preg_replace('/\D+/', '', (string) ($row['number'] ?? '')) ?? '';
        if ($bcode !== '' && $num !== '') {
            $bl[] = "asterisk -rx 'database put BL {$bcode}/{$num} 1'";
        }
    }
    $sync = pbx_ssh(implode("\n", $bl));
    $apply['output'] = trim($apply['output'] . "\n" . $sync['output']);
    $recordingPrefs = [
        "asterisk -rx 'database deltree REC'",
        "asterisk -rx 'database deltree RECFMT'",
    ];
    foreach (store_read('extensions') as $extension) {
        $deptId = (string) ($extension['dept'] ?? '');
        $exten = ast_sanitize_id((string) ($extension['exten'] ?? ''));
        if ($deptId === '' || $exten === '') {
            continue;
        }
        $dept = dept_by_id($deptId) ?? [];
        $enabled = array_key_exists('record', $extension)
            ? !empty($extension['record'])
            : !empty($dept['record']);
        $format = strtolower((string) ($extension['record_format'] ?? 'wav'));
        if (!in_array($format, ['wav', 'gsm', 'ulaw'], true)) {
            $format = 'wav';
        }
        $code = dept_code_of($deptId);
        $recordingPrefs[] = "asterisk -rx 'database put REC/{$code} {$exten} " . ($enabled ? '1' : '0') . "'";
        $recordingPrefs[] = "asterisk -rx 'database put RECFMT/{$code} {$exten} {$format}'";
    }
    $recordingSync = pbx_ssh(implode("\n", $recordingPrefs));
    if (trim((string) ($recordingSync['output'] ?? '')) !== '') {
        $apply['output'] = trim($apply['output'] . "\n" . $recordingSync['output']);
    }
    $crmCallerIdSync = crm_sync_all_caller_id_cache();
    if (trim((string) ($crmCallerIdSync['output'] ?? '')) !== '') {
        $apply['output'] = trim($apply['output'] . "\n" . $crmCallerIdSync['output']);
    }
    if (empty($crmCallerIdSync['ok'])) {
        $apply['ok'] = false;
    }
    $apply['output'] = trim($inc['output'] . "\n" . $apply['output']);
    $http = pbx_ssh("ss -lnt | grep -q ':8088' || systemctl restart asterisk");
    if (trim($http['output']) !== '') {
        $apply['output'] = trim($apply['output'] . "\n" . $http['output']);
    }
    $reporting = ensure_reporting_database();
    if (!$reporting['ok']) {
        $apply['ok'] = false;
    }
    if (trim((string) ($reporting['output'] ?? '')) !== '') {
        $apply['output'] = trim($apply['output'] . "\n" . $reporting['output']);
    }
    if (!empty($apply['ok'])) {
        store_clear_pending();
    }
    store_apply_finish($applyId, !empty($apply['ok']), (string) ($apply['output'] ?? ''));
    return $apply;
    } catch (Throwable $e) {
        try {
            store_apply_finish($applyId, false, $e->getMessage());
        } catch (Throwable) {
        }
        throw $e;
    }
}

function generate_pjsip_base(): string
{
    $pub = defined('PBX_PUBLIC_IP') ? trim((string) PBX_PUBLIC_IP) : '';
    $local = defined('PBX_LOCAL_NET') ? trim((string) PBX_LOCAL_NET) : '192.168.0.0/16';
    $nat = '';
    if ($pub !== '' && !preg_match('/^(10\.|127\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/', $pub)) {
        $nat = "external_signaling_address={$pub}\nexternal_media_address={$pub}\nlocal_net={$local}\n";
    }
    return <<<CONF
;========================================================
; ASTERA / Asterisk 22 - çoklu firma
;========================================================

[global]
type=global
user_agent=Asterisk-22-Astera
endpoint_identifier_order=ip,username,anonymous

[transport-udp]
type=transport
protocol=udp
allow_reload=true
bind=0.0.0.0:5060
{$nat}
[transport-tcp]
type=transport
protocol=tcp
allow_reload=true
bind=0.0.0.0:5060
{$nat}
[transport-tls]
type=transport
protocol=tls
allow_reload=true
bind=0.0.0.0:5061
cert_file=/etc/asterisk/keys/asterisk.pem
priv_key_file=/etc/asterisk/keys/asterisk.pem
method=tlsv1

[transport-ws]
type=transport
protocol=ws
allow_reload=true
bind=0.0.0.0:5061

[transport-wss]
type=transport
protocol=wss
allow_reload=true
bind=0.0.0.0:5061

[endpoint-basic](!)
type=endpoint
disallow=all
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
language=en
transport=transport-udp

[endpoint-webrtc](!)
type=endpoint
disallow=all
direct_media=no
rtp_symmetric=yes
force_rport=yes
rewrite_contact=yes
language=en
webrtc=yes
dtls_auto_generate_cert=yes
rtcp_mux=yes
use_avpf=yes
ice_support=yes
media_encryption=dtls
dtls_verify=fingerprint
dtls_setup=actpass
media_use_received_transport=yes
bundle=yes
max_audio_streams=10

#include pjsip_endpoints.conf
#include pjsip_trunks.conf

CONF;
}

function generate_pjsip_endpoints(): string
{
    $out = "; ASTERA aboneler (firma izole)\n\n";
    foreach (store_read('extensions') as $ext) {
        $sip = sip_user($ext);
        $num = ast_sanitize_id((string) ($ext['exten'] ?? ''));
        $dept = ast_sanitize_id((string) ($ext['dept'] ?? ''));
        if ($sip === '' || $num === '' || $dept === '') {
            continue;
        }
        $pass = ast_quote((string) ($ext['password'] ?? ''));
        $auth = auth_user($ext);
        $name = ast_quote((string) ($ext['name'] ?? $num));
        $max = max(1, (int) ($ext['max_contacts'] ?? 1));
        $context = ctx($dept, 'from');
        $allow = codec_lines($ext['codecs'] ?? ['alaw', 'ulaw']);
        $dtmf = ast_sanitize_id((string) ($ext['dtmf'] ?? 'rfc4733')) ?: 'rfc4733';
        if (!in_array($dtmf, ['rfc4733', 'inband', 'info'], true)) {
            $dtmf = 'rfc4733';
        }
        $lang = ast_sanitize_id((string) ($ext['language'] ?? 'en')) ?: 'en';
        $webrtc = !empty($ext['webrtc']);
        $qualify = max(0, (int) ($ext['qualify'] ?? 0));
        $grp = dept_group_no($dept);
        $mail = !empty($ext['vm_enabled']) ? "mailboxes={$num}@" . dept_code_of($dept) . "\n" : '';
        if ($webrtc) {
            $codecs = $ext['codecs'] ?? ['alaw', 'ulaw'];
            if (!is_array($codecs)) {
                $codecs = ['alaw', 'ulaw'];
            }
            if (!in_array('opus', $codecs, true)) {
                array_unshift($codecs, 'opus');
            }
            $allow = codec_lines($codecs);
            $tmpl = 'endpoint-webrtc';
            $transportLine = '';
        } else {
            $transport = (($ext['transport'] ?? 'udp') === 'tcp') ? 'transport-tcp' : 'transport-udp';
            $tmpl = 'endpoint-basic';
            $transportLine = "transport={$transport}\n";
        }
        $out .= <<<CONF
;--- {$dept} {$num} sip:{$sip} auth:{$auth} ---
[auth{$sip}]
type=auth
auth_type=userpass
username={$auth}
password={$pass}

[{$sip}]
type=aor
max_contacts={$max}
remove_existing=yes
qualify_frequency={$qualify}

[{$sip}]({$tmpl})
context={$context}
auth=auth{$sip}
aors={$sip}
callerid="{$name}" <{$num}>
dtmf_mode={$dtmf}
language={$lang}
{$transportLine}call_group={$grp}
pickup_group={$grp}
{$mail}{$allow}

CONF;
    }
    return $out;
}

function codec_lines(mixed $codecs): string
{
    if (!is_array($codecs) || !$codecs) {
        $codecs = ['alaw', 'ulaw'];
    }
    $allow = '';
    foreach ($codecs as $c) {
        $c = ast_sanitize_id((string) $c);
        if ($c !== '') {
            $allow .= "allow={$c}\n";
        }
    }
    return $allow;
}

function generate_pjsip_trunks(): string
{
    $out = "; ASTERA trunklar\n\n";
    $any = false;
    foreach (store_read('trunks') as $trunk) {
        if (array_key_exists('enabled', $trunk) && !$trunk['enabled']) {
            continue;
        }
        $id = ast_sanitize_id((string) ($trunk['id'] ?? ''));
        $dept = ast_sanitize_id((string) ($trunk['dept'] ?? ''));
        if ($id === '' || $dept === '') {
            continue;
        }
        $any = true;
        $host = ast_quote((string) ($trunk['host'] ?? ''));
        $port = (int) ($trunk['port'] ?? 5060) ?: 5060;
        $hostPort = $host . ($port === 5060 ? '' : ':' . $port);
        $user = ast_quote((string) ($trunk['username'] ?? ''));
        $pass = ast_quote((string) ($trunk['password'] ?? ''));
        $fromDomain = ast_quote((string) ($trunk['from_domain'] ?: $host));
        $fromUser = ast_quote((string) ($trunk['from_user'] ?: $user));
        $type = ($trunk['type'] ?? 'register') === 'peer' ? 'peer' : 'register';
        $codecs = $trunk['codecs'] ?? ['ulaw', 'alaw', 'gsm', 'g726', 'g722'];
        if (!is_array($codecs) || !$codecs) {
            $codecs = ['ulaw', 'alaw'];
        }
        $codecs = array_values(array_filter(array_map(
            static fn ($codec) => ast_sanitize_id((string) $codec),
            $codecs
        )));
        $allow = implode(',', $codecs ?: ['ulaw', 'alaw']);
        $contact = 'sip:' . ($user !== '' ? $user . '@' : '') . $hostPort;
        $qualifyFrequency = max(0, (int) ($trunk['qualify_frequency'] ?? 60));
        $supportPath = !empty($trunk['support_path']) ? 'yes' : 'no';
        $dtmfMode = (string) ($trunk['dtmf_mode'] ?? 'auto');
        if (!in_array($dtmfMode, ['auto', 'rfc4733', 'inband', 'info'], true)) {
            $dtmfMode = 'auto';
        }
        $directMedia = !empty($trunk['direct_media']) ? 'yes' : 'no';
        $faxDetect = !empty($trunk['fax_detect']) ? 'yes' : 'no';
        $mediaEncryption = (string) ($trunk['media_encryption'] ?? 'no');
        if (!in_array($mediaEncryption, ['no', 'sdes', 'dtls'], true)) {
            $mediaEncryption = 'no';
        }
        $rewriteContact = ($trunk['rewrite_contact'] ?? true) ? 'yes' : 'no';
        $rtpSymmetric = ($trunk['rtp_symmetric'] ?? true) ? 'yes' : 'no';
        $trustIdInbound = !empty($trunk['trust_id_inbound']) ? 'yes' : 'no';
        $t38Udptl = !empty($trunk['t38_udptl']) ? 'yes' : 'no';
        $t38UdptlNat = !empty($trunk['t38_udptl_nat']) ? 'yes' : 'no';
        if ($type === 'register') {
            // 908503338008 pjsip_additional.conf master register trunk template.
            $allow = 'ulaw,alaw,gsm,g726,g722';
            $qualifyFrequency = 60;
            $supportPath = 'no';
            $dtmfMode = 'auto';
            $directMedia = 'no';
            $faxDetect = 'no';
            $mediaEncryption = 'no';
            $rewriteContact = 'yes';
            $rtpSymmetric = 'yes';
            $trustIdInbound = 'no';
            $t38Udptl = 'no';
            $t38UdptlNat = 'no';
        }

        $out .= <<<CONF
;--- {$dept} trunk {$id} ---
[{$id}]
type=aor
qualify_frequency={$qualifyFrequency}
contact={$contact}
support_path={$supportPath}

CONF;
        if ($user !== '') {
            $out .= <<<CONF
[{$id}]
type=auth
auth_type=userpass
password={$pass}
username={$user}

CONF;
        }
        $out .= <<<CONF
[{$id}]
type=identify
endpoint={$id}
match={$host}

CONF;
        if ($type === 'register' && $user !== '') {
            $authRejectionPermanent = 'no';
            $expiration = 3600;
            $maxRetries = 10;
            $retryInterval = 60;
            $out .= <<<CONF
[{$id}]
type=registration
endpoint={$id}
line=yes
outbound_auth={$id}
server_uri=sip:{$hostPort}
client_uri=sip:{$user}@{$hostPort}
contact_user={$user}
auth_rejection_permanent={$authRejectionPermanent}
expiration={$expiration}
max_retries={$maxRetries}
retry_interval={$retryInterval}
transport=transport-udp

CONF;
        }
        $authLines = $user !== '' ? "outbound_auth={$id}\n" : '';
        $fromUserLine = $fromUser !== '' ? "from_user={$fromUser}\n" : '';
        $out .= <<<CONF
[{$id}]
type=endpoint
aors={$id}
disallow=all
{$authLines}allow={$allow}
context=from-pstn
direct_media={$directMedia}
dtmf_mode={$dtmfMode}
fax_detect={$faxDetect}
from_domain={$fromDomain}
{$fromUserLine}media_encryption={$mediaEncryption}
rewrite_contact={$rewriteContact}
rtp_symmetric={$rtpSymmetric}
t38_udptl={$t38Udptl}
t38_udptl_ec=none
t38_udptl_nat={$t38UdptlNat}
transport=transport-udp
trust_id_inbound={$trustIdInbound}

CONF;
        $out .= "\n";
    }
    return $any ? $out : "; ASTERA trunklar\n; (henüz trunk yok)\n";
}

function generate_extensions(): string
{
    $blocks = [];
    foreach (departments() as $dept) {
        $id = (string) ($dept['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $code = dept_code_of($id);
        $record = !empty($dept['record']);
        $cid = ast_quote((string) ($dept['cid'] ?? ''));
        $from = ctx($id, 'from');
        $int = ctx($id, 'int');
        $outC = ctx($id, 'out');
        $inC = ctx($id, 'in');
        $feat = ctx($id, 'feat');
        $sub = ctx($id, 'sub');
        $atxfer = 'atxfer-' . $code;
        $monDir = '/var/spool/asterisk/monitor/' . $code;

        $subBody = "exten => s,1,Set(CHANNEL(accountcode)={$code})\n";
        $subBody .= " same => n,Set(CDR(userfield)=\${IF(\$[\"\${ASTERA_TRANSFER_AUDIT}\" != \"\"]?\${ASTERA_TRANSFER_AUDIT}:{$code})})\n";
        $subBody .= " same => n,Set(CDR(accountcode)={$code})\n";
        $subBody .= " same => n,Set(CDR(cnum)=\${CALLERID(num)})\n";
        $subBody .= " same => n,Set(CDR(cnam)=\${CALLERID(name)})\n";
        $subBody .= " same => n,ExecIf(\$[\"\${ASTERA_DID}\" != \"\"]?Set(CDR(did)=\${ASTERA_DID}))\n";
        $subBody .= " same => n,Set(__ASTERA_TRANSFER_DISPLAY=\${IF(\$[\"\${ASTERA_TRANSFER_DISPLAY}\" = \"\"]?\${CALLERID(num)}:\${ASTERA_TRANSFER_DISPLAY})})\n";
        $subBody .= " same => n,Set(__ASTERA_TRANSFER_NAME=\${IF(\$[\"\${ASTERA_TRANSFER_NAME}\" = \"\"]?\${CALLERID(name)}:\${ASTERA_TRANSFER_NAME})})\n";
        $subBody .= " same => n,Set(DB(ATXFER/\${CHANNEL(linkedid)})=\${ASTERA_TRANSFER_DISPLAY})\n";
        $subBody .= " same => n,Set(DB(ATXFERNAME/\${CHANNEL(linkedid)})=\${ASTERA_TRANSFER_NAME})\n";
        $subBody .= " same => n,Set(__TRANSFER_CONTEXT={$atxfer})\n";
        $subBody .= " same => n,GotoIf(\$[\${DB_EXISTS(BL/{$code}/\${CALLERID(num)})}]?deny)\n";
        $recordDefault = $record ? '1' : '0';
        $subBody .= " same => n,Set(ASTERA_RECORD_ENABLED=\${IF(\$[\"\${ASTERA_RECORD_ENABLED}\" = \"\"]?{$recordDefault}:\${ASTERA_RECORD_ENABLED})})\n";
        $subBody .= " same => n,GotoIf(\$[\"\${ASTERA_RECORD_ENABLED}\" != \"1\"]?recorddone)\n";
        $subBody .= " same => n,GotoIf(\$[\"\${ASTERA_SKIP_RECORD}\" = \"1\"]?recorddone)\n";
        $subBody .= " same => n,Set(ASTERA_RECORD_DIRECTION=\${IF(\$[\"\${ASTERA_RECORD_DIRECTION}\" = \"\"]?dahili:\${ASTERA_RECORD_DIRECTION})})\n";
        $subBody .= " same => n,Set(ASTERA_RECORD_TYPE=\${IF(\$[\"\${ASTERA_RECORD_TYPE}\" = \"\"]?dahili:\${ASTERA_RECORD_TYPE})})\n";
        $subBody .= " same => n,Set(ASTERA_RECORD_FORMAT=\${IF(\$[\"\${ASTERA_RECORD_FORMAT}\" = \"\"]?wav:\${ASTERA_RECORD_FORMAT})})\n";
        $subBody .= " same => n,Set(ASTERA_RECORD_INTERNAL=\${FILTER(0-9A-Za-z_,\${ASTERA_RECORD_INTERNAL})})\n";
        $subBody .= " same => n,Set(ASTERA_RECORD_EXTERNAL=\${FILTER(0-9A-Za-z_,\${ASTERA_RECORD_EXTERNAL})})\n";
        $subBody .= " same => n,Set(ASTERA_MON_DIR={$monDir}/\${STRFTIME(\${EPOCH},,%Y)}/\${STRFTIME(\${EPOCH},,%m)}/\${STRFTIME(\${EPOCH},,%d)})\n";
        $subBody .= " same => n,System(/bin/mkdir -p \${ASTERA_MON_DIR})\n";
        $subBody .= " same => n,Set(ASTERA_RECORD_FILE=\${STRFTIME(\${EPOCH},,%Y%m%d-%H%M%S)}-yon_\${ASTERA_RECORD_DIRECTION}-tip_\${ASTERA_RECORD_TYPE}-dahili_\${ASTERA_RECORD_INTERNAL}-numara_\${ASTERA_RECORD_EXTERNAL}-uid_\${UNIQUEID}.\${ASTERA_RECORD_FORMAT})\n";
        $subBody .= " same => n,Set(CDR(recordingfile)=\${STRFTIME(\${EPOCH},,%Y)}/\${STRFTIME(\${EPOCH},,%m)}/\${STRFTIME(\${EPOCH},,%d)}/\${ASTERA_RECORD_FILE})\n";
        $subBody .= " same => n,MixMonitor(\${ASTERA_MON_DIR}/\${ASTERA_RECORD_FILE},b)\n";
        $subBody .= " same => n(recorddone),NoOp(Kayit tercihi uygulandi)\n";
        $subBody .= " same => n,Return()\n";
        $subBody .= " same => n(deny),Playback(ss-noservice)\n";
        $subBody .= " same => n,Hangup()\n";
        $subBody .= "\nexten => transfer-preserve,1,NoOp(Aktarim arayan kimligi korunuyor)\n";
        $subBody .= " same => n,Set(__ASTERA_TRANSFER_DISPLAY=\${IF(\$[\"\${ASTERA_TRANSFER_DISPLAY}\" = \"\"]?\${CALLERID(num)}:\${ASTERA_TRANSFER_DISPLAY})})\n";
        $subBody .= " same => n,Set(__ASTERA_TRANSFER_NAME=\${IF(\$[\"\${ASTERA_TRANSFER_NAME}\" = \"\"]?\${CALLERID(name)}:\${ASTERA_TRANSFER_NAME})})\n";
        $subBody .= " same => n,Set(__TRANSFER_CONTEXT={$atxfer})\n";
        $subBody .= " same => n,Set(DB(ATXFER/\${CHANNEL(linkedid)})=\${ASTERA_TRANSFER_DISPLAY})\n";
        $subBody .= " same => n,Set(DB(ATXFERNAME/\${CHANNEL(linkedid)})=\${ASTERA_TRANSFER_NAME})\n";
        $subBody .= " same => n,ExecIf(\$[\"\${ASTERA_TRANSFER_DISPLAY}\" != \"\"]?Set(CALLERID(num)=\${ASTERA_TRANSFER_DISPLAY}))\n";
        $subBody .= " same => n,ExecIf(\$[\"\${ASTERA_TRANSFER_NAME}\" != \"\"]?Set(CALLERID(name)=\${ASTERA_TRANSFER_NAME}))\n";
        $subBody .= " same => n,ExecIf(\$[\"\${ASTERA_TRANSFER_DISPLAY}\" != \"\"]?Set(CONNECTEDLINE(num,i)=\${ASTERA_TRANSFER_DISPLAY}))\n";
        $subBody .= " same => n,ExecIf(\$[\"\${ASTERA_TRANSFER_NAME}\" != \"\"]?Set(CONNECTEDLINE(name,i)=\${ASTERA_TRANSFER_NAME}))\n";
        $subBody .= " same => n,Return()\n";

        $internal = '';
        foreach (store_read('extensions') as $ext) {
            if ((string) ($ext['dept'] ?? '') !== $id) {
                continue;
            }
            $num = ast_sanitize_id((string) ($ext['exten'] ?? ''));
            $sip = sip_user($ext);
            if ($num === '' || $sip === '') {
                continue;
            }
            $ring = max(8, (int) ($ext['ringtime'] ?? 30));
            $vm = !empty($ext['vm_enabled']);
            $language = ast_sanitize_id((string) ($ext['language'] ?? 'en')) ?: 'en';
            $extensionRecord = array_key_exists('record', $ext) ? !empty($ext['record']) : $record;
            $extensionRecordFormat = strtolower((string) ($ext['record_format'] ?? 'wav'));
            if (!in_array($extensionRecordFormat, ['wav', 'gsm', 'ulaw'], true)) {
                $extensionRecordFormat = 'wav';
            }
            $cidExt = ast_quote((string) ($ext['cid_num'] ?? ''));
            $fm = array_filter(array_map('trim', explode(',', (string) ($ext['followme'] ?? ''))));
            $fallbackType = dest_allowed((string) ($ext['fallback_type'] ?? 'hangup'));
            $fallbackDest = ast_sanitize_id((string) ($ext['fallback_dest'] ?? ''));
            $hasFallback = $fallbackType !== 'hangup' || $fallbackDest !== '';
            $internal .= "exten => {$num},hint,PJSIP/{$sip}\n";
            $internal .= "exten => {$num},1,Set(ASTERA_RECORD_DEST={$num})\n";
            $internal .= " same => n,Set(ASTERA_RECORD_ENABLED=" . ($extensionRecord ? '1' : '0') . ")\n";
            $internal .= " same => n,Set(ASTERA_RECORD_FORMAT={$extensionRecordFormat})\n";
            $internal .= " same => n,Set(ASTERA_RECORD_DIRECTION=\${IF(\$[\"\${ASTERA_RECORD_DIRECTION}\" = \"\"]?dahili:\${ASTERA_RECORD_DIRECTION})})\n";
            $internal .= " same => n,Set(ASTERA_RECORD_TYPE=dahili)\n";
            $internal .= " same => n,Set(ASTERA_RECORD_INTERNAL={$num})\n";
            $internal .= " same => n,Set(ASTERA_RECORD_EXTERNAL=\${IF(\$[\"\${ASTERA_RECORD_EXTERNAL}\" = \"\"]?\${CALLERID(num)}:\${ASTERA_RECORD_EXTERNAL})})\n";
            $internal .= " same => n,Gosub({$sub},s,1)\n";
            $internal .= " same => n,Set(CHANNEL(language)={$language})\n";
            $internal .= " same => n,GotoIf(\$[\"\${DB(DND/{$code}/{$num})}\" = \"YES\"]?dnd)\n";
            $internal .= " same => n,Set(CF=\${DB(CF/{$code}/{$num})})\n";
            $internal .= " same => n,GotoIf(\$[\"\${CF}\" != \"\"]?cfwd)\n";
            if ($cidExt !== '') {
                $internal .= " same => n,ExecIf(\$[\"\${ASTERA_TRANSFER_DISPLAY}\" = \"\"]?Set(CALLERID(num)={$cidExt}))\n";
            }
            $internal .= " same => n,Set(DIALCONTACTS=\${PJSIP_DIAL_CONTACTS({$sip})})\n";
            $internal .= " same => n,Dial(\${DIALCONTACTS},{$ring},tTrb({$sub}^transfer-preserve^1))\n";
            foreach ($fm as $extra) {
                $extra = ast_sanitize_id($extra);
                if ($extra !== '' && $extra !== $num) {
                    $esip = member_sip($id, $extra);
                    $internal .= " same => n,Dial(PJSIP/{$esip},15,tTrb({$sub}^transfer-preserve^1))\n";
                }
            }
            if ($hasFallback) {
                $internal .= " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"BUSY\" | \"\${DIALSTATUS}\" = \"NOANSWER\" | \"\${DIALSTATUS}\" = \"CHANUNAVAIL\" | \"\${DIALSTATUS}\" = \"CONGESTION\"]?fallback)\n";
            }
            if ($vm) {
                $internal .= " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"BUSY\"]?busy:unavail)\n";
                $internal .= " same => n(busy),VoiceMail({$num}@{$code},b)\n";
                $internal .= " same => n,Hangup()\n";
                $internal .= " same => n(unavail),VoiceMail({$num}@{$code},u)\n";
                $internal .= " same => n,Hangup()\n";
            } else {
                $internal .= " same => n,Hangup()\n";
            }
            if ($hasFallback) {
                $fallbackName = ast_quote((string) ($ext['name'] ?? $num));
                $internal .= " same => n(fallback),Set(__ASTERA_FALLBACK_FROM={$num})\n";
                $internal .= " same => n,Set(__ASTERA_FALLBACK_NAME={$fallbackName})\n";
                $internal .= " same => n,Set(__ASTERA_FALLBACK_TYPE={$fallbackType})\n";
                $internal .= " same => n,Set(__ASTERA_FALLBACK_DEST={$fallbackDest})\n";
                $internal .= " same => n,Set(__ASTERA_FALLBACK_REASON=\${DIALSTATUS})\n";
                $internal .= " same => n,NoOp(Dahili {$num} cevaplanmadi veya mesgul: \${DIALSTATUS})\n";
                $internal .= dest_goto($id, $fallbackType, $fallbackDest, $int);
                $internal .= " same => n(dnd),Goto(fallback)\n";
            } else {
                $internal .= " same => n(dnd),Playback(silence/1)\n same => n,Hangup()\n";
            }
            $internal .= " same => n(cfwd),Goto({$int},\${CF},1)\n\n";
        }

        foreach (store_read('queues') as $q) {
            if ((string) ($q['dept'] ?? '') !== $id) {
                continue;
            }
            $num = ast_sanitize_id((string) ($q['exten'] ?? ''));
            $qid = ast_sanitize_id((string) ($q['id'] ?? ''));
            if ($num === '' || $qid === '') {
                continue;
            }
            $language = ast_sanitize_id((string) ($q['language'] ?? 'en')) ?: 'en';
            $queueRecord = array_key_exists('record', $q) ? !empty($q['record']) : $record;
            $queueRecordFormat = strtolower((string) ($q['record_format'] ?? 'wav'));
            if (!in_array($queueRecordFormat, ['wav', 'gsm', 'ulaw'], true)) {
                $queueRecordFormat = 'wav';
            }
            $internal .= "exten => {$num},1,Set(ASTERA_RECORD_DEST={$num})\n";
            $internal .= " same => n,Set(ASTERA_RECORD_ENABLED=" . ($queueRecord ? '1' : '0') . ")\n";
            $internal .= " same => n,Set(ASTERA_RECORD_FORMAT={$queueRecordFormat})\n";
            $internal .= " same => n,Set(ASTERA_RECORD_DIRECTION=\${IF(\$[\"\${ASTERA_RECORD_DIRECTION}\" = \"\"]?dahili:\${ASTERA_RECORD_DIRECTION})})\n";
            $internal .= " same => n,Set(ASTERA_RECORD_TYPE=queue)\n";
            $internal .= " same => n,Set(ASTERA_RECORD_INTERNAL={$num})\n";
            $internal .= " same => n,Set(ASTERA_RECORD_EXTERNAL=\${IF(\$[\"\${ASTERA_RECORD_EXTERNAL}\" = \"\"]?\${CALLERID(num)}:\${ASTERA_RECORD_EXTERNAL})})\n";
            $internal .= " same => n,Gosub({$sub},s,1)\n";
            $internal .= " same => n,Set(CHANNEL(language)={$language})\n";
            $internal .= " same => n,Answer()\n";
            $announce = ast_quote((string) ($q['announce'] ?? ''));
            if ($announce !== '') {
                $internal .= " same => n,Playback({$announce})\n";
            }
            $queueTimeout = max(0, min(86400, (int) ($q['queue_timeout'] ?? 300)));
            $queueOptions = '';
            if ($q['caller_transfer'] ?? true) {
                $queueOptions .= 't';
            }
            if ($q['agent_transfer'] ?? true) {
                $queueOptions .= 'T';
            }
            $queueOptions .= "b({$sub}^transfer-preserve^1)";
            $internal .= " same => n,Queue({$qid},{$queueOptions},,,{$queueTimeout})\n";
            $qtoType = dest_allowed((string) ($q['timeout_type'] ?? 'hangup'));
            $qtoDest = ast_sanitize_id((string) ($q['timeout_dest'] ?? ''));
            if ($qtoType !== 'hangup' || $qtoDest !== '') {
                $internal .= " same => n,GotoIf(\$[\"\${QUEUESTATUS}\" = \"TIMEOUT\" | \"\${QUEUESTATUS}\" = \"FULL\" | \"\${QUEUESTATUS}\" = \"JOINEMPTY\" | \"\${QUEUESTATUS}\" = \"LEAVEEMPTY\" | \"\${QUEUESTATUS}\" = \"JOINUNAVAIL\" | \"\${QUEUESTATUS}\" = \"LEAVEUNAVAIL\"]?qto)\n";
                $internal .= " same => n,Hangup()\n";
                $internal .= " same => n(qto),NoOp(kuyruk cikis: \${QUEUESTATUS})\n";
                $internal .= dest_goto($id, $qtoType, $qtoDest, $int);
            } else {
                $internal .= " same => n,Hangup()\n\n";
            }
            if (!str_ends_with($internal, "\n\n")) {
                $internal .= "\n";
            }
        }

        foreach (store_read('ringgroups') as $g) {
            if ((string) ($g['dept'] ?? '') !== $id) {
                continue;
            }
            $num = ast_sanitize_id((string) ($g['exten'] ?? ''));
            if ($num === '') {
                continue;
            }
            $sips = [];
            foreach ((array) ($g['members'] ?? []) as $member) {
                $sip = member_sip($id, (string) $member);
                if ($sip !== '') {
                    $sips[] = 'PJSIP/' . $sip;
                }
            }
            if (!$sips) {
                continue;
            }
            
            $ringTime = max(5, (int) ($g['ring_time'] ?? 20));
            $language = ast_sanitize_id((string) ($g['language'] ?? 'en')) ?: 'en';
            $confirmCalls = !empty($g['confirm_calls']);
            $announcement = ast_quote((string) ($g['announcement'] ?? ''));
            $musicClass = ast_quote((string) ($g['music_class'] ?? ''));
            if ($musicClass === '' || $musicClass === 'default') {
                $musicClass = $code;
            }
            $cidPrefix = (string) ($g['cid_prefix'] ?? '');
            $skipBusy = !empty($g['skip_busy']);
            $ringRecord = array_key_exists('record', $g) ? !empty($g['record']) : $record;
            $recordFormat = strtolower((string) ($g['record_format'] ?? 'wav'));
            if (!in_array($recordFormat, ['wav', 'gsm', 'ulaw'], true)) {
                $recordFormat = 'wav';
            }
            
            $internal .= "exten => {$num},1,Set(ASTERA_SKIP_RECORD=1)\n";
            $internal .= " same => n,Set(ASTERA_RECORD_DEST={$num})\n";
            $internal .= " same => n,Set(ASTERA_RECORD_DIRECTION=\${IF(\$[\"\${ASTERA_RECORD_DIRECTION}\" = \"\"]?dahili:\${ASTERA_RECORD_DIRECTION})})\n";
            $internal .= " same => n,Set(ASTERA_RECORD_TYPE=ringgroup)\n";
            $internal .= " same => n,Set(ASTERA_RECORD_INTERNAL={$num})\n";
            $internal .= " same => n,Set(ASTERA_RECORD_EXTERNAL=\${IF(\$[\"\${ASTERA_RECORD_EXTERNAL}\" = \"\"]?\${CALLERID(num)}:\${ASTERA_RECORD_EXTERNAL})})\n";
            $internal .= " same => n,Gosub({$sub},s,1)\n";
            $internal .= " same => n,Set(CHANNEL(language)={$language})\n";
            if ($ringRecord) {
                $internal .= " same => n,Set(ASTERA_RECORD_INTERNAL=\${FILTER(0-9A-Za-z_,\${ASTERA_RECORD_INTERNAL})})\n";
                $internal .= " same => n,Set(ASTERA_RECORD_EXTERNAL=\${FILTER(0-9A-Za-z_,\${ASTERA_RECORD_EXTERNAL})})\n";
                $internal .= " same => n,Set(ASTERA_MON_DIR={$monDir}/\${STRFTIME(\${EPOCH},,%Y)}/\${STRFTIME(\${EPOCH},,%m)}/\${STRFTIME(\${EPOCH},,%d)})\n";
                $internal .= " same => n,System(/bin/mkdir -p \${ASTERA_MON_DIR})\n";
                $internal .= " same => n,Set(ASTERA_RECORD_FILE=\${STRFTIME(\${EPOCH},,%Y%m%d-%H%M%S)}-yon_\${ASTERA_RECORD_DIRECTION}-tip_ringgroup-dahili_\${ASTERA_RECORD_INTERNAL}-numara_\${ASTERA_RECORD_EXTERNAL}-uid_\${UNIQUEID}.{$recordFormat})\n";
                $internal .= " same => n,Set(CDR(recordingfile)=\${STRFTIME(\${EPOCH},,%Y)}/\${STRFTIME(\${EPOCH},,%m)}/\${STRFTIME(\${EPOCH},,%d)}/\${ASTERA_RECORD_FILE})\n";
                $internal .= " same => n,MixMonitor(\${ASTERA_MON_DIR}/\${ASTERA_RECORD_FILE},b)\n";
            }
            
            // CID prefix
            if ($cidPrefix !== '') {
                $safeCid = ast_quote($cidPrefix);
                $internal .= " same => n,Set(CALLERID(name)={$safeCid}\${CALLERID(name)})\n";
            }
            
            // Announcement
            if ($announcement !== '') {
                $internal .= " same => n,Playback({$announcement})\n";
            }
            
            // Music on hold
            if ($musicClass !== '"default"') {
                $internal .= " same => n,Set(CHANNEL(musicclass)={$musicClass})\n";
            }
            
            // Build dial options
            $dialOpts = "tTb({$sub}^transfer-preserve^1)";
            if ($confirmCalls) {
                // A() option requires pressing 1 to accept the call
                // The announcement can be empty or a file to play
                $confirmFile = $announcement !== '' ? $announcement : '';
                $dialOpts .= 'A(' . $confirmFile . ')';
            }
            if ($skipBusy) {
                $dialOpts .= 'j';
            }
            
            // Dial strategy
            if (($g['strategy'] ?? 'ringall') === 'hunt') {
                // Hunt: try each member sequentially
                foreach ($sips as $idx => $target) {
                    $internal .= " same => n,Dial({$target},{$ringTime},{$dialOpts})\n";
                    $internal .= " same => n,GotoIf(\$[\"\${DIALSTATUS}\" = \"ANSWER\"]?answered)\n";
                }
            } else {
                // Ringall: call all members simultaneously
                $internal .= ' same => n,Dial(' . implode('&', $sips) . ",{$ringTime},{$dialOpts})\n";
            }
            
            // Check timeout destination
            $rgType = dest_allowed((string) ($g['timeout_type'] ?? 'hangup'));
            $rgDest = ast_sanitize_id((string) ($g['timeout_dest'] ?? ''));
            if ($rgType !== 'hangup' || $rgDest !== '') {
                $internal .= " same => n,GotoIf(\$[\"\${DIALSTATUS}\" != \"ANSWER\"]?rgto)\n";
                $internal .= " same => n(answered),Hangup()\n";
                $internal .= " same => n(rgto),NoOp(ring timeout: \${DIALSTATUS})\n";
                $internal .= dest_goto($id, $rgType, $rgDest, $int);
            } else {
                $internal .= " same => n,Hangup()\n";
            }
            $internal .= "\n";
        }

        foreach (store_read('conferences') as $conf) {
            if ((string) ($conf['dept'] ?? '') !== $id) {
                continue;
            }
            $num = ast_sanitize_id((string) ($conf['exten'] ?? ''));
            $confId = ast_sanitize_id((string) ($conf['id'] ?? ''));
            $pin = ast_quote((string) ($conf['pin'] ?? ''));
            $conferenceRecord = !empty($conf['record']);
            $conferenceRecordFormat = strtolower((string) ($conf['record_format'] ?? 'wav'));
            if (!in_array($conferenceRecordFormat, ['wav', 'gsm', 'ulaw'], true)) {
                $conferenceRecordFormat = 'wav';
            }
            if ($num === '' || $confId === '') {
                continue;
            }
            $internal .= "exten => {$num},1,Answer()\n";
            if ($pin !== '') {
                $internal .= " same => n,Authenticate({$pin})\n";
            }
            if ($conferenceRecord) {
                $internal .= " same => n,Set(ASTERA_RECORD_DIRECTION=\${IF(\$[\"\${ASTERA_RECORD_DIRECTION}\" = \"\"]?dahili:\${ASTERA_RECORD_DIRECTION})})\n";
                $internal .= " same => n,Set(ASTERA_RECORD_EXTERNAL=\${FILTER(0-9A-Za-z_,\${CALLERID(num)})})\n";
                $internal .= " same => n,Set(ASTERA_MON_DIR={$monDir}/\${STRFTIME(\${EPOCH},,%Y)}/\${STRFTIME(\${EPOCH},,%m)}/\${STRFTIME(\${EPOCH},,%d)})\n";
                $internal .= " same => n,System(/bin/mkdir -p \${ASTERA_MON_DIR})\n";
                $internal .= " same => n,Set(ASTERA_RECORD_FILE=\${STRFTIME(\${EPOCH},,%Y%m%d-%H%M%S)}-yon_\${ASTERA_RECORD_DIRECTION}-tip_conference-dahili_{$num}-numara_\${ASTERA_RECORD_EXTERNAL}-uid_\${UNIQUEID}.{$conferenceRecordFormat})\n";
                $internal .= " same => n,Set(CDR(recordingfile)=\${STRFTIME(\${EPOCH},,%Y)}/\${STRFTIME(\${EPOCH},,%m)}/\${STRFTIME(\${EPOCH},,%d)}/\${ASTERA_RECORD_FILE})\n";
                $internal .= " same => n,Set(CONFBRIDGE(bridge,template)=web-bridge)\n";
                $internal .= " same => n,Set(CONFBRIDGE(bridge,record_conference)=yes)\n";
                $internal .= " same => n,Set(CONFBRIDGE(bridge,record_file)=\${ASTERA_MON_DIR}/\${ASTERA_RECORD_FILE})\n";
                $internal .= " same => n,Set(CONFBRIDGE(bridge,record_file_timestamp)=no)\n";
                $internal .= " same => n,Set(CONFBRIDGE(bridge,record_file_append)=no)\n";
                $internal .= " same => n,ConfBridge({$confId},,web-user)\n";
            } else {
                $internal .= " same => n,ConfBridge({$confId},web-bridge,web-user)\n";
            }
            $internal .= " same => n,Hangup()\n\n";
        }

        foreach (store_read('pagings') as $pg) {
            if ((string) ($pg['dept'] ?? '') !== $id) {
                continue;
            }
            $num = ast_sanitize_id((string) ($pg['exten'] ?? ''));
            if ($num === '') {
                continue;
            }
            $sips = [];
            foreach ((array) ($pg['members'] ?? []) as $member) {
                $sip = member_sip($id, (string) $member);
                if ($sip !== '') {
                    $sips[] = 'PJSIP/' . $sip;
                }
            }
            if (!$sips) {
                continue;
            }
            $opt = !empty($pg['duplex']) ? 'di' : 'i';
            $internal .= "exten => {$num},1,Answer()\n";
            $internal .= ' same => n,Page(' . implode('&', $sips) . ",{$opt})\n";
            $internal .= " same => n,Hangup()\n\n";
        }

        foreach (store_read('disas') as $disa) {
            if ((string) ($disa['dept'] ?? '') !== $id) {
                continue;
            }
            $num = ast_sanitize_id((string) ($disa['exten'] ?? ''));
            $did = ast_sanitize_id((string) ($disa['id'] ?? ''));
            if ($num === '' || $did === '') {
                continue;
            }
            $internal .= "exten => {$num},1,Goto(disa-{$did},s,1)\n\n";
        }

        $outbound = '';
        foreach (store_read('outbound') as $route) {
            if ((string) ($route['dept'] ?? '') !== $id) {
                continue;
            }
            $pattern = ast_quote((string) ($route['pattern'] ?? ''));
            $trunk = ast_sanitize_id((string) ($route['trunk'] ?? ''));
            if ($pattern === '' || $trunk === '') {
                continue;
            }
            $strip = max(0, (int) ($route['strip'] ?? 0));
            $prefix = ast_quote((string) ($route['prefix'] ?? ''));
            $name = ast_quote((string) ($route['name'] ?? $pattern));
            $dialnum = $strip > 0 ? '${EXTEN:' . $strip . '}' : '${EXTEN}';
            if ($prefix !== '') {
                $dialnum = $prefix . $dialnum;
            }
            $outbound .= "exten => {$pattern},1,Set(OUTNUM={$dialnum})\n";
            $outbound .= " same => n,Set(OUTNUM=\${FILTER(0-9,\${OUTNUM})})\n";
            $outbound .= " same => n,Set(CDR(cnum)=\${CALLERID(num)})\n";
            $outbound .= " same => n,Set(CDR(cnam)=\${CALLERID(name)})\n";
            $outbound .= " same => n,Set(CDR(outbound_cnum)=\${IF(\$[\"\${ASTERA_FALLBACK_FROM}\" != \"\"]?\${ASTERA_FALLBACK_FROM}:\${CALLERID(num)})})\n";
            $outbound .= " same => n,Set(CDR(outbound_cnam)=\${IF(\$[\"\${ASTERA_FALLBACK_NAME}\" != \"\"]?\${ASTERA_FALLBACK_NAME}:\${CALLERID(name)})})\n";
            $outbound .= " same => n,Set(ASTERA_RECORD_DEST=\${OUTNUM})\n";
            $outbound .= " same => n,Set(ASTERA_RECORD_DIRECTION=giden)\n";
            $outbound .= " same => n,Set(ASTERA_RECORD_TYPE=disarama)\n";
            $outbound .= " same => n,Set(ASTERA_RECORD_INTERNAL=\${IF(\$[\"\${ASTERA_FALLBACK_FROM}\" != \"\"]?\${ASTERA_FALLBACK_FROM}:\${CALLERID(num)})})\n";
            $outbound .= " same => n,Set(ASTERA_RECORD_EXTERNAL=\${OUTNUM})\n";
            $outbound .= " same => n,Set(ASTERA_RECORD_ENABLED=\${DB(REC/{$code}/\${CALLERID(num)})})\n";
            $outbound .= " same => n,Set(ASTERA_RECORD_FORMAT=\${DB(RECFMT/{$code}/\${CALLERID(num)})})\n";
            $outbound .= " same => n,Gosub({$sub},s,1)\n";
            $outbound .= " same => n,ExecIf(\$[\"\${ASTERA_FALLBACK_TYPE}\" = \"external\"]?Set(CDR(userfield)={$code}|FALLBACK_EXTERNAL|\${ASTERA_FALLBACK_FROM}|\${ASTERA_FALLBACK_DEST}|\${ASTERA_FALLBACK_REASON}))\n";
            $outbound .= " same => n,Set(__ASTERA_TRANSFER_DISPLAY=\${OUTNUM})\n";
            $outbound .= " same => n,Set(ASTERA_TRANSFER_CRM_NUM=\${IF(\$[\${LEN(\${OUTNUM})}=12 & \"\${OUTNUM:0:2}\"=\"90\"]?\${OUTNUM:2}:\${OUTNUM})})\n";
            $outbound .= " same => n,Set(ASTERA_TRANSFER_CRM_NUM=\${IF(\$[\${LEN(\${ASTERA_TRANSFER_CRM_NUM})}=11 & \"\${ASTERA_TRANSFER_CRM_NUM:0:1}\"=\"0\"]?\${ASTERA_TRANSFER_CRM_NUM:1}:\${ASTERA_TRANSFER_CRM_NUM})})\n";
            $outbound .= " same => n,Set(ASTERA_TRANSFER_CRM_LAST10=\${IF(\$[\${LEN(\${OUTNUM})}>=10]?\${OUTNUM:-10}:\${OUTNUM})})\n";
            $outbound .= " same => n,Set(ASTERA_TRANSFER_CRM_NAME=\${DB(CRM/{$code}/\${ASTERA_TRANSFER_CRM_NUM})})\n";
            $outbound .= " same => n,ExecIf(\$[\"\${ASTERA_TRANSFER_CRM_NAME}\" = \"\" & \"\${ASTERA_TRANSFER_CRM_LAST10}\" != \"\${ASTERA_TRANSFER_CRM_NUM}\"]?Set(ASTERA_TRANSFER_CRM_NAME=\${DB(CRM/{$code}/\${ASTERA_TRANSFER_CRM_LAST10})}))\n";
            $outbound .= " same => n,Set(__ASTERA_TRANSFER_NAME=\${IF(\$[\"\${ASTERA_TRANSFER_CRM_NAME}\" != \"\"]?\${ASTERA_TRANSFER_CRM_NAME}:\${OUTNUM})})\n";
            $outbound .= " same => n,Set(DB(ATXFER/\${CHANNEL(linkedid)})=\${OUTNUM})\n";
            $outbound .= " same => n,Set(DB(ATXFERNAME/\${CHANNEL(linkedid)})=\${ASTERA_TRANSFER_NAME})\n";
            $pin = ast_sanitize_id((string) ($route['pin'] ?? ''));
            if ($pin !== '') {
                $outbound .= " same => n,Authenticate({$pin})\n";
            }
            $trunkRow = find_by('trunks', 'id', $trunk);
            $trunkCid = pstn_digits((string) (($trunkRow['from_user'] ?? '') ?: ($trunkRow['username'] ?? '')));
            if ($trunkCid === '') {
                $trunkCid = pstn_digits($cid);
            }
            if ($trunkCid !== '') {
                $outbound .= " same => n,Set(CALLERID(num)={$trunkCid})\n";
            }
            $outbound .= " same => n,NoOp(Giden {$code} {$name})\n";
            $outbound .= " same => n,Dial(PJSIP/\${OUTNUM}@{$trunk},60,tT)\n";
            $outbound .= " same => n,Hangup()\n\n";
        }
        if ($outbound === '') {
            $outbound = "; giden rota yok — dış arama kapalı\n";
        } else {
            $outbound .= "exten => h,1,NoOp(Kontrollu transfer gecici bilgisi temizleniyor)\n";
            $outbound .= " same => n,NoOp(\${DB_DELETE(ATXFER/\${CHANNEL(linkedid)})})\n\n";
            $outbound .= " same => n,NoOp(\${DB_DELETE(ATXFERNAME/\${CHANNEL(linkedid)})})\n\n";
        }

        $inbound = '';
        foreach (store_read('inbound') as $route) {
            if ((string) ($route['dept'] ?? '') !== $id) {
                continue;
            }
            $did = ast_quote((string) ($route['did'] ?? '_X.'));
            $destType = (string) ($route['dest_type'] ?? 'extension');
            $dest = ast_sanitize_id((string) ($route['dest'] ?? ''));
            $name = ast_quote((string) ($route['name'] ?? $did));
            if ($did === '' || ($dest === '' && $destType !== 'hangup')) {
                continue;
            }
            $inbound .= "exten => {$did},1,NoOp(Gelen {$code} {$name})\n";
            $inbound .= dest_goto($id, $destType, $dest, $int);
        }
        if ($inbound === '') {
            $inbound = "exten => _X.,1,NoOp({$code} gelen kural yok)\n same => n,Hangup()\n";
        }

        $apps = generate_app_contexts($id, $code, $int);
        $apps = generate_app_contexts($id, $code, $int);
        $park = parking_for($id);
        $parkExt = ast_sanitize_id((string) ($park['parkext'] ?? '700')) ?: '700';
        $parkedCtx = 'parked-' . $code;

        $featExtra = generate_feat_extras($id, $code, $int, $outC, $parkExt);

        $blocks[] = <<<CONF
;========== firma {$code} — diğer firmalara include YOK ==========
[{$sub}]
{$subBody}
[{$from}]
include => {$int}
include => {$outC}
include => {$feat}
include => {$parkedCtx}

[{$int}]
{$internal}
[{$feat}]
exten => *43,1,Answer()
 same => n,Echo()
 same => n,Hangup()

exten => *65,1,Answer()
 same => n,SayUnixTime()
 same => n,Hangup()

exten => *72,1,Answer()
 same => n,Read(CFnum,vm-enter-num-to-call,8,,1,4)
 same => n,Set(DB(CF/{$code}/\${CALLERID(num)})=\${CFnum})
 same => n,Playback(beep)
 same => n,Hangup()

exten => *73,1,Answer()
 same => n,NoOp(\${DB_DELETE(CF/{$code}/\${CALLERID(num)})})
 same => n,Playback(beep)
 same => n,Hangup()

exten => *78,1,Answer()
 same => n,Set(DB(DND/{$code}/\${CALLERID(num)})=YES)
 same => n,Playback(beep)
 same => n,Hangup()

exten => *79,1,Answer()
 same => n,NoOp(\${DB_DELETE(DND/{$code}/\${CALLERID(num)})})
 same => n,Playback(beep)
 same => n,Hangup()

exten => *8,1,Pickup()
 same => n,Hangup()

exten => *97,1,VoiceMailMain(\${CALLERID(num)}@{$code})
 same => n,Hangup()

exten => *98,1,VoiceMailMain(@{$code})
 same => n,Hangup()

exten => *45,1,PauseQueueMember(,PJSIP/\${CHANNEL(endpoint)})
 same => n,Playback(beep)
 same => n,Hangup()

exten => *46,1,UnpauseQueueMember(,PJSIP/\${CHANNEL(endpoint)})
 same => n,Playback(beep)
 same => n,Hangup()

exten => *411,1,Answer()
 same => n,Directory({$code},{$int},f)
 same => n,Hangup()

exten => {$parkExt},1,Park(parking-{$code})
 same => n,Hangup()

{$featExtra}
[{$outC}]
{$outbound}
[{$inC}]
{$inbound}
[{$atxfer}]
exten => _X.,1,NoOp(Kontrollu transfer hedefi \${EXTEN})
 same => n,Set(ASTERA_TRANSFER_CACHE_KEY=\${CHANNEL(linkedid)})
 same => n,Set(ASTERA_TRANSFER_DB_DISPLAY=\${DB_DELETE(ATXFER/\${ASTERA_TRANSFER_CACHE_KEY})})
 same => n,Set(ASTERA_TRANSFER_DB_NAME=\${DB_DELETE(ATXFERNAME/\${ASTERA_TRANSFER_CACHE_KEY})})
 same => n,Set(ASTERA_TRANSFER_DISPLAY=\${IF(\$["\${ASTERA_TRANSFER_DB_DISPLAY}" != ""]?\${ASTERA_TRANSFER_DB_DISPLAY}:\${ASTERA_TRANSFER_DISPLAY})})
 same => n,Set(ASTERA_TRANSFER_NAME=\${IF(\$["\${ASTERA_TRANSFER_DB_NAME}" != ""]?\${ASTERA_TRANSFER_DB_NAME}:\${ASTERA_TRANSFER_NAME})})
 same => n,Set(__ASTERA_RECORD_EXTERNAL=\${ASTERA_TRANSFER_DISPLAY})
 same => n,Set(__ASTERA_TRANSFER_AUDIT={$code}|ATXFER|\${ASTERA_TRANSFER_CACHE_KEY}|\${ASTERA_TRANSFER_DISPLAY}|\${EXTEN}|\${CHANNEL(endpoint)})
 same => n,Set(CDR(userfield)=\${ASTERA_TRANSFER_AUDIT})
 same => n,Set(CDR(transfered)=Y)
 same => n,Set(CDR(cnum)=\${ASTERA_TRANSFER_DISPLAY})
 same => n,ExecIf(\$["\${ASTERA_TRANSFER_DISPLAY}" != ""]?Set(CALLERID(num)=\${ASTERA_TRANSFER_DISPLAY}))
 same => n,ExecIf(\$["\${ASTERA_TRANSFER_NAME}" != ""]?Set(CALLERID(name)=\${ASTERA_TRANSFER_NAME}))
 same => n,ExecIf(\$["\${ASTERA_TRANSFER_DISPLAY}" != ""]?Set(CONNECTEDLINE(num,i)=\${ASTERA_TRANSFER_DISPLAY}))
 same => n,ExecIf(\$["\${ASTERA_TRANSFER_NAME}" != ""]?Set(CONNECTEDLINE(name,i)=\${ASTERA_TRANSFER_NAME}))
 same => n,Goto({$from},\${EXTEN},1)

{$apps}
CONF;
    }

    $blocks[] = generate_from_pstn();

    $body = implode("\n", $blocks);
    return <<<CONF
; ASTERA dialplan — her firma ayrı context
[general]
static=yes
writeprotect=no
clearglobalvars=no

[globals]

{$body}
CONF;
}

function generate_queues(): string
{
    $out = "; ASTERA kuyruklar\n\n";
    foreach (store_read('queues') as $q) {
        $id = ast_sanitize_id((string) ($q['id'] ?? ''));
        $dept = ast_sanitize_id((string) ($q['dept'] ?? ''));
        if ($id === '' || $dept === '') {
            continue;
        }
        $strategy = ast_sanitize_id((string) ($q['strategy'] ?? 'ringall')) ?: 'ringall';
        $timeout = max(5, (int) ($q['timeout'] ?? 20));
        $retry = max(1, min(60, (int) ($q['retry'] ?? 5)));
        $wrap = max(0, (int) ($q['wrapuptime'] ?? 5));
        $maxlen = max(0, (int) ($q['maxlen'] ?? 0));
        $service = max(1, min(86400, (int) ($q['servicelevel'] ?? 60)));
        $weight = max(0, min(100, (int) ($q['weight'] ?? 0)));
        $memberDelay = max(0, min(60, (int) ($q['memberdelay'] ?? 0)));
        $joinempty = in_array(($q['joinempty'] ?? 'yes'), ['yes', 'no', 'strict'], true) ? $q['joinempty'] : 'yes';
        $leaveempty = in_array(($q['leavewhenempty'] ?? 'no'), ['yes', 'no', 'strict'], true) ? $q['leavewhenempty'] : 'no';
        $autopause = in_array(($q['autopause'] ?? 'no'), ['yes', 'no', 'all'], true) ? $q['autopause'] : 'no';
        $announceFrequency = max(0, min(3600, (int) ($q['announce_frequency'] ?? 60)));
        $minAnnounceFrequency = max(0, min(3600, (int) ($q['min_announce_frequency'] ?? 15)));
        $positionLimit = max(0, min(999, (int) ($q['announce_position_limit'] ?? 0)));
        $holdtime = in_array(($q['announce_holdtime'] ?? 'no'), ['yes', 'no', 'once'], true) ? $q['announce_holdtime'] : 'no';
        $round = in_array((int) ($q['announce_round_seconds'] ?? 10), [0, 5, 10, 15, 20, 30], true)
            ? (int) ($q['announce_round_seconds'] ?? 10)
            : 10;
        $periodic = ast_quote((string) ($q['periodic_announce'] ?? ''));
        $periodicFrequency = max(0, min(86400, (int) ($q['periodic_announce_frequency'] ?? 60)));
        $moh = ast_sanitize_id((string) ($q['musicclass'] ?? dept_code_of($dept))) ?: ast_sanitize_id(dept_code_of($dept));
        $out .= "[{$id}]\n";
        $out .= "strategy={$strategy}\n";
        $out .= "timeout={$timeout}\n";
        $out .= "retry={$retry}\n";
        $out .= "wrapuptime={$wrap}\n";
        $out .= "maxlen={$maxlen}\n";
        $out .= "servicelevel={$service}\n";
        $out .= "weight={$weight}\n";
        $out .= "memberdelay={$memberDelay}\n";
        $out .= "joinempty={$joinempty}\n";
        $out .= "leavewhenempty={$leaveempty}\n";
        $out .= "ringinuse=" . (!empty($q['ringinuse']) ? 'yes' : 'no') . "\n";
        $out .= "autofill=" . (($q['autofill'] ?? true) ? 'yes' : 'no') . "\n";
        $out .= "autopause={$autopause}\n";
        $out .= "autopausebusy=" . (!empty($q['autopausebusy']) ? 'yes' : 'no') . "\n";
        $out .= "autopausenoanswer=" . (!empty($q['autopausenoanswer']) ? 'yes' : 'no') . "\n";
        $out .= "autopauseunavail=" . (!empty($q['autopauseunavailable']) ? 'yes' : 'no') . "\n";
        $out .= "autopausedelay=" . max(0, min(3600, (int) ($q['autopausedelay'] ?? 0))) . "\n";
        $out .= "timeoutrestart=" . (!empty($q['timeoutrestart']) ? 'yes' : 'no') . "\n";
        $out .= "shared_lastcall=" . (!empty($q['shared_lastcall']) ? 'yes' : 'no') . "\n";
        $out .= "setinterfacevar=yes\n";
        $out .= "setqueueentryvar=yes\n";
        $out .= "setqueuevar=yes\n";
        $out .= "musicclass={$moh}\n";
        $out .= "announce-position=" . (!empty($q['announce_position']) ? 'yes' : 'no') . "\n";
        $out .= "announce-frequency={$announceFrequency}\n";
        $out .= "min-announce-frequency={$minAnnounceFrequency}\n";
        $out .= "announce-holdtime={$holdtime}\n";
        $out .= "announce-round-seconds={$round}\n";
        if ($positionLimit > 0) {
            $out .= "announce-position-limit={$positionLimit}\n";
        }
        if ($periodic !== '') {
            $out .= "periodic-announce={$periodic}\n";
            $out .= "periodic-announce-frequency={$periodicFrequency}\n";
            $out .= "relative-periodic-announce=" . (($q['relative_periodic_announce'] ?? true) ? 'yes' : 'no') . "\n";
        }
        $out .= "announce-to-first-user=" . (!empty($q['announce_to_first_user']) ? 'yes' : 'no') . "\n";
        $out .= "reportholdtime=" . (!empty($q['reportholdtime']) ? 'yes' : 'no') . "\n";
        foreach ((array) ($q['members'] ?? []) as $member) {
            $sip = member_sip($dept, (string) $member);
            if ($sip !== '') {
                $out .= "member => PJSIP/{$sip}\n";
            }
        }
        $out .= "\n";
    }
    return $out;
}

function turkey_religious_holidays(): array
{
    return [
        'full' => [
            '2025-03-30', '2025-03-31', '2025-04-01', '2025-06-06', '2025-06-07', '2025-06-08', '2025-06-09',
            '2026-03-20', '2026-03-21', '2026-03-22', '2026-05-27', '2026-05-28', '2026-05-29', '2026-05-30',
            '2027-03-09', '2027-03-10', '2027-03-11', '2027-05-16', '2027-05-17', '2027-05-18', '2027-05-19',
            '2028-02-26', '2028-02-27', '2028-02-28', '2028-05-05', '2028-05-06', '2028-05-07', '2028-05-08',
            '2029-02-14', '2029-02-15', '2029-02-16', '2029-04-24', '2029-04-25', '2029-04-26', '2029-04-27',
            '2030-02-04', '2030-02-05', '2030-02-06', '2030-04-13', '2030-04-14', '2030-04-15', '2030-04-16',
            '2031-01-24', '2031-01-25', '2031-01-26', '2031-04-02', '2031-04-03', '2031-04-04', '2031-04-05',
            '2032-01-14', '2032-01-15', '2032-01-16', '2032-03-22', '2032-03-23', '2032-03-24', '2032-03-25',
            '2033-01-02', '2033-01-03', '2033-01-04', '2033-03-11', '2033-03-12', '2033-03-13', '2033-03-14',
            '2033-12-23', '2033-12-24', '2033-12-25',
            '2034-03-01', '2034-03-02', '2034-03-03', '2034-03-04', '2034-12-12', '2034-12-13', '2034-12-14',
            '2035-02-18', '2035-02-19', '2035-02-20', '2035-02-21', '2035-12-01', '2035-12-02', '2035-12-03',
        ],
        'half' => [
            '2025-03-29', '2025-06-05', '2026-03-19', '2026-05-26', '2027-03-08', '2027-05-15',
            '2028-02-25', '2028-05-04', '2029-02-13', '2029-04-23', '2030-02-03', '2030-04-12',
            '2031-01-23', '2031-04-01', '2032-01-13', '2032-03-21', '2033-01-01', '2033-03-10',
            '2033-12-22', '2034-02-28', '2034-12-11', '2035-02-17', '2035-11-30',
        ],
    ];
}

function time_exception_condition(string $date, string $time, string $timezone): string
{
    [$start, $end] = array_pad(explode('-', $time, 2), 2, '23:59');
    $startNumber = (int) str_replace(':', '', $start);
    $endNumber = (int) str_replace(':', '', $end);
    $dateValue = '${STRFTIME(${EPOCH},' . $timezone . ',%Y-%m-%d)}';
    $timeValue = '${STRFTIME(${EPOCH},' . $timezone . ',%H%M)}';
    $timeCheck = $endNumber >= $startNumber
        ? "{$timeValue} >= {$startNumber} & {$timeValue} <= {$endNumber}"
        : "({$timeValue} >= {$startNumber} | {$timeValue} <= {$endNumber})";
    return '$["' . $dateValue . '" = "' . $date . '" & ' . $timeCheck . ']';
}

function generate_app_contexts(string $deptId, string $code, string $intCtx): string
{
    $out = '';
    foreach (store_read('ivrs') as $ivr) {
        if ((string) ($ivr['dept'] ?? '') !== $deptId) {
            continue;
        }
        $iid = ast_sanitize_id((string) ($ivr['id'] ?? ''));
        if ($iid === '') {
            continue;
        }
        $sound = ast_quote((string) ($ivr['sound'] ?? 'hello-world')) ?: 'hello-world';
        $language = ast_sanitize_id((string) ($ivr['language'] ?? 'en')) ?: 'en';
        $timeoutType = (string) ($ivr['timeout_type'] ?? 'hangup');
        $timeoutDest = ast_sanitize_id((string) ($ivr['timeout_dest'] ?? ''));
        $ctx = 'ivr-' . $iid;
        $out .= "[{$ctx}]\n";
        $out .= "exten => s,1,Answer()\n";
        $out .= " same => n,Set(CHANNEL(language)={$language})\n";
        $out .= " same => n,Set(TIMEOUT(digit)=3)\n";
        $out .= " same => n,Set(TIMEOUT(response)=6)\n";
        $out .= " same => n(loop),Background({$sound})\n";
        $out .= " same => n,WaitExten(6)\n";
        $digits = $ivr['digits'] ?? [];
        if (is_array($digits)) {
            foreach ($digits as $digit => $info) {
                $digit = ast_sanitize_id((string) $digit);
                if ($digit === '') {
                    continue;
                }
                $t = (string) ($info['type'] ?? 'hangup');
                $d = ast_sanitize_id((string) ($info['dest'] ?? ''));
                $out .= "exten => {$digit},1,NoOp(IVR {$digit})\n";
                $out .= dest_goto($deptId, $t, $d, $intCtx);
            }
        }
        if ($ivr['direct_dial'] ?? true) {
            $blocked = array_fill_keys(array_map(
                static fn($number) => ast_sanitize_id((string) $number),
                (array) ($ivr['blocked_extensions'] ?? [])
            ), true);
            $blockedType = (string) ($ivr['blocked_type'] ?? 'hangup');
            $blockedDest = ast_sanitize_id((string) ($ivr['blocked_dest'] ?? ''));
            foreach (store_read('extensions') as $extension) {
                if ((string) ($extension['dept'] ?? '') !== $deptId) {
                    continue;
                }
                $extensionNumber = ast_sanitize_id((string) ($extension['exten'] ?? ''));
                if ($extensionNumber === '' || isset($digits[$extensionNumber])) {
                    continue;
                }
                if (isset($blocked[$extensionNumber])) {
                    $out .= "exten => {$extensionNumber},1,NoOp(IVR yasakli dahili {$extensionNumber})\n";
                    $out .= dest_goto($deptId, $blockedType, $blockedDest, $intCtx);
                    continue;
                }
                $out .= "exten => {$extensionNumber},1,Goto({$intCtx},{$extensionNumber},1)\n";
            }
        }
        $out .= "exten => i,1,Playback(invalid)\n same => n,Goto(s,loop)\n";
        $out .= "exten => t,1,NoOp(IVR timeout)\n";
        $out .= dest_goto($deptId, $timeoutType, $timeoutDest, $intCtx);
        $out .= "\n";
    }

    foreach (store_read('timeconditions') as $tc) {
        if ((string) ($tc['dept'] ?? '') !== $deptId) {
            continue;
        }
        $tid = ast_sanitize_id((string) ($tc['id'] ?? ''));
        if ($tid === '') {
            continue;
        }
        $timezone = (string) ($tc['timezone'] ?? 'Europe/Istanbul');
        if (!in_array($timezone, ['Europe/Istanbul', 'UTC', 'Europe/London', 'Europe/Berlin'], true)) {
            $timezone = 'Europe/Istanbul';
        }
        $rules = $tc['rules'] ?? [];
        if (!is_array($rules) || !$rules) {
            [$legacyStart, $legacyEnd] = array_pad(explode('-', (string) ($tc['time'] ?? '09:00-18:00'), 2), 2, '18:00');
            $legacyDays = (string) ($tc['days'] ?? 'mon-fri');
            $rules = [[
                'start' => $legacyStart,
                'end' => $legacyEnd,
                'days' => $legacyDays === 'mon-fri'
                    ? ['mon', 'tue', 'wed', 'thu', 'fri']
                    : ($legacyDays === 'sat-sun' ? ['sat', 'sun'] : preg_split('/[,&]+/', $legacyDays)),
            ]];
        }
        $trueT = (string) ($tc['true_type'] ?? 'hangup');
        $trueD = ast_sanitize_id((string) ($tc['true_dest'] ?? ''));
        $falseT = (string) ($tc['false_type'] ?? 'hangup');
        $falseD = ast_sanitize_id((string) ($tc['false_dest'] ?? ''));
        $ctx = 'tc-' . $tid;
        $out .= "[{$ctx}]\n";
        $out .= "exten => s,1,NoOp(Gelismis zaman kosulu {$tid} - {$timezone})\n";
        foreach ((array) ($tc['exceptions'] ?? []) as $exception) {
            $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($exception['from'] ?? ''));
            $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($exception['to'] ?? $exception['from'] ?? ''));
            if (!$from || !$to || $to < $from || $from->diff($to)->days > 366) {
                continue;
            }
            $label = ($exception['state'] ?? 'closed') === 'open' ? 'open' : 'closed';
            $time = (string) ($exception['time'] ?? '00:00-23:59');
            for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
                $condition = time_exception_condition($date->format('Y-m-d'), $time, $timezone);
                $out .= " same => n,GotoIf({$condition}?{$label})\n";
            }
        }
        $turkeyHolidays = turkey_religious_holidays();
        if ($tc['turkey_holidays'] ?? false) {
            foreach ([
                ['1', 'jan'], ['23', 'apr'], ['1', 'may'], ['19', 'may'],
                ['15', 'jul'], ['30', 'aug'], ['29', 'oct'],
            ] as [$monthDay, $month]) {
                $out .= " same => n,GotoIfTime(00:00-23:59,*,{$monthDay},{$month},{$timezone}?closed)\n";
            }
            foreach ($turkeyHolidays['full'] as $date) {
                $condition = time_exception_condition($date, '00:00-23:59', $timezone);
                $out .= " same => n,GotoIf({$condition}?closed)\n";
            }
        }
        if ($tc['turkey_half_days'] ?? false) {
            $out .= " same => n,GotoIfTime(13:00-23:59,*,28,oct,{$timezone}?closed)\n";
            foreach ($turkeyHolidays['half'] as $date) {
                $condition = time_exception_condition($date, '13:00-23:59', $timezone);
                $out .= " same => n,GotoIf({$condition}?closed)\n";
            }
        }
        foreach ($rules as $rule) {
            $start = preg_match('/^\d{2}:\d{2}$/', (string) ($rule['start'] ?? '')) ? $rule['start'] : '09:00';
            $end = preg_match('/^\d{2}:\d{2}$/', (string) ($rule['end'] ?? '')) ? $rule['end'] : '18:00';
            $days = array_values(array_intersect(
                ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
                (array) ($rule['days'] ?? [])
            ));
            if ($days) {
                $out .= " same => n,GotoIfTime({$start}-{$end}," . implode('&', $days) . ",*,*,{$timezone}?open)\n";
            }
        }
        $out .= " same => n,Goto(closed)\n";
        $out .= " same => n(open),NoOp(mesai)\n";
        $out .= dest_goto($deptId, $trueT, $trueD, $intCtx);
        $out .= "exten => s,n(closed),NoOp(mesai disi)\n";
        $out .= dest_goto($deptId, $falseT, $falseD, $intCtx);
        $out .= "\n";
    }

    foreach (store_read('announcements') as $ann) {
        if ((string) ($ann['dept'] ?? '') !== $deptId) {
            continue;
        }
        $aid = ast_sanitize_id((string) ($ann['id'] ?? ''));
        if ($aid === '') {
            continue;
        }
        $sound = ast_quote((string) ($ann['sound'] ?? 'hello-world')) ?: 'hello-world';
        $language = ast_sanitize_id((string) ($ann['language'] ?? 'en')) ?: 'en';
        $t = (string) ($ann['dest_type'] ?? 'hangup');
        $d = ast_sanitize_id((string) ($ann['dest'] ?? ''));
        $ctx = 'ann-' . $aid;
        $out .= "[{$ctx}]\n";
        $out .= "exten => s,1,Answer()\n";
        $out .= " same => n,Set(CHANNEL(language)={$language})\n";
        $out .= " same => n,Playback({$sound})\n";
        $out .= dest_goto($deptId, $t, $d, $intCtx);
        $out .= "\n";
    }

    foreach (store_read('flows') as $flow) {
        if ((string) ($flow['dept'] ?? '') !== $deptId) {
            continue;
        }
        $fid = ast_sanitize_id((string) ($flow['id'] ?? ''));
        if ($fid === '') {
            continue;
        }
        $trueT = dest_allowed((string) ($flow['true_type'] ?? 'hangup'));
        $trueD = ast_sanitize_id((string) ($flow['true_dest'] ?? ''));
        $falseT = dest_allowed((string) ($flow['false_type'] ?? 'hangup'));
        $falseD = ast_sanitize_id((string) ($flow['false_dest'] ?? ''));
        $out .= "[flow-{$fid}]\n";
        $out .= "exten => s,1,GotoIf(\$[\"\${DB(CFLOW/{$code}/{$fid})}\" = \"1\"]?night)\n";
        $out .= " same => n,NoOp(gunduz)\n";
        $out .= dest_goto($deptId, $trueT, $trueD, $intCtx);
        $out .= "exten => s,n(night),NoOp(gece)\n";
        $out .= dest_goto($deptId, $falseT, $falseD, $intCtx);
        $out .= "\n";
    }

    foreach (store_read('disas') as $disa) {
        if ((string) ($disa['dept'] ?? '') !== $deptId) {
            continue;
        }
        $did = ast_sanitize_id((string) ($disa['id'] ?? ''));
        if ($did === '') {
            continue;
        }
        $pin = ast_sanitize_id((string) ($disa['pin'] ?? ''));
        $from = ctx($deptId, 'from');
        $out .= "[disa-{$did}]\n";
        $out .= "exten => s,1,Answer()\n";
        if ($pin !== '') {
            $out .= " same => n,Authenticate({$pin})\n";
        }
        $out .= " same => n,DISA(no-password,{$from})\n";
        $out .= " same => n,Hangup()\n\n";
    }

    foreach (store_read('customs') as $cust) {
        if ((string) ($cust['dept'] ?? '') !== $deptId) {
            continue;
        }
        $cid = ast_sanitize_id((string) ($cust['id'] ?? ''));
        if ($cid === '') {
            continue;
        }
        $ctxName = ast_sanitize_id((string) ($cust['goto_context'] ?? ''));
        $exten = ast_sanitize_id((string) ($cust['goto_exten'] ?? 's')) ?: 's';
        $pri = max(1, (int) ($cust['goto_pri'] ?? 1));
        $sound = ast_quote((string) ($cust['sound'] ?? ''));
        $out .= "[cust-{$cid}]\n";
        $out .= "exten => s,1,NoOp(ozel {$cid})\n";
        if ($sound !== '') {
            $out .= " same => n,Playback({$sound})\n";
        }
        if ($ctxName !== '' && preg_match('/^(int|from|out|in|feat|ivr|tc|ann|flow|disa|cust|parked)-[A-Za-z0-9_-]+$/', $ctxName)) {
            $out .= " same => n,Goto({$ctxName},{$exten},{$pri})\n";
        } else {
            $t = dest_allowed((string) ($cust['dest_type'] ?? 'hangup'));
            $d = ast_sanitize_id((string) ($cust['dest'] ?? ''));
            $out .= dest_goto($deptId, $t, $d, $intCtx);
        }
        $out .= "\n";
    }

    return $out;
}

function generate_voicemail(): string
{
    $byCtx = [];
    foreach (store_read('extensions') as $ext) {
        if (empty($ext['vm_enabled'])) {
            continue;
        }
        $dept = (string) ($ext['dept'] ?? '');
        $num = ast_sanitize_id((string) ($ext['exten'] ?? ''));
        if ($dept === '' || $num === '') {
            continue;
        }
        $vmCtx = dept_code_of($dept);
        $pin = ast_sanitize_id((string) ($ext['vm_pin'] ?? '1234')) ?: '1234';
        $name = ast_quote((string) ($ext['name'] ?? $num));
        $email = ast_quote((string) ($ext['vm_email'] ?? ''));
        $byCtx[$vmCtx][] = "{$num} => {$pin},{$name},{$email}";
    }
    if (!$byCtx) {
        return "; ASTERA voicemail\n";
    }
    $out = "; ASTERA voicemail\n\n";
    foreach ($byCtx as $vmCtx => $lines) {
        $out .= "[{$vmCtx}]\n" . implode("\n", $lines) . "\n\n";
    }
    return $out;
}

function generate_confbridge(): string
{
    return <<<CONF
; ASTERA konferans profilleri
[web-user]
type=user
quiet=yes
announce_user_count=yes
music_on_hold_when_empty=yes
dsp_drop_silence=yes

[web-bridge]
type=bridge
max_members=50
mixing_interval=20

CONF;
}

function parking_for(string $deptId): array
{
    foreach (store_read('parking') as $row) {
        if ((string) ($row['dept'] ?? '') === $deptId) {
            return $row;
        }
    }
    return [
        'parkext' => '700',
        'start' => '701',
        'end' => '709',
        'time' => 45,
    ];
}

function generate_feat_extras(string $deptId, string $code, string $intCtx, string $outCtx, string $parkExt): string
{
    $out = '';
    foreach (store_read('extensions') as $ext) {
        if ((string) ($ext['dept'] ?? '') !== $deptId) {
            continue;
        }
        $num = ast_sanitize_id((string) ($ext['exten'] ?? ''));
        $sip = sip_user($ext);
        if ($num === '' || $sip === '') {
            continue;
        }
        $out .= "exten => *80{$num},1,Page(PJSIP/{$sip},qi)\n same => n,Hangup()\n\n";
    }

    foreach (store_read('flows') as $flow) {
        if ((string) ($flow['dept'] ?? '') !== $deptId) {
            continue;
        }
        $fid = ast_sanitize_id((string) ($flow['id'] ?? ''));
        $feat = ast_quote((string) ($flow['feature'] ?? '*28'));
        if ($fid === '' || $feat === '') {
            continue;
        }
        $out .= "exten => {$feat},1,Answer()\n";
        $out .= " same => n,GotoIf(\$[\"\${DB(CFLOW/{$code}/{$fid})}\" = \"1\"]?off)\n";
        $out .= " same => n,Set(DB(CFLOW/{$code}/{$fid})=1)\n";
        $out .= " same => n,Playback(beep)\n";
        $out .= " same => n,Hangup()\n";
        $out .= " same => n(off),NoOp(\${DB_DELETE(CFLOW/{$code}/{$fid})})\n";
        $out .= " same => n,Playback(beep)\n";
        $out .= " same => n,Hangup()\n\n";
    }

    foreach (store_read('speeddials') as $sd) {
        if ((string) ($sd['dept'] ?? '') !== $deptId) {
            continue;
        }
        $codeSd = ast_sanitize_id((string) ($sd['code'] ?? ''));
        $number = preg_replace('/[^0-9*#]/', '', (string) ($sd['number'] ?? '')) ?? '';
        if ($codeSd === '' || $number === '') {
            continue;
        }
        $star = str_starts_with($codeSd, '*') ? $codeSd : ('*' . $codeSd);
        $out .= "exten => {$star},1,Gosub(" . ctx($deptId, 'sub') . ",s,1)\n";
        if (find_ext($deptId, $number)) {
            $out .= " same => n,Goto({$intCtx},{$number},1)\n\n";
        } else {
            $out .= " same => n,Goto({$outCtx},{$number},1)\n\n";
        }
    }

    return $out;
}

function generate_from_pstn(): string
{
    $exact = '';
    $seen = [];
    $catchall = " same => n,Hangup()\n";
    $blacklistGuard = static function (string $dept): string {
        $code = dept_code_of($dept);
        return " same => n,Set(CHANNEL(accountcode)={$code})\n"
            . " same => n,Set(CDR(accountcode)={$code})\n"
            . " same => n,Set(CDR(userfield)={$code})\n"
            . " same => n,Set(ASTERA_CALLER_NUM=\${FILTER(0-9,\${CALLERID(num)})})\n"
            . " same => n,Set(ASTERA_CRM_NUM=\${IF(\$[\${LEN(\${ASTERA_CALLER_NUM})}=12 & \"\${ASTERA_CALLER_NUM:0:2}\"=\"90\"]?\${ASTERA_CALLER_NUM:2}:\${ASTERA_CALLER_NUM})})\n"
            . " same => n,Set(ASTERA_CRM_NUM=\${IF(\$[\${LEN(\${ASTERA_CRM_NUM})}=11 & \"\${ASTERA_CRM_NUM:0:1}\"=\"0\"]?\${ASTERA_CRM_NUM:1}:\${ASTERA_CRM_NUM})})\n"
            . " same => n,Set(ASTERA_CRM_LAST10=\${IF(\$[\${LEN(\${ASTERA_CALLER_NUM})}>=10]?\${ASTERA_CALLER_NUM:-10}:\${ASTERA_CALLER_NUM})})\n"
            . " same => n,Set(ASTERA_CRM_NAME=\${DB(CRM/{$code}/\${ASTERA_CRM_NUM})})\n"
            . " same => n,ExecIf(\$[\"\${ASTERA_CRM_NAME}\" = \"\" & \"\${ASTERA_CRM_LAST10}\" != \"\${ASTERA_CRM_NUM}\"]?Set(ASTERA_CRM_NAME=\${DB(CRM/{$code}/\${ASTERA_CRM_LAST10})}))\n"
            . " same => n,ExecIf(\$[\"\${ASTERA_CRM_NAME}\" != \"\"]?Set(CALLERID(name)=\${ASTERA_CRM_NAME}))\n"
            . " same => n,ExecIf(\$[\"\${ASTERA_CRM_NAME}\" != \"\"]?Set(CDR(cnam)=\${ASTERA_CRM_NAME}))\n"
            . " same => n,GotoIf(\$[\${DB_EXISTS(BL/{$code}/\${ASTERA_CALLER_NUM})}]?blacklisted)\n";
    };
    $blacklistHangup = static function (string $dept): string {
        $code = dept_code_of($dept);
        return " same => n(blacklisted),NoOp(Kara listedeki arayan reddedildi: \${ASTERA_CALLER_NUM})\n"
            . " same => n,Set(CDR(userfield)={$code}|BLACKLIST|\${ASTERA_CALLER_NUM})\n"
            . " same => n,ForkCDR(e)\n"
            . " same => n,Set(CDR_PROP(disable)=1)\n"
            . " same => n,Hangup(21)\n";
    };
    foreach (store_read('trunks') as $trunk) {
        if (($trunk['enabled'] ?? true) || ($trunk['type'] ?? 'register') !== 'register') {
            continue;
        }
        $numbers = array_unique([
            (string) ($trunk['id'] ?? ''),
            (string) ($trunk['username'] ?? ''),
            (string) ($trunk['from_user'] ?? ''),
        ]);
        foreach ($numbers as $number) {
            foreach (inbound_did_list($number) as $alias) {
                if (isset($seen[$alias])) {
                    continue;
                }
                $seen[$alias] = true;
                $exact .= "exten => {$alias},1,NoOp(Devre disi trunk: {$number})\n";
                $exact .= " same => n,Hangup(21)\n";
            }
        }
    }
    foreach (store_read('inbound') as $route) {
        $dept = (string) ($route['dept'] ?? '');
        if ($dept === '' || !dept_by_id($dept)) {
            continue;
        }
        $did = trim((string) ($route['did'] ?? ''));
        $destType = (string) ($route['dest_type'] ?? 'extension');
        $dest = ast_sanitize_id((string) ($route['dest'] ?? ''));
        $name = ast_quote((string) ($route['name'] ?? $did));
        $int = ctx($dept, 'int');
        if ($did === '' || ($dest === '' && $destType !== 'hangup')) {
            continue;
        }
        $goto = dest_goto($dept, $destType, $dest, $int);
        if (inbound_is_catchall($did)) {
            $catchall = $blacklistGuard($dept) . $goto . $blacklistHangup($dept);
            continue;
        }
        foreach (inbound_did_list($did) as $alias) {
            if (isset($seen[$alias])) {
                continue;
            }
            $seen[$alias] = true;
            $exact .= "exten => {$alias},1,NoOp(Gelen {$dept} {$name})\n";
            $exact .= $blacklistGuard($dept) . $goto . $blacklistHangup($dept);
        }
    }

    return <<<CONF
;========== dış hat gelen (tüm firmalar) ==========
[from-pstn-did]
exten => s,1,Set(TOHDR=\${PJSIP_HEADER(read,To)})
 same => n,Set(TODID=\${CUT(CUT(TOHDR,<,2),@,1)})
 same => n,Set(TODID=\${FILTER(0-9,\${TODID})})
 same => n,Set(DID=\${IF(\$["\${TODID}" != ""]?\${TODID}:\${FILTER(0-9,\${EXTEN})})})
 same => n,Return()

[ext-did]
{$exact}exten => s,1,NoOp(Gelen s)
{$catchall}exten => _X.,1,NoOp(Gelen kalip)
{$catchall}
[from-pstn]
exten => _+X.,1,Set(ASTERA_RECORD_DIRECTION=gelen)
 same => n,Set(ASTERA_RECORD_EXTERNAL=\${CALLERID(num)})
 same => n,Set(__ASTERA_DID=\${EXTEN:1})
 same => n,Set(CDR(did)=\${ASTERA_DID})
 same => n,Set(CDR(cnum)=\${CALLERID(num)})
 same => n,Set(CDR(cnam)=\${CALLERID(name)})
 same => n,Goto(ext-did,\${EXTEN:1},1)
exten => s,1,Set(ASTERA_RECORD_DIRECTION=gelen)
 same => n,Set(ASTERA_RECORD_EXTERNAL=\${CALLERID(num)})
 same => n,Gosub(from-pstn-did,s,1)
 same => n,Set(__ASTERA_DID=\${DID})
 same => n,Set(CDR(did)=\${ASTERA_DID})
 same => n,Set(CDR(cnum)=\${CALLERID(num)})
 same => n,Set(CDR(cnam)=\${CALLERID(name)})
 same => n,GotoIf(\$["\${DID}" != ""]?ext-did,\${DID},1)
 same => n,Goto(ext-did,s,1)
exten => _.,1,Set(ASTERA_RECORD_DIRECTION=gelen)
 same => n,Set(ASTERA_RECORD_EXTERNAL=\${CALLERID(num)})
 same => n,Gosub(from-pstn-did,s,1)
 same => n,Set(__ASTERA_DID=\${DID})
 same => n,Set(CDR(did)=\${ASTERA_DID})
 same => n,Set(CDR(cnum)=\${CALLERID(num)})
 same => n,Set(CDR(cnam)=\${CALLERID(name)})
 same => n,GotoIf(\$["\${DID}" != ""]?ext-did,\${DID},1)
 same => n,Goto(ext-did,s,1)

CONF;
}

function generate_parking(): string
{
    $out = "; ASTERA park yerleri\n\n";
    foreach (departments() as $dept) {
        $id = (string) ($dept['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $code = dept_code_of($id);
        $p = parking_for($id);
        $parkext = ast_sanitize_id((string) ($p['parkext'] ?? '700')) ?: '700';
        $start = ast_sanitize_id((string) ($p['start'] ?? '701')) ?: '701';
        $end = ast_sanitize_id((string) ($p['end'] ?? '709')) ?: '709';
        $time = max(15, (int) ($p['time'] ?? 45));
        $out .= <<<CONF
[parking-{$code}]
parkext => {$parkext}
parkpos => {$start}-{$end}
context => parked-{$code}
parkingtime => {$time}
parkedmusicclass => {$code}
findslot => next
parkext_exclusive => yes

CONF;
    }
    return $out;
}

function generate_moh(): string
{
    $out = "; ASTERA müzik (beklemede)\n\n";
    foreach (departments() as $dept) {
        $id = (string) ($dept['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $code = dept_code_of($id);
        $out .= <<<CONF
[{$code}]
mode=files
directory=/var/lib/asterisk/moh/{$code}
sort=random

CONF;
    }
    return $out;
}

function generate_features_conf(): string
{
    return <<<'CONF'
; ASTERA çağrı içi özellikleri
[general]
transferdigittimeout = 3
featuredigittimeout = 1000
atxfernoanswertimeout = 15
atxferdropcall = no
atxferabort = *1
atxfercomplete = *2
atxferthreeway = *3
atxferswap = *4

[featuremap]
atxfer => *2

CONF;
}

function generate_http_conf(): string
{
    return <<<CONF
; ASTERA HTTP / WebSocket (WebRTC)
[general]
enabled=yes
bindaddr=0.0.0.0
bindport=8088
enablestatic=no
enable_status=yes
sessionlimit=100
tlsenable=yes
tlsbindaddr=0.0.0.0:8089
tlscertfile=/etc/asterisk/keys/asterisk.pem
tlsprivatekey=/etc/asterisk/keys/asterisk.key

CONF;
}

