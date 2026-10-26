<?php
declare(strict_types=1);

session_name('ASTERA_CALLCENTER_AGENT');
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/callcenter.php';
if (empty($_SESSION['callcenter_agent'])) {
    header('Location: login.php');
    exit;
}
$agent = (array) $_SESSION['callcenter_agent'];
$snapshot = callcenter_agent_snapshot($agent);
$agentWebphone = find_ext((string) $agent['dept_id'], (string) $agent['extension']);
$phoneMode = (string) ($agent['phone_mode'] ?? 'webrtc');
$phoneModeLabel = callcenter_phone_mode_labels()[$phoneMode] ?? 'Telefon';
$webphoneReady = $phoneMode === 'webrtc' && $agentWebphone && !empty($agentWebphone['webrtc']);
$initialCallAge = max(0, (int) ($snapshot['extension_status']['call_age'] ?? 0));
$initialCallDuration = sprintf(
    '%02d:%02d:%02d',
    intdiv($initialCallAge, 3600),
    intdiv($initialCallAge % 3600, 60),
    $initialCallAge % 60
);
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Agent Dashboard - <?= e((string) $agent['username']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/app.css?v=49">
    <link rel="stylesheet" href="assets/agent.css?v=8">
</head>
<body class="agent-dashboard-page">
<main class="dashboard-shell">
    <header class="dashboard-header">
        <div class="header-left">
            <div class="header-title"><i class="fas fa-headset" aria-hidden="true"></i> Agent Dashboard</div>
            <div class="agent-badge">Agent ID: <?= e((string) $agent['username']) ?> · <?= e((string) $agent['extension']) ?> · <?= e($phoneModeLabel) ?></div>
        </div>
        <div class="header-right">
            <div id="agent-status" class="status-badge status-online">
                <span id="agent-status-main">Müsait</span>
                <small id="agent-status-detail"></small>
            </div>
            <form method="post" action="logout.php" id="logout-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <button class="logout-btn" type="submit"><i class="fas fa-sign-out-alt"></i> Çıkış</button>
            </form>
        </div>
    </header>
    <div id="notice" class="notice" role="status"></div>
    <section class="dashboard-grid">
        <article class="panel panel-left">
            <header class="panel-header"><div class="panel-title"><span class="panel-icon"><i class="fas fa-list"></i></span>Kuyruk Durumları</div></header>
            <div class="panel-content"><div id="queue-list" class="queue-list"></div></div>
        </article>
        <article class="panel panel-center">
            <header class="panel-header"><div class="panel-title"><span class="panel-icon icon-green"><i class="fas fa-phone-alt"></i></span><?= e($phoneModeLabel) ?> · <?= e((string) $agent['extension']) ?></div></header>
            <div class="panel-content agent-webphone">
                <?php if ($webphoneReady): ?>
                    <script>
                    window.ASTERA = Object.assign(window.ASTERA || {}, {
                        csrf: <?= json_encode(csrf_token()) ?>,
                        dept: <?= json_encode((string) $agent['dept_id']) ?>,
                        pbxHost: <?= json_encode(PBX_HOST) ?>,
                        ws: <?= json_encode(pbx_ws_url()) ?>,
                        wss: <?= json_encode(pbx_wss_url()) ?>,
                        webphoneAutoConnect: true,
                        webphoneStatusUrl: '../webphone_status.php?scope=agent',
                        webphoneCustomerUrl: '../webphone_customer.php?scope=agent',
                        webphonePendingDial: ''
                    });
                    window.Astera = window.Astera || {
                        _t: null,
                        toast(message, bad = false) {
                            const element = document.getElementById('notice');
                            if (!element) return;
                            element.textContent = message;
                            element.className = `notice show ${bad ? 'error' : 'success'}`;
                            clearTimeout(this._t);
                            this._t = setTimeout(() => element.classList.remove('show'), 5000);
                        }
                    };
                    </script>
                    <?php
                    $webphoneMode = true;
                    $agentDashboardMode = true;
                    $webphoneExtension = $agentWebphone;
                    $assetPrefix = '../assets/';
                    $rootPrefix = '../';
                    include dirname(__DIR__) . '/pages/webrtc.php';
                    ?>
                <?php elseif (in_array($phoneMode, ['softphone', 'desk_phone'], true)): ?>
                    <div class="external-phone-active">
                        <i class="fas <?= $phoneMode === 'softphone' ? 'fa-headset' : 'fa-phone-alt' ?>" aria-hidden="true"></i>
                        <strong><?= e($phoneModeLabel) ?> · <?= e((string) $agent['extension']) ?></strong>
                        <span id="external-phone-help">Telefon hazır, çağrı bekleniyor.</span>
                        <div class="external-call-display" id="external-call-display" data-state="<?= e((string) ($snapshot['extension_status']['state'] ?? 'available')) ?>">
                            <span id="external-call-label"><?= e((string) ($snapshot['extension_status']['label'] ?? 'Müsait')) ?></span>
                            <b id="external-call-number"><?= e((string) (($snapshot['extension_status']['peer'] ?? '') ?: '—')) ?></b>
                            <strong id="external-call-duration"><?= e($initialCallDuration) ?></strong>
                        </div>
                        <div class="external-phone-state">
                            <b id="external-device-state"><?= e((string) ($snapshot['extension_status']['label'] ?? 'Aktif')) ?></b>
                            <b>Dahili <?= e((string) $agent['extension']) ?></b>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="webphone-unavailable">
                        <i class="fas fa-triangle-exclamation" aria-hidden="true"></i>
                        <strong>Telefon kullanıma hazır değil</strong>
                        <span>Çıkış yapıp abone için etkin bir telefon türüyle tekrar giriş yapın.</span>
                    </div>
                <?php endif; ?>
            </div>
        </article>
        <article class="panel">
            <header class="panel-header"><div class="panel-title"><span class="panel-icon icon-pink"><i class="fas fa-phone-volume"></i></span>Bekleyen Çağrılar</div></header>
            <div class="panel-content table-wrap" id="waiting-calls"></div>
        </article>
        <article class="panel">
            <header class="panel-header"><div class="panel-title"><span class="panel-icon icon-orange"><i class="fas fa-coffee"></i></span>Mola Yönetimi</div></header>
            <div class="panel-content">
                <div class="break-time-grid">
                    <div class="time-card">Login Süresi<strong id="login-duration">00:00:00</strong></div>
                    <div class="time-card break">Bugün Mola<strong id="break-duration">00:00:00</strong></div>
                </div>
                <div id="break-controls"></div>
            </div>
        </article>
    </section>
    <section class="panel stats-panel">
        <header class="panel-header stats-head">
            <div class="panel-title"><span class="panel-icon icon-blue"><i class="fas fa-chart-line"></i></span>Günlük İstatistikler</div>
            <div id="daily-stats" class="stat-cards"></div>
        </header>
        <div class="panel-content">
            <h3>Son 10 Çağrı</h3>
            <div class="table-wrap" id="recent-calls"></div>
        </div>
    </section>
</main>
<script>
window.AGENT_APP = <?= json_encode([
    'csrf' => csrf_token(),
    'snapshot' => $snapshot,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="assets/agent.js?v=6"></script>
</body>
</html>
