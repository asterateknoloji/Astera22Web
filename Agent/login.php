<?php
declare(strict_types=1);

session_name('ASTERA_CALLCENTER_AGENT');
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/callcenter.php';

if (!empty($_SESSION['callcenter_agent'])) {
    header('Location: agent_dashboard_v2.php');
    exit;
}
$error = '';
$departments = array_values(array_filter(departments(), static fn($d) => !isset($d['active']) || (bool) $d['active']));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $error = 'İstek doğrulanamadı. Sayfayı yenileyin.';
    } else {
        $dept = trim((string) ($_POST['dept'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $phoneMode = trim((string) ($_POST['phone_mode'] ?? ''));
        $agent = $dept !== '' ? callcenter_agent_authenticate($dept, $username, (string) ($_POST['password'] ?? '')) : null;
        if ($agent && callcenter_agent_queues($dept, (int) $agent['agent_id'])) {
            try {
                callcenter_validate_agent_phone_mode($agent, $phoneMode);
                $sessionId = callcenter_start_session($dept, (int) $agent['agent_id'], $phoneMode);
                $agent['phone_mode'] = $phoneMode;
                $agent['phone_mode_label'] = callcenter_phone_mode_labels()[$phoneMode];
                session_regenerate_id(true);
                $_SESSION['callcenter_agent'] = $agent;
                $_SESSION['callcenter_session_id'] = $sessionId;
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                header('Location: agent_dashboard_v2.php');
                exit;
            } catch (Throwable $exception) {
                error_log('Callcenter agent login failed: ' . $exception->getMessage());
                $error = $exception instanceof PDOException
                    ? 'Telefon ve Agent oturumu doğrulanamadı. Lütfen tekrar deneyin.'
                    : $exception->getMessage();
            }
        }
        if ($error === '') {
            $error = 'Giriş bilgileri geçersiz veya aktif kuyruk ataması yok.';
        }
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Agent Login - Agent Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/agent.css?v=8">
</head>
<body class="agent-login-page">
<main class="login-container">
    <header class="login-header">
        <h1><i class="fas fa-headset" aria-hidden="true"></i> Agent Dashboard</h1>
        <p>Agent Login Sistemi</p>
    </header>
    <section class="login-body">
        <?php if ($error !== ''): ?>
            <div class="error-message" role="alert">
                <i class="fas fa-exclamation-circle" aria-hidden="true"></i> <?= e($error) ?>
            </div>
        <?php endif; ?>
        <form method="post" id="login-form" autocomplete="on">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div class="form-group">
                <label for="dept"><i class="fas fa-building" aria-hidden="true"></i> Firma / Departman</label>
                <select id="dept" name="dept" required>
                    <option value="">Seçin</option>
                    <?php foreach ($departments as $department): ?>
                        <?php $deptId = (string) $department['id']; ?>
                        <option value="<?= e($deptId) ?>" <?= (string) ($_POST['dept'] ?? '') === $deptId ? 'selected' : '' ?>>
                            <?= e((string) ($department['name'] ?? $deptId)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="username"><i class="fas fa-user" aria-hidden="true"></i> Agent ID / Dahili</label>
                <input id="username" name="username" value="<?= e((string) ($_POST['username'] ?? '')) ?>"
                    placeholder="Örn. Agent_1001 veya 1001" autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="password"><i class="fas fa-lock" aria-hidden="true"></i> Şifre</label>
                <input id="password" name="password" type="password" placeholder="Şifrenizi girin"
                    autocomplete="current-password" required>
            </div>
            <fieldset class="phone-mode-picker">
                <legend><i class="fas fa-phone" aria-hidden="true"></i> Kullanılacak Telefon</legend>
                <div class="phone-mode-options">
                    <?php foreach ([
                        'webrtc' => ['fa-globe', 'WebRTC', 'Tarayıcı içindeki WebPhone'],
                        'softphone' => ['fa-headset', 'Softphone', 'Bilgisayardaki SIP uygulaması'],
                        'desk_phone' => ['fa-phone-alt', 'Masaüstü Telefon', 'Fiziksel IP telefon'],
                    ] as $mode => [$icon, $label, $description]): ?>
                    <label class="phone-mode-option">
                        <input type="radio" name="phone_mode" value="<?= e($mode) ?>"
                            <?= (string) ($_POST['phone_mode'] ?? 'webrtc') === $mode ? ' checked' : '' ?> required>
                        <span><i class="fas <?= e($icon) ?>" aria-hidden="true"></i><strong><?= e($label) ?></strong><small><?= e($description) ?></small></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="helper-text">Softphone ve masaüstü telefon santralde aktif değilse giriş tamamlanmaz.</div>
            </fieldset>
            <button type="submit" class="login-btn" id="login-btn">
                <i class="fas fa-sign-in-alt" aria-hidden="true"></i> Giriş Yap
            </button>
        </form>
    </section>
    <footer class="login-footer"><i class="fas fa-shield-alt" aria-hidden="true"></i>
        © 2026 Astera Call Center - Agent Dashboard
    </footer>
</main>
<script>
document.getElementById('login-form').addEventListener('submit', function () {
    const button = document.getElementById('login-btn');
    button.disabled = true;
    button.innerHTML = '<span class="spinner" aria-hidden="true"></span> Giriş yapılıyor...';
});
</script>
</body>
</html>
