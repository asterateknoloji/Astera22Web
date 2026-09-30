<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$action = (string) ($_POST['action'] ?? '');
$webphoneCrmAccess = false;
if (empty($_SESSION['user']) && str_starts_with($action, 'crm_')) {
    $webphoneId = (string) ($_SESSION['webphone_extension_id'] ?? '');
    $webphoneExtension = $webphoneId !== '' ? find_by('extensions', 'id', $webphoneId) : null;
    if ($webphoneExtension && !empty($webphoneExtension['webrtc'])) {
        $_SESSION['user_dept'] = (string) ($webphoneExtension['dept'] ?? '');
        $_SESSION['username'] = 'WebPhone ' . (string) ($webphoneExtension['exten'] ?? '');
        $webphoneCrmAccess = $_SESSION['user_dept'] !== '';
    }
}
if (!$webphoneCrmAccess) {
    require_login();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'POST gerekli'], 405);
}
if (!csrf_ok()) {
    json_out(['ok' => false, 'error' => 'Güvenlik doğrulaması başarısız'], 403);
}

function security_networks(string|array $input): array
{
    $items = is_array($input) ? $input : preg_split('/[\s,;]+/', $input);
    $networks = [];
    foreach ((array) $items as $item) {
        $item = trim((string) $item);
        if ($item === '') {
            continue;
        }
        [$ip, $prefix] = array_pad(explode('/', $item, 2), 2, '32');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || !ctype_digit($prefix) || (int) $prefix < 0 || (int) $prefix > 32) {
            json_out(['ok' => false, 'error' => 'Geçersiz IPv4/CIDR: ' . $item], 422);
        }
        $normalized = $ip . '/' . (int) $prefix;
        if ($normalized === '0.0.0.0/0') {
            json_out(['ok' => false, 'error' => 'Tüm interneti kapsayan 0.0.0.0/0 kuralına izin verilmez'], 422);
        }
        $networks[$normalized] = true;
    }
    return array_keys($networks);
}

