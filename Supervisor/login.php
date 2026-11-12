<?php
declare(strict_types=1);

session_name('ASTERA_CALLCENTER_SUPERVISOR');
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/callcenter.php';
require_once dirname(__DIR__) . '/includes/ui_icons.php';
if (!empty($_SESSION['callcenter_supervisor'])) {
    header('Location: dashboard.php');
    exit;
}
$error = '';
$departments = departments();
$selectedDept = trim((string) ($_POST['dept'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $error = 'İstek doğrulanamadı. Sayfayı yenileyin.';
    } else {
        $dept = $selectedDept;
        $user = $dept !== '' ? callcenter_supervisor_authenticate(
            $dept,
            trim((string) ($_POST['username'] ?? '')),
            (string) ($_POST['password'] ?? '')
        ) : null;
        if ($user) {
            session_regenerate_id(true);
            $_SESSION['callcenter_supervisor'] = $user;
            $_SESSION['callcenter_supervisor_dept'] = $dept;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            callcenter_audit_write(
                $user,
                'login',
                'login',
                'Supervisor girişi',
                '',
                'auth',
                '/Supervisor/login.php'
            );
            header('Location: ' . (!empty($user['must_change_password'])
                ? 'change_password.php'
                : 'dashboard.php'));
            exit;
        }
        $error = 'Giriş bilgileri geçersiz.';
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Supervisor Girişi · Astera</title>
    <link rel="stylesheet" href="../assets/css/tokens.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/css/tokens.css') ?>">
    <link rel="stylesheet" href="assets/css/auth.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/auth.css') ?>">
</head>
<body class="auth-page">
<main class="auth-shell">
    <div class="auth-brand"><span class="sidebar-logo" aria-hidden="true">A</span><span><strong>ASTERA</strong><small>SUPERVISOR</small></span></div>
    <section class="auth-card">
        <h1><?= ui_icon('shield-check') ?> Supervisor girişi</h1>
        <p>Operasyon ve çağrı merkezi yönetim paneli</p>
        <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= ui_icon('circle-alert') ?><span><?= e($error) ?></span></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label for="dept"><span><?= ui_icon('building-2') ?> Firma / departman</span>
                <select id="dept" name="dept" required>
                    <option value="">Firma seçin</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>"
                                <?= (string) $department['id'] === $selectedDept ? 'selected' : '' ?>>
                            <?= e((string) ($department['name'] ?? $department['id'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="username"><span><?= ui_icon('user-round') ?> Kullanıcı adı</span>
                <input id="username" name="username" autocomplete="username" required autofocus>
            </label>
            <label for="password"><span><?= ui_icon('lock') ?> Parola</span>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </label>
            <button class="btn btn-primary" type="submit"><?= ui_icon('log-in') ?> Giriş yap</button>
        </form>
    </section>
    <div class="auth-foot"><?= ui_icon('shield-check') ?> © 2026 Astera Call Center · Güvenli yönetim alanı</div>
</main>
</body>
</html>
