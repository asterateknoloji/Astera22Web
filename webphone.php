<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Cache-Control: no-store, private');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

function webphone_rate_file(string $ip): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'astera-webphone-' . hash('sha256', $ip) . '.json';
}

function webphone_rate_state(string $ip, bool $failed = false, bool $reset = false): array
{
    $file = webphone_rate_file($ip);
    if ($reset) {
        @unlink($file);
        return ['count' => 0, 'window' => time(), 'locked_until' => 0];
    }

    $handle = fopen($file, 'c+');
    if ($handle === false) {
        return ['count' => 0, 'window' => time(), 'locked_until' => 0];
    }
    flock($handle, LOCK_EX);
    rewind($handle);
    $state = json_decode((string) stream_get_contents($handle), true);
    if (!is_array($state)) {
        $state = [];
    }
    $now = time();
    $window = (int) ($state['window'] ?? $now);
    if ($now - $window >= 600) {
        $state = ['count' => 0, 'window' => $now, 'locked_until' => 0];
    }
    $state += ['count' => 0, 'window' => $now, 'locked_until' => 0];
    if ($failed) {
        $state['count'] = (int) $state['count'] + 1;
        if ($state['count'] >= 10) {
            $state['locked_until'] = $now + 600;
        }
    }
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($state, JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $state;
}

$error = '';
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!csrf_ok()) {
        $error = 'Oturum doğrulaması başarısız.';
    } else {
        unset($_SESSION['webphone_extension_id']);
        redirect('webphone.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $rate = webphone_rate_state($clientIp);
    if ((int) ($rate['locked_until'] ?? 0) > time()) {
        $error = 'Çok fazla hatalı deneme yapıldı. 10 dakika sonra tekrar deneyin.';
    } elseif (!csrf_ok()) {
        $error = 'Oturum doğrulaması başarısız.';
    } else {
        $extension = preg_replace('/\D/', '', (string) ($_POST['extension'] ?? '')) ?? '';
        $pin = trim((string) ($_POST['pin'] ?? ''));
        $matches = [];
        foreach (store_read('extensions') as $row) {
            $rowPin = (string) ($row['vm_pin'] ?? '');
            if (
                !empty($row['webrtc'])
                && (string) ($row['exten'] ?? '') === $extension
                && $rowPin !== ''
                && hash_equals($rowPin, $pin)
            ) {
                $matches[] = $row;
            }
        }
        if (count($matches) === 1) {
            session_regenerate_id(true);
            $_SESSION['webphone_extension_id'] = (string) ($matches[0]['id'] ?? '');
            webphone_rate_state($clientIp, false, true);
            redirect('webphone.php');
        }
        usleep(400000);
        webphone_rate_state($clientIp, true);
        $error = 'Dahili numarası veya PIN hatalı.';
    }
}

$webphoneExtension = null;
$webphoneId = (string) ($_SESSION['webphone_extension_id'] ?? '');
if ($webphoneId !== '') {
    $candidate = find_by('extensions', 'id', $webphoneId);
    if ($candidate && !empty($candidate['webrtc'])) {
        $webphoneExtension = $candidate;
    } else {
        unset($_SESSION['webphone_extension_id']);
    }
}
if ($webphoneExtension) {
    try {
        crm_local_caller_names((string) ($webphoneExtension['dept'] ?? ''));
    } catch (Throwable) {
        // CRM geçici olarak erişilemezse WebPhone çalışmaya devam etsin.
    }
}
$pendingDial = preg_replace('/[^0-9+*#]/', '', substr((string) ($_GET['dial'] ?? ''), 0, 32)) ?? '';
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ASTERA · WebPhone</title>
    <link rel="stylesheet" href="assets/css/app.css?v=42">
</head>
<body class="login-body webphone-body">
<?php if (!$webphoneExtension): ?>
    <main class="login-card">
        <div class="brand-mark">A</div>
        <h1>WEBPHONE</h1>
        <p class="muted">Dahili numaranız ve PIN’iniz ile giriş yapın.</p>
        <?php if ($error !== ''): ?>
            <div class="alert bad"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label>Dahili numarası
                <input name="extension" inputmode="numeric" autocomplete="username" required autofocus>
            </label>
            <label>PIN
                <input type="password" name="pin" inputmode="numeric" autocomplete="current-password" required>
            </label>
            <button class="btn primary" type="submit" name="login" value="1">WebPhone’a gir</button>
        </form>
        <p class="tiny muted">Yalnız WebRTC erişimi açık aboneler giriş yapabilir.</p>
        <p class="tiny"><a href="login.php">Yönetim paneline dön</a></p>
    </main>
<?php else: ?>
    <main class="webphone-shell">
        <header class="webphone-head">
            <div>
                <strong>ASTERA WebPhone</strong>
                <span><?= e(($webphoneExtension['name'] ?? '') . ' · ' . ($webphoneExtension['exten'] ?? '')) ?></span>
            </div>
        </header>
        <?php
        $webphoneMode = true;
        include __DIR__ . '/pages/webrtc.php';
        ?>
    </main>
    <div id="toast" class="toast" hidden></div>
    <script>
    window.name = 'astera-webphone';
    window.ASTERA = Object.assign(window.ASTERA || {}, {
        csrf: <?= json_encode(csrf_token()) ?>,
        dept: <?= json_encode((string) ($webphoneExtension['dept'] ?? '')) ?>,
        pbxHost: <?= json_encode(PBX_HOST) ?>,
        ws: <?= json_encode(pbx_ws_url()) ?>,
        wss: <?= json_encode(pbx_wss_url()) ?>,
        webphoneAutoConnect: true,
        webphoneStatusUrl: 'webphone_status.php',
        webphoneCustomerUrl: 'webphone_customer.php',
        webphonePendingDial: <?= json_encode($pendingDial) ?>
    });
    window.Astera = {
        _t: null,
        toast(message, bad = false) {
            const element = document.getElementById('toast');
            element.hidden = false;
            element.classList.toggle('bad', bad);
            element.textContent = message;
            clearTimeout(this._t);
            this._t = setTimeout(() => { element.hidden = true; }, 4200);
        }
    };
    </script>
<?php endif; ?>
</body>
</html>
