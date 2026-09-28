<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!empty($_SESSION['user'])) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['user'] ?? ''));
    $pass = (string) ($_POST['pass'] ?? '');
    if ($user === WEB_USER && $pass === WEB_PASS) {
        $_SESSION['user'] = $user;
        unset($_SESSION['user_dept']);
        $_SESSION['dept'] = '*';
        redirect('index.php');
    }
    foreach (departments() as $dept) {
        $pu = trim((string) ($dept['panel_user'] ?? ''));
        $pp = (string) ($dept['panel_pass'] ?? '');
        if ($pu !== '' && $pp !== '' && $pu === $user && $pp === $pass) {
            $_SESSION['user'] = $user;
            $_SESSION['user_dept'] = $dept['id'];
            $_SESSION['dept'] = $dept['id'];
            redirect('index.php');
        }
    }
    $error = 'Kullanıcı adı veya parola hatalı.';
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ASTERA · Giriş</title>
    <link rel="stylesheet" href="assets/css/app.css?v=20">
</head>
<body class="login-body">
    <main class="login-card">
        <div class="brand-mark">A</div>
        <h1>ASTERA</h1>
        <p class="muted">Asterisk 22 · çoklu firma santral</p>
        <?php if ($error): ?>
            <div class="alert bad"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <label>Kullanıcı
                <input name="user" value="admin" required>
            </label>
            <label>Parola
                <input type="password" name="pass" required>
            </label>
            <button class="btn primary" type="submit">Giriş yap</button>
        </form>
        <p class="tiny"><a href="webphone.php">WebPhone girişi</a></p>
        <p class="tiny muted">Santral <?= e(PBX_HOST) ?> · Asterisk 22.11.0</p>
    </main>
</body>
</html>
