<?php
declare(strict_types=1);

require_once __DIR__ . '/callcenter.php';

function feature_takeover_defaults(string $deptId): array
{
    return [
        'dept_id' => $deptId,
        'enabled' => false,
        'activation_prefix' => '*88',
        'cancel_code' => '*89',
    ];
}

function feature_takeover_settings(string $deptId): array
{
    try {
        return callcenter_rows(
            'SELECT * FROM feature_takeover_settings WHERE dept_id=:dept',
            ['dept' => $deptId]
        )[0] ?? feature_takeover_defaults($deptId);
    } catch (Throwable $exception) {
        error_log('Dahili devralma ayarı okunamadı: ' . $exception->getMessage());
        return feature_takeover_defaults($deptId);
    }
}

function feature_takeover_bool(mixed $value): bool
{
    return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
}

function feature_code_definitions(): array
{
    return [
        'echo_test' => ['label' => 'Echo test', 'default_code' => '*43', 'description' => 'Ses gecikmesi ve çift yönlü sesi test eder.'],
        'speaking_clock' => ['label' => 'Saat söyle', 'default_code' => '*65', 'description' => 'Sistem tarih ve saatini seslendirir.'],
        'call_forward_activate' => ['label' => 'Tüm çağrıları yönlendir', 'default_code' => '*72', 'description' => 'Yönlendirilecek hedef numarayı sorar.'],
        'call_forward_deactivate' => ['label' => 'Tüm çağrı yönlendirmesini kapat', 'default_code' => '*73', 'description' => 'Dahilinin yönlendirmesini kaldırır.'],
        'dnd_activate' => ['label' => 'Rahatsız etmeyin aç', 'default_code' => '*78', 'description' => 'Dahili için DND durumunu etkinleştirir.'],
        'dnd_deactivate' => ['label' => 'Rahatsız etmeyin kapat', 'default_code' => '*79', 'description' => 'Dahili için DND durumunu kapatır.'],
        'call_pickup' => ['label' => 'Çağrı toplama', 'default_code' => '*8', 'description' => 'Aynı pickup grubundaki çalan çağrıyı alır.'],
        'queue_pause' => ['label' => 'Kuyrukta duraklat', 'default_code' => '*45', 'description' => 'Telefonu kuyruklarda geçici olarak duraklatır.'],
        'queue_unpause' => ['label' => 'Kuyruk duraklatmasını kaldır', 'default_code' => '*46', 'description' => 'Telefonu kuyruklarda yeniden aktif eder.'],
        'voicemail_self' => ['label' => 'Kendi mesaj kutusu', 'default_code' => '*97', 'description' => 'Arayan dahilinin mesaj kutusunu açar.'],
        'voicemail_prompt' => ['label' => 'Mesaj kutusu seç', 'default_code' => '*98', 'description' => 'Açılacak mesaj kutusu numarasını sorar.'],
        'directory' => ['label' => 'Dahili rehberi', 'default_code' => '*411', 'description' => 'Mesaj kutusu adı bulunan aboneleri sesli listeler.'],
        'intercom_prefix' => ['label' => 'Tekli anons başlangıcı', 'default_code' => '*80', 'description' => 'Dahili numarası sona eklenir; örneğin *80 + 1001.'],
    ];
}

function feature_code_defaults(string $deptId): array
{
    $rows = [];
    foreach (feature_code_definitions() as $key => $definition) {
        $rows[$key] = [
            'dept_id' => $deptId,
            'feature_key' => $key,
            'code' => (string) $definition['default_code'],
            'enabled' => true,
            'label' => (string) $definition['label'],
            'description' => (string) $definition['description'],
            'is_default' => true,
        ];
    }
    return $rows;
}

function feature_code_settings(string $deptId): array
{
    $settings = feature_code_defaults($deptId);
    try {
        foreach (callcenter_rows(
            'SELECT feature_key,code,enabled FROM feature_code_settings WHERE dept_id=:dept',
            ['dept' => $deptId]
        ) as $row) {
            $key = (string) ($row['feature_key'] ?? '');
            if (!isset($settings[$key])) continue;
            $settings[$key]['code'] = (string) ($row['code'] ?? $settings[$key]['code']);
            $settings[$key]['enabled'] = feature_takeover_bool($row['enabled'] ?? true);
            $settings[$key]['is_default'] =
                $settings[$key]['code'] === feature_code_definitions()[$key]['default_code']
                && $settings[$key]['enabled'];
        }
    } catch (Throwable $exception) {
        error_log('Özellik kodları okunamadı: ' . $exception->getMessage());
    }
    return $settings;
}

function feature_code_value(array $settings, string $key): string
{
    return !empty($settings[$key]['enabled']) ? (string) ($settings[$key]['code'] ?? '') : '';
}

