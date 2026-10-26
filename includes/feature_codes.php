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

function feature_code_inventory(string $deptId): array
{
    $codes = [
        '*2' => 'Kontrollü transfer',
        '*43' => 'Echo test',
        '*65' => 'Saat söyle',
        '*72' => 'Çağrı yönlendirme aç',
        '*73' => 'Çağrı yönlendirme kapat',
        '*78' => 'DND aç',
        '*79' => 'DND kapat',
        '*8' => 'Çağrı yakala',
        '*45' => 'Kuyrukta duraklat',
        '*46' => 'Kuyruk duraklatmayı kaldır',
        '*97' => 'Kendi mesaj kutusu',
        '*98' => 'Mesaj kutusu',
        '*411' => 'Rehber',
    ];
    foreach (store_read('extensions') as $extension) {
        if ((string) ($extension['dept'] ?? '') !== $deptId) continue;
        $number = preg_replace('/\D/', '', (string) ($extension['exten'] ?? '')) ?? '';
        if ($number !== '') $codes['*80' . $number] = 'Tekli anons: ' . $number;
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
