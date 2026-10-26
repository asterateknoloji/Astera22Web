<?php
declare(strict_types=1);

session_name('ASTERA_CALLCENTER_SUPERVISOR');
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/callcenter.php';

if (empty($_SESSION['callcenter_supervisor'])) {
    header('Location: login.php');
    exit;
}
$user = (array) $_SESSION['callcenter_supervisor'];
if (empty($user['must_change_password'])) {
    header('Location: dashboard.php');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $error = 'İstek doğrulanamadı. Sayfayı yenileyin.';
    } elseif ((string) ($_POST['password'] ?? '') !== (string) ($_POST['password_confirm'] ?? '')) {
        $error = 'Yeni parolalar eşleşmiyor.';
    } else {
        try {
            callcenter_supervisor_change_password(
                (string) $user['dept_id'],
                (int) $user['supervisor_id'],
                (string) ($_POST['password'] ?? '')
            );
            $_SESSION['callcenter_supervisor']['must_change_password'] = false;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            callcenter_audit_write(
                $user,
                'update',
                'change_password',
                'Supervisor parolası değiştirildi',
                '',
                'auth',
                '/Supervisor/change_password.php'
            );
            header('Location: dashboard.php');
            exit;
        } catch (Throwable $exception) {
            $error = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'Parola değiştirilemedi. Lütfen tekrar deneyin.';
        }
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Parola Değiştir · Astera</title>
    <link rel="stylesheet" href="assets/css/theme.css?v=2">
</head>
<body class="auth-page">
<main class="auth-shell">
    <div class="auth-brand"><span class="sidebar-logo">A</span><span><strong>ASTERA</strong><small>CALL CENTER</small></span></div>
    <section class="auth-card">
        <h1>Yeni parola belirleyin</h1>
        <p>Varsayılan parola yalnızca ilk giriş içindir.</p>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label for="password">Yeni parola
                <input id="password" name="password" type="password" minlength="10"
                    autocomplete="new-password" required>
            </label>
            <label for="password_confirm">Yeni parola tekrar
                <input id="password_confirm" name="password_confirm" type="password" minlength="10"
                    autocomplete="new-password" required>
            </label>
            <button class="btn btn-primary" type="submit">Parolayı Değiştir</button>
        </form>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <button class="btn btn-outline" type="submit">Çıkış</button>
        </form>
    </section>
    <div class="auth-foot">Parolanız en az 10 karakter olmalıdır.</div>
</main>
</body>
</html>
