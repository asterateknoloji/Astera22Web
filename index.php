<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pages = ['dashboard', 'departments', 'crm', 'extensions', 'webrtc', 'bulk', 'features', 'trunks', 'outbound', 'inbound', 'queues', 'ringgroups', 'ivr', 'time', 'announcements', 'conferences', 'parking', 'flow', 'disa', 'paging', 'speed', 'custom', 'urltriggers', 'sounds', 'blacklist', 'recordings', 'reports', 'cdr', 'queue_log', 'logs', 'security', 'ssl', 'system'];
$page = current_page();
if (!in_array($page, $pages, true) || !can_page($page)) {
    $page = 'dashboard';
}

$pageFile = __DIR__ . '/pages/' . $page . '.php';
include __DIR__ . '/includes/header.php';
include $pageFile;
include __DIR__ . '/includes/footer.php';
