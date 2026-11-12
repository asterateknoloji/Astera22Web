<?php
declare(strict_types=1);

session_name('ASTERA_CALLCENTER_AGENT');
require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Location: ' . (!empty($_SESSION['callcenter_agent']) ? 'agent_dashboard_v2.php' : 'login.php'));
exit;
