<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/pbx_repository.php';
require_once __DIR__ . '/ssh.php';
require_once __DIR__ . '/ami.php';
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/destinations.php';
require_once __DIR__ . '/contexts.php';
require_once __DIR__ . '/url_trigger.php';
require_once __DIR__ . '/generator.php';
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/crm_repository.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionOptions = [];
    if (!filter_var(ini_get('session.cookie_httponly'), FILTER_VALIDATE_BOOL)) {
        $sessionOptions['cookie_httponly'] = true;
    }
    if (strcasecmp((string) ini_get('session.cookie_samesite'), 'Lax') !== 0) {
        $sessionOptions['cookie_samesite'] = 'Lax';
    }
    $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    if ($https && !filter_var(ini_get('session.cookie_secure'), FILTER_VALIDATE_BOOL)) {
        $sessionOptions['cookie_secure'] = true;
    }
    session_start($sessionOptions);
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

store_init();
migrate_tenants();
