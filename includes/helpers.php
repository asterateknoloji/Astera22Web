<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string
{
    return (string) ($_SESSION['csrf'] ?? '');
}

function csrf_ok(?string $token = null): bool
{
    $token ??= $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    return is_string($token) && hash_equals(csrf_token(), $token);
}

function extension_pickup_groups(mixed $value, bool $strict = false): array
{
    $parts = is_array($value)
        ? $value
        : (preg_split('/[\s,;|\/-]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $groups = [];
    foreach ($parts as $part) {
        $text = trim((string) $part);
        if (preg_match('/^\d{1,2}$/D', $text) !== 1 || (int) $text < 1 || (int) $text > 63) {
            if ($strict && $text !== '') {
                throw new InvalidArgumentException('Çağrı toplama grupları 1-63 arasında olmalıdır.');
            }
            continue;
        }
        $groups[(int) $text] = (int) $text;
    }
    $groups = array_values($groups);
    sort($groups, SORT_NUMERIC);
    if ($strict && !$groups) {
        throw new InvalidArgumentException('En az bir çağrı toplama grubu girin.');
    }
    if (count($groups) > 32) {
        throw new InvalidArgumentException('Bir abone en fazla 32 çağrı toplama grubuna üye olabilir.');
    }
    return $groups;
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_login(): void
{
    if (empty($_SESSION['user'])) {
        if (is_api_request()) {
            json_out(['ok' => false, 'error' => 'Oturum gerekli'], 401);
        }
        redirect('login.php');
    }
}

function is_api_request(): bool
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return $script === 'api.php';
}

function current_page(): string
{
    return preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['p'] ?? 'dashboard')) ?: 'dashboard';
}

function nav_active(string $page): string
{
    return current_page() === $page ? ' active' : '';
}

function codecs_list(): array
{
    return ['alaw', 'ulaw', 'g722', 'gsm', 'g729', 'opus'];
}

function pbx_ws_url(): string
{
    return 'ws://' . PBX_HOST . ':8088/ws';
}

function pbx_wss_url(): string
{
    return 'wss://' . PBX_HOST . ':8089/ws';
}

function ring_strategies(): array
{
    return [
        'ringall' => 'Tümü çalsın',
        'leastrecent' => 'En uzun süredir bekleyen',
        'fewestcalls' => 'En az çağrı alan',
        'random' => 'Rastgele',
        'rrmemory' => 'Sırayla (hafızalı)',
        'rrordered' => 'Döngülü sıra (her çağrıda kaldığı yerden)',
        'linear' => 'Sabit üye sırası (1 → 2 → 3)',
        'wrandom' => 'Ağırlıklı rastgele',
    ];
}

function normalize_group_member(string $value): string
{
    $value = trim($value);
    if (preg_match('/^([0-9]{7,20})#$/D', $value, $matches) === 1) {
        return $matches[1] . '#';
    }
    return preg_replace('/\D/', '', $value) ?? '';
}

function group_member_is_external(string $value): bool
{
    return preg_match('/^[0-9]{7,20}#$/D', $value) === 1;
}
