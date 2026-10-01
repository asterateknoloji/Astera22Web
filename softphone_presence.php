<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $respond(['ok' => false, 'error' => 'GET gerekli'], 405);
}

$authorization = trim((string) (
    $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? ''
));
if ($authorization === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp((string) $name, 'Authorization') === 0) {
            $authorization = trim((string) $value);
            break;
        }
    }
}
if (!preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/i', $authorization, $match)) {
    $respond(['ok' => false, 'error' => 'SIP kayıt bilgileri gerekli'], 401);
}
$decoded = base64_decode($match[1], true);
if ($decoded === false || !str_contains($decoded, ':')) {
    $respond(['ok' => false, 'error' => 'SIP kayıt bilgileri geçersiz'], 401);
}
[$authUser, $sipPassword] = explode(':', $decoded, 2);
$sipUser = trim((string) ($_GET['sip_user'] ?? ''));
$extension = null;
foreach (store_read('extensions') as $candidate) {
    $candidateSip = sip_user($candidate);
    $candidateAuth = trim((string) (
        ($candidate['authuser'] ?? '') ?: $candidateSip
    ));
    if (
        $sipUser !== ''
        && hash_equals($candidateSip, $sipUser)
        && hash_equals($candidateAuth, trim($authUser))
        && hash_equals((string) ($candidate['password'] ?? ''), $sipPassword)
    ) {
        $extension = $candidate;
        break;
    }
}
if (!$extension) {
    $respond(['ok' => false, 'error' => 'SIP hesabı doğrulanamadı'], 401);
}
$dept = trim((string) ($extension['dept'] ?? ''));
if ($dept === '') {
    $respond(['ok' => false, 'error' => 'SIP hesabının firma bilgisi yok'], 422);
}

try {
    $status = pbx_blf_status($dept);
    $extensions = array_values(array_map(
        static fn (array $row): array => [
            'extension' => (string) ($row['extension'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'state' => (string) ($row['state'] ?? 'offline'),
            'label' => (string) ($row['label'] ?? 'Çevrimdışı'),
            'peer' => (string) ($row['peer'] ?? ''),
            'direction' => (string) ($row['direction'] ?? ''),
            'elapsed_seconds' => max(0, (int) ($row['call_age'] ?? 0)),
        ],
        array_values((array) ($status['extensions'] ?? []))
    ));
    usort(
        $extensions,
        static fn (array $a, array $b): int =>
            strnatcasecmp($a['extension'], $b['extension'])
    );
    $urlTrigger = find_by('url_triggers', 'dept', $dept);
    $browserTrigger = null;
    if (
        $urlTrigger
        && url_trigger_enabled_for_extension($urlTrigger, $extension)
        && in_array((string) ($urlTrigger['mode'] ?? 'both'), ['browser', 'both'], true)
    ) {
        $browserTrigger = [
            'url_template' => (string) ($urlTrigger['url_template'] ?? ''),
            'trigger' => (string) ($urlTrigger['trigger'] ?? 'ring'),
            'number_format' => (string) ($urlTrigger['number_format'] ?? 'digits'),
            'min_digits' => max(1, min(32, (int) ($urlTrigger['min_digits'] ?? 7))),
            'department' => $dept,
            'extension' => (string) ($extension['exten'] ?? ''),
            'access_token' => softphone_crm_token_create($extension),
        ];
    }
    $respond([
        'ok' => !empty($status['ok']),
        'source' => 'ami',
        'extensions' => $extensions,
        'url_trigger' => $browserTrigger,
    ], !empty($status['ok']) ? 200 : 503);
} catch (Throwable) {
    $respond(
        ['ok' => false, 'error' => 'AMI meşguliyet bilgisi alınamadı'],
        503
    );
}
