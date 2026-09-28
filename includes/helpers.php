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
    return preg_replace('/[^a-z_]/', '', (string) ($_GET['p'] ?? 'dashboard')) ?: 'dashboard';
}

function nav_active(string $page): string
{
    return current_page() === $page ? ' active' : '';
}

function codecs_list(): array
{
    return ['alaw', 'ulaw', 'g722', 'gsm', 'opus'];
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
        'rrordered' => 'Sırayla (sabit sıralı)',
        'linear' => 'Sırayla',
        'wrandom' => 'Ağırlıklı rastgele',
    ];
}
