<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/alarms.php';
require_login();

if (!is_super()) {
    http_response_code(403);
    exit('Bu işlem yalnızca santral yöneticisine açıktır.');
}

$deptId = current_dept_id();
if ($deptId === null || dept_by_id($deptId) === null) {
    $_SESSION['system_alarms_flash'] = [
        'message' => 'Alarm ayarları için önce bir firma seçin.',
        'error' => true,
    ];
    redirect('index.php?p=alarmlar');
}

$definitions = astera_alarm_definitions();
$tab = in_array((string) ($_POST['return_tab'] ?? 'system'), ['system', 'pbx', 'email', 'history'], true)
    ? (string) $_POST['return_tab']
    : 'system';
$identity = [
    'dept_id' => $deptId,
    'username' => substr((string) ($_SESSION['username'] ?? $_SESSION['user'] ?? 'santral-admin'), 0, 160),
];

try {
    if (!csrf_ok()) {
        throw new RuntimeException('İstek doğrulanamadı. Sayfayı yenileyip tekrar deneyin.');
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_rules') {
        astera_alarm_ensure_rules($deptId);
        $statements = [];
        foreach ($definitions as $key => $definition) {
            $threshold = $definition[3] === null
                ? null
                : max(0, (float) ($_POST['threshold'][$key] ?? $definition[3]));
            $warning = $definition[4] === null
                ? null
                : max(0, (float) ($_POST['warning'][$key] ?? $definition[4]));
            if ($warning !== null && $threshold !== null && $warning > $threshold) {
                throw new InvalidArgumentException($definition[1] . ': uyarı değeri alarm değerinden büyük olamaz.');
            }
            $statements[] = [
                'UPDATE callcenter_alarm_rules SET enabled=:enabled,threshold_value=:threshold,'
                . 'warning_value=:warning,cooldown_minutes=:cooldown,recovery_notification=:recovery,updated_at=now() '
                . 'WHERE dept_id=:dept AND alarm_key=:alarm',
                [
                    'enabled' => isset($_POST['enabled'][$key]),
                    'threshold' => $threshold,
                    'warning' => $warning,
                    'cooldown' => max(1, min(10080, (int) ($_POST['cooldown'][$key] ?? 60))),
                    'recovery' => isset($_POST['recovery'][$key]),
                    'dept' => $deptId,
                    'alarm' => $key,
                ],
            ];
        }
        callcenter_execute_batch($statements);
        callcenter_audit_write($identity, 'update', 'alarmlar', 'Alarm kuralları güncellendi', '', 'system');
        $message = 'Alarm ayarları kaydedildi.';
    } elseif ($action === 'save_smtp') {
        $host = trim((string) ($_POST['smtp_host'] ?? ''));
        $port = (int) ($_POST['smtp_port'] ?? 587);
        $encryption = (string) ($_POST['encryption'] ?? 'tls');
        $username = trim((string) ($_POST['username'] ?? ''));
        $fromEmail = strtolower(trim((string) ($_POST['from_email'] ?? '')));
        $fromName = trim((string) ($_POST['from_name'] ?? 'Astera PBX'));
        $enabled = isset($_POST['smtp_enabled']);
        if ($enabled && ($host === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL))) {
            throw new InvalidArgumentException('Etkin SMTP için sunucu ve geçerli gönderen adresi zorunludur.');
        }
        if ($port < 1 || $port > 65535 || !in_array($encryption, ['none', 'tls', 'ssl'], true)) {
            throw new InvalidArgumentException('SMTP portu veya şifreleme türü geçersiz.');
        }
        $password = (string) ($_POST['password'] ?? '');
        $existing = callcenter_rows(
            'SELECT password_encrypted FROM callcenter_alarm_smtp_settings WHERE dept_id=:dept',
            ['dept' => $deptId]
        )[0]['password_encrypted'] ?? '';
        $encrypted = $password !== '' ? astera_alarm_encrypt($password) : (string) $existing;
        callcenter_execute(
            'INSERT INTO callcenter_alarm_smtp_settings '
            . '(dept_id,enabled,smtp_host,smtp_port,encryption,username,password_encrypted,from_email,from_name) '
            . 'VALUES (:dept,:enabled,:host,:port,:encryption,:username,:password,:email,:name) '
            . 'ON CONFLICT (dept_id) DO UPDATE SET enabled=EXCLUDED.enabled,smtp_host=EXCLUDED.smtp_host,'
            . 'smtp_port=EXCLUDED.smtp_port,encryption=EXCLUDED.encryption,username=EXCLUDED.username,'
            . 'password_encrypted=EXCLUDED.password_encrypted,from_email=EXCLUDED.from_email,'
            . 'from_name=EXCLUDED.from_name,updated_at=now()',
            [
                'dept' => $deptId, 'enabled' => $enabled, 'host' => $host, 'port' => $port,
                'encryption' => $encryption, 'username' => $username, 'password' => $encrypted,
                'email' => $fromEmail, 'name' => $fromName,
            ]
        );
        callcenter_audit_write($identity, 'update', 'alarmlar', 'Alarm SMTP ayarı güncellendi', '', 'system');
        $message = 'E-posta sunucu ayarları kaydedildi.';
    } elseif ($action === 'add_recipient') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name = trim((string) ($_POST['display_name'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Geçerli bir alıcı e-posta adresi girin.');
        }
        callcenter_execute(
            'INSERT INTO callcenter_alarm_recipients (dept_id,email,display_name,active) '
            . 'VALUES (:dept,:email,:name,TRUE) ON CONFLICT (dept_id,email) DO UPDATE '
            . 'SET display_name=EXCLUDED.display_name,active=TRUE,updated_at=now()',
            ['dept' => $deptId, 'email' => $email, 'name' => $name]
        );
        $message = 'Bildirim alıcısı eklendi.';
    } elseif ($action === 'delete_recipient') {
        callcenter_execute(
            'DELETE FROM callcenter_alarm_recipients WHERE dept_id=:dept AND recipient_id=:id',
            ['dept' => $deptId, 'id' => (int) ($_POST['recipient_id'] ?? 0)]
        );
        $message = 'Bildirim alıcısı silindi.';
    } elseif ($action === 'test_email') {
        $smtp = callcenter_rows(
            'SELECT * FROM callcenter_alarm_smtp_settings WHERE dept_id=:dept',
            ['dept' => $deptId]
        )[0] ?? null;
        $recipients = array_column(callcenter_rows(
            'SELECT email FROM callcenter_alarm_recipients WHERE dept_id=:dept AND active ORDER BY email',
            ['dept' => $deptId]
        ), 'email');
        if (!$smtp) {
            throw new RuntimeException('Önce SMTP ayarlarını kaydedin.');
        }
        astera_alarm_send_email(
            $smtp,
            $recipients,
            '[Astera PBX] Test bildirimi',
            '<h2>Alarm e-postası çalışıyor</h2><p>Firma: ' . e($deptId)
                . '</p><p>Zaman: ' . date('d.m.Y H:i:s') . '</p>'
        );
        callcenter_audit_write($identity, 'update', 'alarmlar', 'Alarm test e-postası gönderildi', '', 'system');
        $message = 'Test e-postası gönderildi.';
    } else {
        throw new InvalidArgumentException('Geçersiz işlem.');
    }

    $_SESSION['system_alarms_flash'] = ['message' => $message, 'error' => false];
} catch (Throwable $exception) {
    $_SESSION['system_alarms_flash'] = ['message' => $exception->getMessage(), 'error' => true];
}

redirect('index.php?p=alarmlar&alarm_tab=' . rawurlencode($tab));