try {
    switch ($action) {
        case 'status':
            $status = pbx_status(current_dept_id());
            $status['pending_count'] = store_pending_count();
            json_out(['ok' => true, 'status' => $status]);

        case 'dept_select':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Firma değiştirilemez']);
            }
            $id = ast_sanitize_id((string) ($_POST['dept'] ?? '*')) ?: '*';
            if ($id !== '*' && !dept_by_id($id)) {
                json_out(['ok' => false, 'error' => 'Firma yok']);
            }
            $_SESSION['dept'] = $id;
            json_out(['ok' => true]);

        case 'url_trigger_save':
            $dept = require_dept_id();
            $template = trim((string) ($_POST['url_template'] ?? ''));
            if ($template === '' || strlen($template) > 2048 || !url_trigger_valid_template($template)) {
                json_out(['ok' => false, 'error' => 'Geçerli bir HTTP/HTTPS URL şablonu girin'], 422);
            }
            $trigger = (string) ($_POST['trigger'] ?? 'ring');
            if (!in_array($trigger, ['ring', 'answer', 'both'], true)) {
                $trigger = 'ring';
            }
            $mode = (string) ($_POST['mode'] ?? 'both');
            if (!in_array($mode, ['server', 'browser', 'both'], true)) {
                $mode = 'both';
            }
            $numberFormat = (string) ($_POST['number_format'] ?? 'digits');
            if (!in_array($numberFormat, ['raw', 'digits', 'e164_tr'], true)) {
                $numberFormat = 'digits';
            }
            store_upsert('url_triggers', [
                'id' => 'url-' . $dept,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? 'CRM')) ?: 'CRM',
                'url_template' => $template,
                'trigger' => $trigger,
                'mode' => $mode,
                'number_format' => $numberFormat,
                'enabled' => !empty($_POST['enabled']),
                'updated_at' => date(DATE_ATOM),
                'updated_by' => store_actor(),
            ], 'id');
            json_out(['ok' => true]);

        case 'url_trigger_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('url_triggers', 'id', $id);
            if ($row) {
                assert_row_scope($row);
                store_delete('url_triggers', 'id', $id);
            }
            json_out(['ok' => true]);

        case 'url_trigger_test':
            $dept = require_dept_id();
            $template = trim((string) ($_POST['url_template'] ?? ''));
            if (!url_trigger_valid_template($template)) {
                json_out(['ok' => false, 'error' => 'Geçerli bir HTTP/HTTPS URL şablonu girin'], 422);
            }
            $config = [
                'dept' => $dept,
                'url_template' => $template,
                'number_format' => (string) ($_POST['number_format'] ?? 'digits'),
            ];
            $url = url_trigger_build_url(
                $config,
                trim((string) ($_POST['test_number'] ?? '905551112233')),
                '1001',
                'test'
            );
            $result = url_trigger_request($url);
            json_out([
                'ok' => !empty($result['ok']),
                'code' => (int) ($result['code'] ?? 0),
                'url' => $url,
                'error' => $result['error'] ?? null,
            ], !empty($result['ok']) ? 200 : 422);

        case 'crm_customer_save':
            $dept = require_dept_id();
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $existing = $id !== '' ? crm_customer_find_db($id) : null;
            if ($existing) {
                assert_row_scope($existing);
                if ((string) ($existing['dept'] ?? '') !== $dept) {
                    json_out(['ok' => false, 'error' => 'Müşteri başka bir firmaya ait'], 403);
                }
            }
            $company = substr(trim((string) ($_POST['company'] ?? '')), 0, 160);
            $phoneRaw = substr(trim((string) ($_POST['phone'] ?? '')), 0, 32);
            $phoneAltRaw = substr(trim((string) ($_POST['phone_alt'] ?? '')), 0, 32);
            $phone = crm_normalize_phone($phoneRaw);
            $phoneAlt = crm_normalize_phone($phoneAltRaw);
            if ($company === '') {
                json_out(['ok' => false, 'error' => 'Firma / müşteri adı zorunlu'], 422);
            }
            if (strlen($phone) < 7 || strlen($phone) > 15) {
                json_out(['ok' => false, 'error' => 'Geçerli bir telefon numarası girin'], 422);
            }
            if ($phoneAlt !== '' && (strlen($phoneAlt) < 7 || strlen($phoneAlt) > 15)) {
                json_out(['ok' => false, 'error' => 'İkinci telefon numarası geçersiz'], 422);
            }
            foreach (array_filter([$phone, $phoneAlt]) as $candidatePhone) {
                $customer = crm_customer_by_phone_db($dept, $candidatePhone);
                if (!$customer || (string) ($customer['id'] ?? '') === $id) {
                    continue;
                }
                json_out(['ok' => false, 'error' => 'Bu telefon numarası başka bir müşteri kartında kayıtlı'], 422);
            }
            $email = substr(trim((string) ($_POST['email'] ?? '')), 0, 180);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                json_out(['ok' => false, 'error' => 'E-posta adresi geçersiz'], 422);
            }
            if ($id === '') {
                $id = $dept . '-crm-' . bin2hex(random_bytes(6));
            }
            $savedCustomer = [
                'id' => $id,
                'dept' => $dept,
                'company' => $company,
                'contact' => substr(trim((string) ($_POST['contact'] ?? '')), 0, 160),
                'phone' => $phoneRaw,
                'phone_alt' => $phoneAltRaw,
                'email' => $email,
                'address' => substr(trim((string) ($_POST['address'] ?? '')), 0, 500),
                'notes' => substr(trim((string) ($_POST['notes'] ?? '')), 0, 3000),
                'created_at' => (string) ($existing['created_at'] ?? date(DATE_ATOM)),
                'updated_at' => date(DATE_ATOM),
                'updated_by' => store_actor(),
            ];
            crm_customer_save_db($savedCustomer);
            crm_sync_customer_caller_id_cache($existing, $savedCustomer);
            json_out(['ok' => true, 'id' => $id]);

        case 'crm_customer_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $customer = crm_customer_find_db($id);
            if ($customer) {
                assert_row_scope($customer);
                crm_customer_delete_db($id, (string) ($customer['dept'] ?? ''));
                crm_sync_customer_caller_id_cache($customer, null);
            }
            json_out(['ok' => true]);

        case 'crm_call_note_save':
            $customerId = ast_sanitize_id((string) ($_POST['customer_id'] ?? ''));
            $customer = crm_customer_find_db($customerId);
            if (!$customer) {
                json_out(['ok' => false, 'error' => 'Müşteri kartı bulunamadı'], 404);
            }
            assert_row_scope($customer);
            $dept = (string) ($customer['dept'] ?? '');
            $callKey = substr(trim((string) ($_POST['call_key'] ?? '')), 0, 255);
            if ($callKey === '') {
                json_out(['ok' => false, 'error' => 'Görüşme kimliği eksik'], 422);
            }
            $note = substr(trim((string) ($_POST['note'] ?? '')), 0, 3000);
            $noteId = crm_call_note_id($dept, $callKey);
            if ($note === '') {
                crm_call_note_delete_db($noteId, $dept);
                json_out(['ok' => true]);
            }
            crm_call_note_save_db([
                'id' => $noteId,
                'dept' => $dept,
                'customer_id' => $customerId,
                'call_key' => $callKey,
                'note' => $note,
                'updated_at' => date(DATE_ATOM),
                'updated_by' => store_actor(),
            ]);
            json_out(['ok' => true]);

        case 'department_save':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi']);
            }
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($id === '' || $id === '*') {
                json_out(['ok' => false, 'error' => 'Firma kodu zorunlu (harf/rakam)']);
            }
            $existing = dept_by_id($id);
            $panelPass = (string) ($_POST['panel_pass'] ?? '');
            if ($panelPass === '' && $existing) {
                $panelPass = (string) ($existing['panel_pass'] ?? '');
            }
            $extensionLimit = max(0, min(100000, (int) ($_POST['extension_limit'] ?? 0)));
            $extensionCount = department_extension_count($id);
            if ($extensionLimit > 0 && $extensionLimit < $extensionCount) {
                json_out([
                    'ok' => false,
                    'error' => "Abone limiti mevcut {$extensionCount} aboneden düşük olamaz.",
                ], 422);
            }
            store_upsert('departments', [
                'id' => $id,
                'code' => $id,
                'name' => trim((string) ($_POST['name'] ?? $id)),
                'record' => !empty($_POST['record']),
                'cid' => trim((string) ($_POST['cid'] ?? '')),
                'panel_user' => trim((string) ($_POST['panel_user'] ?? '')),
                'panel_pass' => $panelPass,
                'extension_limit' => $extensionLimit,
            ], 'id');
            crm_ensure_tenant($id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'department_delete':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi']);
            }
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($id === 'genel') {
                json_out(['ok' => false, 'error' => 'Varsayılan firma silinemez']);
            }
            foreach (store_read('extensions') as $row) {
                if ((string) ($row['dept'] ?? '') === $id) {
                    json_out(['ok' => false, 'error' => 'Önce bu firmanın abonelerini silin']);
                }
            }
            store_delete('departments', 'id', $id);
            crm_tenant_delete_db($id);
            foreach (tenant_store_names() as $name) {
                $rows = array_values(array_filter(store_read($name), static fn($r) => (string) ($r['dept'] ?? '') !== $id));
                store_write($name, $rows);
            }
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'extension_save':
            $dept = require_dept_id();
            $exten = preg_replace('/\D/', '', (string) ($_POST['exten'] ?? '')) ?? '';
            if (strlen($exten) < 2 || strlen($exten) > 8) {
                json_out(['ok' => false, 'error' => 'Dahili 2-8 haneli olmalı']);
            }
            $password = trim((string) ($_POST['password'] ?? ''));
            if ($password === '') {
                json_out(['ok' => false, 'error' => 'Parola zorunlu']);
            }
            $id = ext_id($dept, $exten);
            $old = find_by('extensions', 'id', $id);
            if (!$old) {
                assert_extension_capacity($dept);
            }
            $wantSip = ast_sanitize_id((string) ($_POST['sipuser'] ?? ''));
            if ($wantSip === '') {
                $wantSip = (string) ($old['sipuser'] ?? $exten);
            }
            $sip = pick_sip_user($dept, $exten, $wantSip);
            $other = find_sip_user($sip);
            if ($other && ((string) ($other['id'] ?? '') !== $id) && sip_user($other) === $sip) {
                json_out(['ok' => false, 'error' => 'Bu SIP kullanıcı adı başka abonede kayıtlı: ' . $sip]);
            }
            $auth = ast_sanitize_id((string) ($_POST['authuser'] ?? ''));
            if ($auth === '') {
                $auth = ast_sanitize_id((string) ($old['authuser'] ?? $sip));
            }
            if ($auth === '') {
                $auth = $sip;
            }
            $codecs = $_POST['codecs'] ?? ['alaw', 'ulaw'];
            if (!is_array($codecs)) {
                $codecs = [$codecs];
            }
            $recordFormat = strtolower((string) ($_POST['record_format'] ?? 'wav'));
            if (!in_array($recordFormat, ['wav', 'gsm', 'ulaw'], true)) {
                $recordFormat = 'wav';
            }
            $fallbackType = dest_allowed((string) ($_POST['fallback_type'] ?? 'hangup'));
            $fallbackDest = ast_sanitize_id((string) ($_POST['fallback_dest'] ?? ''));
            if ($fallbackType !== 'hangup' && $fallbackDest === '') {
                json_out(['ok' => false, 'error' => 'Cevaplanmama/meşgul yönlendirme hedefi zorunlu']);
            }
            if ($fallbackType !== 'hangup' && !dest_belongs_to_dept($dept, $fallbackType, $fallbackDest)) {
                json_out(['ok' => false, 'error' => 'Yönlendirme hedefi bu firmaya ait değil']);
            }
            store_upsert('extensions', [
                'id' => $id,
                'dept' => $dept,
                'exten' => $exten,
                'sipuser' => $sip,
                'authuser' => $auth,
                'name' => trim((string) ($_POST['name'] ?? $exten)),
                'password' => $password,
                'max_contacts' => max(1, (int) ($_POST['max_contacts'] ?? 1)),
                'codecs' => array_values($codecs),
                'callerid' => trim((string) ($_POST['name'] ?? $exten)),
                'vm_enabled' => !empty($_POST['vm_enabled']),
                'vm_pin' => ast_sanitize_id((string) ($_POST['vm_pin'] ?? '1234')) ?: '1234',
                'vm_email' => trim((string) ($_POST['vm_email'] ?? '')),
                'cid_num' => trim((string) ($_POST['cid_num'] ?? '')),
                'record' => !empty($_POST['record']),
                'record_format' => $recordFormat,
                'ringtime' => max(8, (int) ($_POST['ringtime'] ?? 30)),
                'followme' => trim((string) ($_POST['followme'] ?? '')),
                'fallback_type' => $fallbackType,
                'fallback_dest' => $fallbackDest,
                'dtmf' => in_array((string) ($_POST['dtmf'] ?? 'rfc4733'), ['rfc4733', 'inband', 'info'], true) ? (string) $_POST['dtmf'] : 'rfc4733',
                'language' => ast_sanitize_id((string) ($_POST['language'] ?? 'en')) ?: 'en',
                'transport' => ($_POST['transport'] ?? 'udp') === 'tcp' ? 'tcp' : 'udp',
                'qualify' => max(0, (int) ($_POST['qualify'] ?? 0)),
                'webrtc' => !empty($_POST['webrtc']),
            ], 'id');
            json_out(array_merge(['ok' => true, 'sipuser' => $sip], apply_to_pbx()));

        case 'extension_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('extensions', 'id', $id);
            if (!$row) {
                json_out(['ok' => false, 'error' => 'Abone yok']);
            }
            assert_row_scope($row);
            store_delete('extensions', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'extension_bulk_delete':
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids)) {
                $ids = [$ids];
            }
            $ids = array_values(array_unique(array_filter(array_map(
                static fn($id) => ast_sanitize_id((string) $id),
                $ids
            ))));
            if (!$ids) {
                json_out(['ok' => false, 'error' => 'Silinecek abone seçilmedi']);
            }
            if (count($ids) > 5000) {
                json_out(['ok' => false, 'error' => 'Tek işlemde en fazla 5000 abone silinebilir']);
            }
            $idIndex = array_fill_keys($ids, true);
            $rows = store_read('extensions');
            $deleted = 0;
            foreach ($rows as $row) {
                if (!isset($idIndex[(string) ($row['id'] ?? '')])) {
                    continue;
                }
                assert_row_scope($row);
                $deleted++;
            }
            if ($deleted === 0) {
                json_out(['ok' => false, 'error' => 'Seçilen aboneler bulunamadı']);
            }
            $rows = array_values(array_filter(
                $rows,
                static fn($row) => !isset($idIndex[(string) ($row['id'] ?? '')])
            ));
            store_write('extensions', $rows);
            json_out(array_merge(['ok' => true, 'deleted' => $deleted], apply_to_pbx()));

        case 'trunk_save':
            $dept = require_dept_id();
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $host = trim((string) ($_POST['host'] ?? ''));
            if ($id === '' || $host === '') {
                json_out(['ok' => false, 'error' => 'Trunk adı ve sunucu zorunlu']);
            }
            $old = find_by('trunks', 'id', $id);
            if ($old) {
                assert_row_scope($old);
            }
            $trunkType = ($_POST['type'] ?? 'register') === 'peer' ? 'peer' : 'register';
            $codecs = $_POST['codecs'] ?? ['ulaw', 'alaw', 'gsm', 'g726', 'g722'];
            if (!is_array($codecs)) {
                $codecs = [$codecs];
            }
            $dtmfMode = (string) ($_POST['dtmf_mode'] ?? 'auto');
            if (!in_array($dtmfMode, ['auto', 'rfc4733', 'inband', 'info'], true)) {
                $dtmfMode = 'auto';
            }
            $mediaEncryption = (string) ($_POST['media_encryption'] ?? 'no');
            if (!in_array($mediaEncryption, ['no', 'sdes', 'dtls'], true)) {
                $mediaEncryption = 'no';
            }
            if ($trunkType === 'register') {
                $codecs = ['ulaw', 'alaw', 'gsm', 'g726', 'g722'];
                $dtmfMode = 'auto';
                $mediaEncryption = 'no';
            }
            store_upsert('trunks', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $id)),
                'type' => $trunkType,
                'enabled' => $old ? ($old['enabled'] ?? true) : true,
                'host' => $host,
                'port' => (int) ($_POST['port'] ?? 5060) ?: 5060,
                'username' => trim((string) ($_POST['username'] ?? '')),
                'password' => (string) ($_POST['password'] ?? ''),
                'from_user' => trim((string) ($_POST['from_user'] ?? '')),
                'from_domain' => trim((string) ($_POST['from_domain'] ?? '')),
                'codecs' => array_values($codecs),
                'dtmf_mode' => $dtmfMode,
                'direct_media' => $trunkType === 'register' ? false : !empty($_POST['direct_media']),
                'fax_detect' => $trunkType === 'register' ? false : !empty($_POST['fax_detect']),
                'media_encryption' => $mediaEncryption,
                'rewrite_contact' => $trunkType === 'register' ? true : !empty($_POST['rewrite_contact']),
                'rtp_symmetric' => $trunkType === 'register' ? true : !empty($_POST['rtp_symmetric']),
                'trust_id_inbound' => $trunkType === 'register' ? false : !empty($_POST['trust_id_inbound']),
                'support_path' => $trunkType === 'register' ? false : !empty($_POST['support_path']),
                'qualify_frequency' => $trunkType === 'register' ? 60 : max(0, (int) ($_POST['qualify_frequency'] ?? 60)),
                'expiration' => $trunkType === 'register' ? 3600 : max(60, (int) ($_POST['expiration'] ?? 3600)),
                'retry_interval' => $trunkType === 'register' ? 60 : max(1, (int) ($_POST['retry_interval'] ?? 60)),
                'max_retries' => $trunkType === 'register' ? 10 : max(0, (int) ($_POST['max_retries'] ?? 10)),
                'auth_rejection_permanent' => $trunkType === 'register' ? false : !empty($_POST['auth_rejection_permanent']),
                't38_udptl' => $trunkType === 'register' ? false : !empty($_POST['t38_udptl']),
                't38_udptl_nat' => $trunkType === 'register' ? false : !empty($_POST['t38_udptl_nat']),
            ], 'id');
            ensure_default_outbound($dept, $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'trunk_toggle':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('trunks', 'id', $id);
            if (!$row) {
                json_out(['ok' => false, 'error' => 'Trunk bulunamadı'], 404);
            }
            assert_row_scope($row);
            $row['enabled'] = !($row['enabled'] ?? true);
            store_upsert('trunks', $row, 'id');
            json_out(array_merge([
                'ok' => true,
                'enabled' => $row['enabled'],
            ], apply_to_pbx()));

        case 'trunk_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('trunks', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('trunks', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'outbound_save':
            $dept = require_dept_id();
            $id = ast_sanitize_id((string) ($_POST['id'] ?? '')) ?: ($dept . 'out' . substr(bin2hex(random_bytes(3)), 0, 6));
            $pattern = trim((string) ($_POST['pattern'] ?? ''));
            $trunk = ast_sanitize_id((string) ($_POST['trunk'] ?? ''));
            if ($pattern === '' || $trunk === '') {
                json_out(['ok' => false, 'error' => 'Kalıp ve trunk zorunlu']);
            }
            $trunkRow = find_by('trunks', 'id', $trunk);
            if (!$trunkRow || (string) ($trunkRow['dept'] ?? '') !== $dept) {
                json_out(['ok' => false, 'error' => 'Trunk bu firmaya ait değil']);
            }
            store_upsert('outbound', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $pattern)),
                'pattern' => $pattern,
                'strip' => max(0, (int) ($_POST['strip'] ?? 0)),
                'prefix' => trim((string) ($_POST['prefix'] ?? '')),
                'trunk' => $trunk,
                'pin' => ast_sanitize_id((string) ($_POST['pin'] ?? '')),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'outbound_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('outbound', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('outbound', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'inbound_save':
            $dept = require_dept_id();
            $id = ast_sanitize_id((string) ($_POST['id'] ?? '')) ?: ($dept . 'in' . substr(bin2hex(random_bytes(3)), 0, 6));
            $did = trim((string) ($_POST['did'] ?? ''));
            $destType = dest_allowed((string) ($_POST['dest_type'] ?? 'extension'));
            $dest = ast_sanitize_id((string) ($_POST['dest'] ?? ''));
            if ($did === '' || ($dest === '' && $destType !== 'hangup')) {
                json_out(['ok' => false, 'error' => 'DID ve hedef zorunlu']);
            }
            store_upsert('inbound', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $did)),
                'did' => $did,
                'dest_type' => $destType,
                'dest' => $dest,
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'inbound_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('inbound', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('inbound', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'queue_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $exten = preg_replace('/\D/', '', (string) ($_POST['exten'] ?? '')) ?? '';
            if ($local === '' || $exten === '') {
                json_out(['ok' => false, 'error' => 'Kuyruk adı ve dahili numarası zorunlu']);
            }
            $id = str_starts_with($local, $dept . '-') ? $local : ($dept . '-' . $local);
            $members = $_POST['members'] ?? [];
            if (!is_array($members)) {
                $members = array_filter(array_map('trim', explode(',', (string) $members)));
            }
            $members = array_values(array_unique(array_filter(array_map(
                static fn($member) => preg_replace('/\D/', '', (string) $member) ?? '',
                $members
            ), static fn($member) => $member !== '' && find_ext($dept, $member) !== null)));
            $strategy = ast_sanitize_id((string) ($_POST['strategy'] ?? 'ringall')) ?: 'ringall';
            if (!array_key_exists($strategy, ring_strategies())) {
                $strategy = 'ringall';
            }
            $joinempty = (string) ($_POST['joinempty'] ?? 'yes');
            if (!in_array($joinempty, ['yes', 'no', 'strict'], true)) {
                $joinempty = 'yes';
            }
            $leavewhenempty = (string) ($_POST['leavewhenempty'] ?? 'no');
            if (!in_array($leavewhenempty, ['yes', 'no', 'strict'], true)) {
                $leavewhenempty = 'no';
            }
            $autopause = (string) ($_POST['autopause'] ?? 'no');
            if (!in_array($autopause, ['yes', 'no', 'all'], true)) {
                $autopause = 'no';
            }
            $holdtime = (string) ($_POST['announce_holdtime'] ?? 'no');
            if (!in_array($holdtime, ['yes', 'no', 'once'], true)) {
                $holdtime = 'no';
            }
            $round = (int) ($_POST['announce_round_seconds'] ?? 10);
            if (!in_array($round, [0, 5, 10, 15, 20, 30], true)) {
                $round = 10;
            }
            $announce = normalize_sound_name((string) ($_POST['announce'] ?? ''));
            $periodicAnnounce = normalize_sound_name((string) ($_POST['periodic_announce'] ?? ''));
            if (!tenant_sound_allowed($dept, $announce) || !tenant_sound_allowed($dept, $periodicAnnounce)) {
                json_out(['ok' => false, 'error' => 'Seçilen ses bu firmaya ait değil'], 422);
            }
            $recordFormat = strtolower((string) ($_POST['record_format'] ?? 'wav'));
            if (!in_array($recordFormat, ['wav', 'gsm', 'ulaw'], true)) {
                $recordFormat = 'wav';
            }
            store_upsert('queues', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'language' => ast_sanitize_id((string) ($_POST['language'] ?? 'en')) ?: 'en',
                'record' => !empty($_POST['record']),
                'record_format' => $recordFormat,
                'exten' => $exten,
                'strategy' => $strategy,
                'timeout' => max(5, (int) ($_POST['timeout'] ?? 20)),
                'retry' => max(1, min(60, (int) ($_POST['retry'] ?? 5))),
                'wrapuptime' => max(0, (int) ($_POST['wrapuptime'] ?? 5)),
                'maxlen' => max(0, (int) ($_POST['maxlen'] ?? 0)),
                'queue_timeout' => max(0, min(86400, (int) ($_POST['queue_timeout'] ?? 300))),
                'servicelevel' => max(1, min(86400, (int) ($_POST['servicelevel'] ?? 60))),
                'weight' => max(0, min(100, (int) ($_POST['weight'] ?? 0))),
                'memberdelay' => max(0, min(60, (int) ($_POST['memberdelay'] ?? 0))),
                'musicclass' => ast_sanitize_id((string) ($_POST['musicclass'] ?? dept_code_of($dept))) ?: dept_code_of($dept),
                'joinempty' => $joinempty,
                'leavewhenempty' => $leavewhenempty,
                'ringinuse' => !empty($_POST['ringinuse']),
                'autofill' => !empty($_POST['autofill']),
                'autopause' => $autopause,
                'autopausebusy' => !empty($_POST['autopausebusy']),
                'autopausenoanswer' => !empty($_POST['autopausenoanswer']),
                'autopauseunavailable' => !empty($_POST['autopauseunavailable']),
                'autopausedelay' => max(0, min(3600, (int) ($_POST['autopausedelay'] ?? 0))),
                'timeoutrestart' => !empty($_POST['timeoutrestart']),
                'shared_lastcall' => !empty($_POST['shared_lastcall']),
                'caller_transfer' => !empty($_POST['caller_transfer']),
                'agent_transfer' => !empty($_POST['agent_transfer']),
                'announce' => $announce,
                'announce_position' => !empty($_POST['announce_position']),
                'announce_frequency' => max(0, min(3600, (int) ($_POST['announce_frequency'] ?? 60))),
                'min_announce_frequency' => max(0, min(3600, (int) ($_POST['min_announce_frequency'] ?? 15))),
                'announce_holdtime' => $holdtime,
                'announce_round_seconds' => $round,
                'announce_position_limit' => max(0, min(999, (int) ($_POST['announce_position_limit'] ?? 0))),
                'periodic_announce' => $periodicAnnounce,
                'periodic_announce_frequency' => max(0, min(86400, (int) ($_POST['periodic_announce_frequency'] ?? 60))),
                'relative_periodic_announce' => !empty($_POST['relative_periodic_announce']),
                'announce_to_first_user' => !empty($_POST['announce_to_first_user']),
                'reportholdtime' => !empty($_POST['reportholdtime']),
                'timeout_type' => dest_allowed((string) ($_POST['timeout_type'] ?? 'hangup')),
                'timeout_dest' => ast_sanitize_id((string) ($_POST['timeout_dest'] ?? '')),
                'members' => array_values($members),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'queue_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('queues', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('queues', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'ringgroup_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $exten = preg_replace('/\D/', '', (string) ($_POST['exten'] ?? '')) ?? '';
            if ($local === '' || $exten === '') {
                json_out(['ok' => false, 'error' => 'Grup adı ve dahili zorunlu']);
            }
            $id = str_starts_with($local, $dept . '-') ? $local : ($dept . '-' . $local);
            $members = $_POST['members'] ?? [];
            if (!is_array($members)) {
                $members = array_filter(array_map('trim', explode(',', (string) $members)));
            }
            $allowedMembers = [];
            foreach (store_read('extensions') as $extension) {
                if ((string) ($extension['dept'] ?? '') === $dept) {
                    $allowedMembers[] = (string) ($extension['exten'] ?? '');
                }
            }
            $members = array_values(array_unique(array_filter(
                array_map(static fn ($member) => preg_replace('/\D/', '', (string) $member) ?? '', $members),
                static fn ($member) => $member !== '' && in_array($member, $allowedMembers, true)
            )));
            if (!$members) {
                json_out(['ok' => false, 'error' => 'Seçilen firmaya ait en az bir abone seçin']);
            }
            $recordFormat = strtolower((string) ($_POST['record_format'] ?? 'wav'));
            if (!in_array($recordFormat, ['wav', 'gsm', 'ulaw'], true)) {
                $recordFormat = 'wav';
            }
            $announcement = normalize_sound_name((string) ($_POST['announcement'] ?? ''));
            if (!tenant_sound_allowed($dept, $announcement)) {
                json_out(['ok' => false, 'error' => 'Seçilen ses bu firmaya ait değil'], 422);
            }
            store_upsert('ringgroups', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'description' => trim((string) ($_POST['description'] ?? '')),
                'language' => ast_sanitize_id((string) ($_POST['language'] ?? 'en')) ?: 'en',
                'exten' => $exten,
                'strategy' => ($_POST['strategy'] ?? 'ringall') === 'hunt' ? 'hunt' : 'ringall',
                'ring_time' => max(5, (int) ($_POST['ring_time'] ?? 20)),
                'confirm_calls' => !empty($_POST['confirm_calls']),
                'announcement' => $announcement,
                'music_class' => trim((string) ($_POST['music_class'] ?? dept_code_of($dept))) ?: dept_code_of($dept),
                'cid_prefix' => trim((string) ($_POST['cid_prefix'] ?? '')),
                'skip_busy' => !empty($_POST['skip_busy']),
                'record' => !empty($_POST['record']),
                'record_format' => $recordFormat,
                'timeout' => max(5, (int) ($_POST['timeout'] ?? 25)),
                'timeout_type' => dest_allowed((string) ($_POST['timeout_type'] ?? 'hangup')),
                'timeout_dest' => ast_sanitize_id((string) ($_POST['timeout_dest'] ?? '')),
                'members' => array_values($members),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'ringgroup_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('ringgroups', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('ringgroups', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'recordings':
            json_out(['ok' => true, 'files' => pbx_recordings(current_dept_id())]);

        case 'ivr_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($local === '') {
                json_out(['ok' => false, 'error' => 'IVR kodu zorunlu']);
            }
            $id = str_starts_with($local, $dept . '-') ? $local : ($dept . '-' . $local);
            $digits = json_decode((string) ($_POST['digits_json'] ?? '{}'), true);
            if (!is_array($digits)) {
                $digits = [];
            }
            $blockedExtensions = json_decode((string) ($_POST['blocked_extensions_json'] ?? '[]'), true);
            if (!is_array($blockedExtensions)) {
                $blockedExtensions = [];
            }
            $deptExtensions = [];
            foreach (store_read('extensions') as $extension) {
                if ((string) ($extension['dept'] ?? '') === $dept) {
                    $number = ast_sanitize_id((string) ($extension['exten'] ?? ''));
                    if ($number !== '') {
                        $deptExtensions[$number] = true;
                    }
                }
            }
            $blockedExtensions = array_values(array_unique(array_filter(
                array_map(static fn($number) => ast_sanitize_id((string) $number), $blockedExtensions),
                static fn(string $number): bool => isset($deptExtensions[$number])
            )));
            $blockedType = ast_sanitize_id((string) ($_POST['blocked_type'] ?? 'hangup')) ?: 'hangup';
            $blockedDest = ast_sanitize_id((string) ($_POST['blocked_dest'] ?? ''));
            if ($blockedExtensions && !dest_belongs_to_dept($dept, $blockedType, $blockedDest)) {
                json_out(['ok' => false, 'error' => 'Yasaklı dahili hedefi bu firmaya ait değil'], 422);
            }
            $sound = normalize_sound_name((string) ($_POST['sound'] ?? 'hello-world')) ?: 'hello-world';
            if (!tenant_sound_allowed($dept, $sound)) {
                json_out(['ok' => false, 'error' => 'Seçilen ses bu firmaya ait değil'], 422);
            }
            store_upsert('ivrs', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'language' => ast_sanitize_id((string) ($_POST['language'] ?? 'en')) ?: 'en',
                'sound' => $sound,
                'timeout_type' => ast_sanitize_id((string) ($_POST['timeout_type'] ?? 'hangup')) ?: 'hangup',
                'timeout_dest' => ast_sanitize_id((string) ($_POST['timeout_dest'] ?? '')),
                'direct_dial' => !empty($_POST['direct_dial']),
                'blocked_extensions' => $blockedExtensions,
                'blocked_type' => $blockedType,
                'blocked_dest' => $blockedDest,
                'digits' => $digits,
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'ivr_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('ivrs', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('ivrs', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'time_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($local === '') {
                for ($sequence = 1; $sequence <= 9999; $sequence++) {
                    $candidate = $dept . '-mesai-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
                    if (!find_by('timeconditions', 'id', $candidate)) {
                        $local = $candidate;
                        break;
                    }
                }
                if ($local === '') {
                    json_out(['ok' => false, 'error' => 'Otomatik zaman koşulu kodu üretilemedi'], 500);
                }
            }
            $id = str_starts_with($local, $dept . '-') ? $local : ($dept . '-' . $local);
            $rulesInput = json_decode((string) ($_POST['rules_json'] ?? '[]'), true);
            $rules = [];
            $validDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
            foreach (is_array($rulesInput) ? array_slice($rulesInput, 0, 30) : [] as $rule) {
                $start = (string) ($rule['start'] ?? '');
                $end = (string) ($rule['end'] ?? '');
                $days = array_values(array_intersect($validDays, (array) ($rule['days'] ?? [])));
                if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || !$days) {
                    continue;
                }
                $rules[] = [
                    'name' => trim((string) ($rule['name'] ?? 'Çalışma aralığı')),
                    'start' => $start,
                    'end' => $end,
                    'days' => $days,
                ];
            }
            if (!$rules) {
                json_out(['ok' => false, 'error' => 'En az bir çalışma aralığı ve gün seçmelisiniz'], 422);
            }
            $exceptionsInput = json_decode((string) ($_POST['exceptions_json'] ?? '[]'), true);
            $exceptions = [];
            foreach (is_array($exceptionsInput) ? array_slice($exceptionsInput, 0, 100) : [] as $exception) {
                $from = (string) ($exception['from'] ?? '');
                $to = (string) ($exception['to'] ?? $from);
                $time = (string) ($exception['time'] ?? '00:00-23:59');
                $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
                $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
                if (!$fromDate || !$toDate || $toDate < $fromDate || $fromDate->diff($toDate)->days > 366
                    || !preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $time)) {
                    json_out(['ok' => false, 'error' => 'Özel tarih veya saat aralığı geçersiz'], 422);
                }
                $exceptions[] = [
                    'name' => trim((string) ($exception['name'] ?? 'Özel tarih')),
                    'from' => $from,
                    'to' => $to,
                    'state' => ($exception['state'] ?? 'closed') === 'open' ? 'open' : 'closed',
                    'time' => $time,
                ];
            }
            $timezone = (string) ($_POST['timezone'] ?? 'Europe/Istanbul');
            if (!in_array($timezone, ['Europe/Istanbul', 'UTC', 'Europe/London', 'Europe/Berlin'], true)) {
                $timezone = 'Europe/Istanbul';
            }
            $legacyDays = implode('&', $rules[0]['days']);
            $trueType = ast_sanitize_id((string) ($_POST['true_type'] ?? 'hangup')) ?: 'hangup';
            $trueDest = ast_sanitize_id((string) ($_POST['true_dest'] ?? ''));
            $falseType = ast_sanitize_id((string) ($_POST['false_type'] ?? 'hangup')) ?: 'hangup';
            $falseDest = ast_sanitize_id((string) ($_POST['false_dest'] ?? ''));
            if (!dest_belongs_to_dept($dept, $trueType, $trueDest)
                || !dest_belongs_to_dept($dept, $falseType, $falseDest)) {
                json_out(['ok' => false, 'error' => 'Zaman koşulu hedefi seçili firmaya ait değil'], 422);
            }
            $name = trim((string) ($_POST['name'] ?? ''));
            store_upsert('timeconditions', [
                'id' => $id,
                'dept' => $dept,
                'name' => $name !== '' ? $name : $local,
                'timezone' => $timezone,
                'rules' => $rules,
                'exceptions' => $exceptions,
                'turkey_holidays' => !empty($_POST['turkey_holidays']),
                'turkey_half_days' => !empty($_POST['turkey_half_days']),
                'time' => $rules[0]['start'] . '-' . $rules[0]['end'],
                'days' => $legacyDays,
                'true_type' => $trueType,
                'true_dest' => $trueDest,
                'false_type' => $falseType,
                'false_dest' => $falseDest,
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'time_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('timeconditions', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('timeconditions', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'announcement_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($local === '') {
                json_out(['ok' => false, 'error' => 'Kod zorunlu']);
            }
            $id = str_starts_with($local, $dept . '-') ? $local : ($dept . '-' . $local);
            $sound = normalize_sound_name((string) ($_POST['sound'] ?? 'hello-world')) ?: 'hello-world';
            if (!tenant_sound_allowed($dept, $sound)) {
                json_out(['ok' => false, 'error' => 'Seçilen ses bu firmaya ait değil'], 422);
            }
            store_upsert('announcements', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'language' => ast_sanitize_id((string) ($_POST['language'] ?? 'en')) ?: 'en',
                'sound' => $sound,
                'dest_type' => ast_sanitize_id((string) ($_POST['dest_type'] ?? 'hangup')) ?: 'hangup',
                'dest' => ast_sanitize_id((string) ($_POST['dest'] ?? '')),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'announcement_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('announcements', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('announcements', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'conference_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $exten = preg_replace('/\D/', '', (string) ($_POST['exten'] ?? '')) ?? '';
            if ($local === '' || $exten === '') {
                json_out(['ok' => false, 'error' => 'Kod ve dahili zorunlu']);
            }
            $id = str_starts_with($local, $dept . '-') ? $local : ($dept . '-' . $local);
            $recordFormat = strtolower((string) ($_POST['record_format'] ?? 'wav'));
            if (!in_array($recordFormat, ['wav', 'gsm', 'ulaw'], true)) {
                $recordFormat = 'wav';
            }
            store_upsert('conferences', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'exten' => $exten,
                'pin' => ast_sanitize_id((string) ($_POST['pin'] ?? '')),
                'record' => !empty($_POST['record']),
                'record_format' => $recordFormat,
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'conference_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('conferences', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('conferences', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'blacklist_save':
            $dept = require_dept_id();
            $number = preg_replace('/\D+/', '', (string) ($_POST['number'] ?? '')) ?? '';
            if (strlen($number) < 3) {
                json_out(['ok' => false, 'error' => 'Numara çok kısa']);
            }
            store_upsert('blacklist', [
                'id' => $dept . '-' . $number,
                'dept' => $dept,
                'number' => $number,
                'name' => trim((string) ($_POST['name'] ?? $number)),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'blacklist_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('blacklist', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('blacklist', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'bulk_extensions':
            $dept = require_dept_id();
            $start = (int) ($_POST['start'] ?? 0);
            $end = (int) ($_POST['end'] ?? 0);
            $pass = trim((string) ($_POST['password'] ?? ''));
            if ($start < 100 || $end < $start || $end - $start > 50) {
                json_out(['ok' => false, 'error' => 'Aralık 100+ ve en fazla 50 dahili']);
            }
            if ($pass === '') {
                json_out(['ok' => false, 'error' => 'Parola zorunlu']);
            }
            $extensionRows = store_read('extensions');
            $existingIds = array_fill_keys(array_map(
                static fn($row) => (string) ($row['id'] ?? ''),
                $extensionRows
            ), true);
            $existingAuthUsers = array_fill_keys(array_filter(array_map(
                static fn($row) => ast_sanitize_id((string) ($row['authuser'] ?? '')),
                $extensionRows
            )), true);
            $newCount = 0;
            for ($n = $start; $n <= $end; $n++) {
                if (!isset($existingIds[ext_id($dept, (string) $n)])) {
                    $newCount++;
                }
            }
            assert_extension_capacity($dept, $newCount);
            for ($n = $start; $n <= $end; $n++) {
                $exten = (string) $n;
                $id = ext_id($dept, $exten);
                if (isset($existingIds[$id])) {
                    continue;
                }
                $sip = pick_sip_user($dept, $exten, $exten);
                do {
                    $auth = bin2hex(random_bytes(6));
                } while (isset($existingAuthUsers[$auth]));
                $existingAuthUsers[$auth] = true;
                $extensionRows[] = [
                    'id' => $id,
                    'dept' => $dept,
                    'exten' => $exten,
                    'sipuser' => $sip,
                    'authuser' => $auth,
                    'name' => 'Abone ' . $exten,
                    'password' => $pass,
                    'max_contacts' => 1,
                    'codecs' => ['alaw', 'ulaw'],
                    'callerid' => 'Abone ' . $exten,
                    'vm_enabled' => !empty($_POST['vm_enabled']),
                    'vm_pin' => '1234',
                    'ringtime' => 30,
                    'followme' => '',
                ];
                $existingIds[$id] = true;
            }
            store_write('extensions', $extensionRows);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'parking_save':
            $dept = require_dept_id();
            $id = $dept . '-park';
            store_upsert('parking', [
                'id' => $id,
                'dept' => $dept,
                'parkext' => ast_sanitize_id((string) ($_POST['parkext'] ?? '700')) ?: '700',
                'start' => ast_sanitize_id((string) ($_POST['start'] ?? '701')) ?: '701',
                'end' => ast_sanitize_id((string) ($_POST['end'] ?? '709')) ?: '709',
                'time' => max(15, (int) ($_POST['time'] ?? 45)),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'flow_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($local === '') {
                json_out(['ok' => false, 'error' => 'Kod zorunlu']);
            }
            $id = scoped_id($dept, $local);
            $feat = trim((string) ($_POST['feature'] ?? '*28'));
            if (!preg_match('/^\*[0-9]{2,6}$/', $feat)) {
                json_out(['ok' => false, 'error' => 'Özellik kodu * ile başlamalı (ör. *28)']);
            }
            store_upsert('flows', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'feature' => $feat,
                'true_type' => dest_allowed((string) ($_POST['true_type'] ?? 'extension')),
                'true_dest' => ast_sanitize_id((string) ($_POST['true_dest'] ?? '')),
                'false_type' => dest_allowed((string) ($_POST['false_type'] ?? 'hangup')),
                'false_dest' => ast_sanitize_id((string) ($_POST['false_dest'] ?? '')),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'flow_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('flows', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('flows', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'disa_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($local === '') {
                json_out(['ok' => false, 'error' => 'Kod zorunlu']);
            }
            $id = scoped_id($dept, $local);
            store_upsert('disas', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'pin' => ast_sanitize_id((string) ($_POST['pin'] ?? '')),
                'exten' => preg_replace('/\D/', '', (string) ($_POST['exten'] ?? '')) ?? '',
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'disa_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('disas', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('disas', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'paging_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $exten = preg_replace('/\D/', '', (string) ($_POST['exten'] ?? '')) ?? '';
            if ($local === '' || $exten === '') {
                json_out(['ok' => false, 'error' => 'Kod ve dahili zorunlu']);
            }
            $id = scoped_id($dept, $local);
            $members = $_POST['members'] ?? [];
            if (!is_array($members)) {
                $members = array_filter(array_map('trim', explode(',', (string) $members)));
            }
            if (!$members) {
                json_out(['ok' => false, 'error' => 'En az bir üye seçin']);
            }
            store_upsert('pagings', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'exten' => $exten,
                'duplex' => !empty($_POST['duplex']),
                'members' => array_values($members),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'paging_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('pagings', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('pagings', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'speed_save':
            $dept = require_dept_id();
            $code = ast_sanitize_id((string) ($_POST['code'] ?? ''));
            $number = preg_replace('/[^0-9*#]/', '', (string) ($_POST['number'] ?? '')) ?? '';
            if ($code === '' || $number === '') {
                json_out(['ok' => false, 'error' => 'Kod ve numara zorunlu']);
            }
            store_upsert('speeddials', [
                'id' => $dept . '-' . $code,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $code)),
                'code' => $code,
                'number' => $number,
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'speed_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('speeddials', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('speeddials', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'custom_save':
            $dept = require_dept_id();
            $local = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            if ($local === '') {
                json_out(['ok' => false, 'error' => 'Kod zorunlu']);
            }
            $id = scoped_id($dept, $local);
            $sound = normalize_sound_name((string) ($_POST['sound'] ?? ''));
            if (!tenant_sound_allowed($dept, $sound)) {
                json_out(['ok' => false, 'error' => 'Seçilen ses bu firmaya ait değil'], 422);
            }
            store_upsert('customs', [
                'id' => $id,
                'dept' => $dept,
                'name' => trim((string) ($_POST['name'] ?? $local)),
                'sound' => $sound,
                'goto_context' => ast_sanitize_id((string) ($_POST['goto_context'] ?? '')),
                'goto_exten' => ast_sanitize_id((string) ($_POST['goto_exten'] ?? 's')) ?: 's',
                'goto_pri' => max(1, (int) ($_POST['goto_pri'] ?? 1)),
                'dest_type' => dest_allowed((string) ($_POST['dest_type'] ?? 'hangup')),
                'dest' => ast_sanitize_id((string) ($_POST['dest'] ?? '')),
            ], 'id');
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'custom_delete':
            $id = ast_sanitize_id((string) ($_POST['id'] ?? ''));
            $row = find_by('customs', 'id', $id);
            if ($row) {
                assert_row_scope($row);
            }
            store_delete('customs', 'id', $id);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'context_detail':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Yetkisiz işlem'], 403);
            }
            $contextName = trim((string) ($_POST['context'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_.-]+$/', $contextName)) {
                json_out(['ok' => false, 'error' => 'Geçersiz context'], 422);
            }
            $known = array_column(pbx_context_inventory(), null, 'name');
            if (!isset($known[$contextName])) {
                json_out(['ok' => false, 'error' => 'Context bulunamadı'], 404);
            }
            json_out([
                'ok' => true,
                'context' => $contextName,
                'output' => pbx_cli('dialplan show ' . $contextName),
            ]);

        case 'custom_context_save':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Yetkisiz işlem'], 403);
            }
            if (!custom_context_schema_ready()) {
                json_out(['ok' => false, 'error' => 'Özel context veritabanı migration’ı uygulanmamış'], 503);
            }
            $dept = require_dept_id();
            $contextId = ast_sanitize_id((string) ($_POST['context_id'] ?? ''));
            if ($contextId === '') {
                json_out(['ok' => false, 'error' => 'Context kodu zorunlu'], 422);
            }
            $description = trim((string) ($_POST['description'] ?? ''));
            if (mb_strlen($description) > 255) {
                json_out(['ok' => false, 'error' => 'Açıklama 255 karakteri aşamaz'], 422);
            }
            try {
                $steps = custom_context_parse_steps((string) ($_POST['steps_text'] ?? ''));
                custom_context_save($dept, $contextId, $description, $steps);
            } catch (InvalidArgumentException $exception) {
                json_out(['ok' => false, 'error' => $exception->getMessage()], 422);
            }
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'custom_context_delete':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Yetkisiz işlem'], 403);
            }
            if (!custom_context_schema_ready()) {
                json_out(['ok' => false, 'error' => 'Özel context veritabanı migration’ı uygulanmamış'], 503);
            }
            $dept = ast_sanitize_id((string) ($_POST['dept'] ?? ''));
            $contextId = ast_sanitize_id((string) ($_POST['context_id'] ?? ''));
            if ($dept === '' || $contextId === '' || custom_context_find($dept, $contextId) === null) {
                json_out(['ok' => false, 'error' => 'Özel context bulunamadı'], 404);
            }
            custom_context_delete($dept, $contextId);
            json_out(array_merge(['ok' => true], apply_to_pbx()));

        case 'sound_list':
            json_out(['ok' => true, 'files' => pbx_custom_sounds(current_dept_id()), 'moh' => pbx_moh_files(current_dept_id())]);

        case 'sound_languages':
            json_out(['ok' => true, 'languages' => pbx_sound_languages()]);

        case 'sound_upload':
            $dept = require_dept_id();
            $kind = (string) ($_POST['kind'] ?? 'sound') === 'moh' ? 'moh' : 'sound';
            $res = pbx_upload_media($dept, $kind);
            json_out($res);

        case 'sound_delete':
            $path = (string) ($_POST['path'] ?? '');
            json_out(pbx_delete_media($path));

        case 'logs':
            $res = pbx_ssh('tail -n 80 /var/log/asterisk/full 2>/dev/null || tail -n 80 /var/log/asterisk/messages');
            json_out(['ok' => true, 'output' => $res['output']]);

        case 'ssl_issue':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            $domain = strtolower(trim((string) ($_POST['domain'] ?? PANEL_DOMAIN)));
            $email = trim((string) ($_POST['email'] ?? ''));
            if ($domain !== strtolower(PANEL_DOMAIN)
                || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1) {
                json_out(['ok' => false, 'error' => 'Geçersiz veya yapılandırılmamış alan adı']);
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                json_out(['ok' => false, 'error' => 'Geçerli bir bildirim e-postası girin']);
            }
            $res = pbx_ssh(
                "set -e\n"
                . 'certbot --nginx --non-interactive --agree-tos --redirect --keep-until-expiring '
                . '--email ' . escapeshellarg($email) . ' -d ' . escapeshellarg($domain)
                . "\nsystemctl enable --now certbot.timer"
                . "\nsystemctl is-active certbot.timer"
            );
            json_out([
                'ok' => !empty($res['ok']),
                'output' => (string) ($res['output'] ?? ''),
                'error' => !empty($res['ok']) ? null
                    : 'Sertifika alınamadı. DNS ile 80/443 yönlendirmesini kontrol edin.' . "\n"
                    . (string) ($res['output'] ?? ''),
            ], !empty($res['ok']) ? 200 : 422);

        case 'ssl_renew_test':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            $res = pbx_ssh('certbot renew --dry-run --no-random-sleep-on-renew');
            json_out([
                'ok' => !empty($res['ok']),
                'output' => (string) ($res['output'] ?? ''),
                'error' => !empty($res['ok']) ? null
                    : 'Yenileme testi başarısız' . "\n" . (string) ($res['output'] ?? ''),
            ], !empty($res['ok']) ? 200 : 422);

        case 'security_firewall_apply':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            $management = security_networks($_POST['management_networks'] ?? '');
            $sipNetworks = security_networks($_POST['sip_networks'] ?? '');
            $currentIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            if (filter_var($currentIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $management[] = $currentIp . '/32';
                $management = array_values(array_unique($management));
            }
            if (!$management) {
                json_out(['ok' => false, 'error' => 'En az bir yönetim IP/ağı gereklidir'], 422);
            }
            if (!$sipNetworks) {
                json_out(['ok' => false, 'error' => 'En az bir SIP operatörü veya telefon ağı gereklidir'], 422);
            }
            $publicWeb = !empty($_POST['public_web']);
            $commands = [
                'set -e',
                'ufw --force reset',
                'ufw default deny incoming',
                'ufw default allow outgoing',
                'ufw logging medium',
                'ufw allow in on lo',
                'ufw allow 80/tcp comment astera-http-certbot',
            ];
            if ($publicWeb) {
                $commands[] = 'ufw allow 443/tcp comment astera-https';
            }
            foreach ($management as $network) {
                $source = escapeshellarg($network);
                $commands[] = "ufw allow from {$source} to any port 22 proto tcp comment astera-management-ssh";
                $commands[] = "ufw allow from {$source} to any port 5432 proto tcp comment astera-management-db";
                if (!$publicWeb) {
                    $commands[] = "ufw allow from {$source} to any port 443 proto tcp comment astera-management-web";
                }
            }
            foreach ($sipNetworks as $network) {
                $source = escapeshellarg($network);
                $commands[] = "ufw allow from {$source} to any port 5060 proto udp comment astera-sip-udp";
                $commands[] = "ufw allow from {$source} to any port 5060 proto tcp comment astera-sip-tcp";
                $commands[] = "ufw allow from {$source} to any port 5061 proto tcp comment astera-sip-tls";
            }
            foreach (array_values(array_unique(array_merge($management, $sipNetworks))) as $network) {
                $source = escapeshellarg($network);
                $commands[] = "ufw allow from {$source} to any port 8088 proto tcp comment astera-webrtc-ws";
                $commands[] = "ufw allow from {$source} to any port 8089 proto tcp comment astera-webrtc-wss";
            }
            if ($publicWeb) {
                // Public WebRTC clients use dynamic source IP addresses. SIP signalling stays
                // behind HTTPS/WSS, but their encrypted media must reach Asterisk directly.
                $commands[] = 'ufw allow 10000:20000/udp comment astera-webrtc-rtp';
            } else {
                foreach ($sipNetworks as $network) {
                    $source = escapeshellarg($network);
                    $commands[] = "ufw allow from {$source} to any port 10000:20000 proto udp comment astera-rtp";
                }
            }
            $rollbackUnit = 'astera-firewall-rollback-' . date('YmdHis');
            $commands[] = 'ufw --force enable';
            $commands[] = 'systemd-run --quiet --unit=' . escapeshellarg($rollbackUnit)
                . ' --on-active=5m /usr/sbin/ufw --force disable';
            $commands[] = 'ufw status numbered';
            $res = pbx_ssh(implode("\n", $commands));
            if (!empty($res['ok'])) {
                store_upsert('security_settings', [
                    'id' => 'firewall',
                    'management_networks' => $management,
                    'sip_networks' => $sipNetworks,
                    'public_web' => $publicWeb,
                    'rollback_unit' => $rollbackUnit,
                    'confirmed' => false,
                    'applied_at' => date(DATE_ATOM),
                    'applied_by' => store_actor(),
                ], 'id');
            }
            json_out([
                'ok' => !empty($res['ok']),
                'output' => (string) ($res['output'] ?? ''),
                'rollback_unit' => $rollbackUnit,
                'error' => !empty($res['ok']) ? null : 'Güvenlik duvarı uygulanamadı',
            ], !empty($res['ok']) ? 200 : 422);

        case 'security_firewall_confirm':
            if (!is_super()) {
                json_out(['ok' => false, 'error' => 'Sadece sistem yöneticisi'], 403);
            }
            $settings = find_by('security_settings', 'id', 'firewall');
            $unit = ast_sanitize_id((string) ($settings['rollback_unit'] ?? ''));
            if ($unit === '') {
                json_out(['ok' => false, 'error' => 'Onay bekleyen güvenlik duvarı işlemi yok'], 422);
            }
            $res = pbx_ssh(
                'systemctl stop ' . escapeshellarg($unit . '.timer') . ' '
                . escapeshellarg($unit . '.service') . " 2>/dev/null || true\n"
                . 'systemctl reset-failed ' . escapeshellarg($unit . '.service') . " 2>/dev/null || true\n"
                . 'ufw status numbered'
            );
            $settings['confirmed'] = true;
            $settings['confirmed_at'] = date(DATE_ATOM);
            store_upsert('security_settings', $settings, 'id');
            json_out(['ok' => true, 'output' => (string) ($res['output'] ?? '')]);

        case 'apply':
            json_out(array_merge(['ok' => true], apply_to_pbx(true)));

        case 'reload':
            $applyId = store_apply_begin();
            $res = pbx_ssh("asterisk -rx 'core reload'");
            store_apply_finish($applyId, !empty($res['ok']), (string) ($res['output'] ?? ''));
            json_out(['ok' => $res['ok'], 'output' => $res['output']]);

        default:
            json_out(['ok' => false, 'error' => 'Bilinmeyen işlem'], 400);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