function feature_code_inventory(string $deptId): array
{
    $codes = ['*2' => 'Kontrollü transfer'];
    $settings = feature_code_settings($deptId);
    foreach ($settings as $setting) {
        if (!empty($setting['enabled']) && (string) $setting['code'] !== '') {
            $codes[(string) $setting['code']] = (string) $setting['label'];
        }
    }
    $intercomPrefix = feature_code_value($settings, 'intercom_prefix');
    foreach (store_read('extensions') as $extension) {
        if ((string) ($extension['dept'] ?? '') !== $deptId) continue;
        $number = preg_replace('/\D/', '', (string) ($extension['exten'] ?? '')) ?? '';
        if ($number !== '' && $intercomPrefix !== '') {
            $codes[$intercomPrefix . $number] = 'Tekli anons: ' . $number;
        }
    }
    foreach (store_read('flows') as $flow) {
        if ((string) ($flow['dept'] ?? '') !== $deptId) continue;
        $code = trim((string) ($flow['feature'] ?? ''));
        if (preg_match('/^\*[0-9]{2,20}$/D', $code) === 1) {
            $codes[$code] = 'Gündüz/gece: ' . (string) ($flow['name'] ?? $code);
        }
    }
    foreach (store_read('speeddials') as $speedDial) {
        if ((string) ($speedDial['dept'] ?? '') !== $deptId) continue;
        $local = preg_replace('/\D/', '', (string) ($speedDial['code'] ?? '')) ?? '';
        if ($local !== '') $codes['*' . $local] = 'Hızlı arama: ' . $local;
    }
    return $codes;
}

function feature_code_settings_conflicts(string $deptId, array $settings): array
{
    $conflicts = [];
    $used = [];
    foreach ($settings as $key => $setting) {
        if (empty($setting['enabled'])) continue;
        $code = (string) ($setting['code'] ?? '');
        if (isset($used[$code])) {
            $conflicts[] = "{$code} iki işlevde kullanılıyor: {$used[$code]} ve "
                . (string) ($setting['label'] ?? $key);
        } else {
            $used[$code] = (string) ($setting['label'] ?? $key);
        }
    }

    $external = ['*2' => 'Kontrollü transfer'];
    foreach (store_read('extensions') as $extension) {
        if ((string) ($extension['dept'] ?? '') !== $deptId) continue;
        $number = preg_replace('/\D/', '', (string) ($extension['exten'] ?? '')) ?? '';
        if ($number !== '') $external[$number] = 'Dahili: ' . $number;
    }
    foreach (store_read('flows') as $flow) {
        if ((string) ($flow['dept'] ?? '') !== $deptId) continue;
        $code = trim((string) ($flow['feature'] ?? ''));
        if ($code !== '') $external[$code] = 'Gündüz/gece: ' . (string) ($flow['name'] ?? $code);
    }
    foreach (store_read('speeddials') as $speedDial) {
        if ((string) ($speedDial['dept'] ?? '') !== $deptId) continue;
        $local = preg_replace('/\D/', '', (string) ($speedDial['code'] ?? '')) ?? '';
        if ($local !== '') $external['*' . $local] = 'Hızlı arama: ' . $local;
    }
    $takeover = feature_takeover_settings($deptId);
    if (feature_takeover_bool($takeover['enabled'] ?? false)) {
        $external[(string) $takeover['cancel_code']] = 'Dahili devralmayı iptal et';
        foreach (store_read('extensions') as $extension) {
            if ((string) ($extension['dept'] ?? '') !== $deptId) continue;
            $number = preg_replace('/\D/', '', (string) ($extension['exten'] ?? '')) ?? '';
            if ($number !== '') {
                $external[(string) $takeover['activation_prefix'] . $number] =
                    'Dahili devralma: ' . $number;
            }
        }
    }
    foreach ($used as $code => $label) {
        if (isset($external[$code])) {
            $conflicts[] = "{$code} kullanımda: {$external[$code]} ({$label})";
        }
    }

    $intercomPrefix = feature_code_value($settings, 'intercom_prefix');
    if ($intercomPrefix !== '') {
        foreach (store_read('extensions') as $extension) {
            if ((string) ($extension['dept'] ?? '') !== $deptId) continue;
            $number = preg_replace('/\D/', '', (string) ($extension['exten'] ?? '')) ?? '';
            $generated = $intercomPrefix . $number;
            if ($number !== '' && isset($used[$generated])) {
                $conflicts[] = "{$generated} hem tekli anons hem {$used[$generated]} için kullanılıyor";
            }
            if ($number !== '' && isset($external[$generated])) {
                $conflicts[] = "{$generated} kullanımda: {$external[$generated]}";
            }
        }
    }
    return array_values(array_unique($conflicts));
}

function feature_takeover_conflicts(
    string $deptId,
    string $activationPrefix,
    string $cancelCode
): array {
    $inventory = feature_code_inventory($deptId);
    $conflicts = [];
    if (isset($inventory[$cancelCode])) {
        $conflicts[] = "{$cancelCode} kullanımda: {$inventory[$cancelCode]}";
    }
    foreach (store_read('extensions') as $extension) {
        if ((string) ($extension['dept'] ?? '') !== $deptId) continue;
        $target = preg_replace('/\D/', '', (string) ($extension['exten'] ?? '')) ?? '';
        if ($target === '') continue;
        $activationCode = $activationPrefix . $target;
        if ($activationCode === $cancelCode) {
            $conflicts[] = "{$activationCode} hem devralma hem iptal kodu oluyor";
        }
        if (isset($inventory[$activationCode])) {
            $conflicts[] = "{$activationCode} kullanımda: {$inventory[$activationCode]}";
        }
    }
    return array_values(array_unique($conflicts));
}
