<?php
declare(strict_types=1);

session_name('ASTERA_CALLCENTER_SUPERVISOR');
require_once dirname(__DIR__) . '/includes/bootstrap.php';
header('Location: ' . (!empty($_SESSION['callcenter_supervisor']) ? 'dashboard.php' : 'login.php'));
exit;
