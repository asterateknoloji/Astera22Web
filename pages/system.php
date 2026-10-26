<?php
require_once dirname(__DIR__) . '/includes/alarms.php';
require_once dirname(__DIR__) . '/includes/system_updates.php';

$renderAlarmPage = !empty($renderAlarmPage);
$cli = $renderAlarmPage ? '' : pbx_cli('core show version');
$uptime = $renderAlarmPage ? '' : pbx_cli('core show uptime');
$transports = $renderAlarmPage ? '' : pbx_cli('pjsip show transports');
$requestedAlarmTab = (string) ($_GET['alarm_tab'] ?? 'system');
$alarmTab = in_array($requestedAlarmTab, ['system', 'pbx', 'email', 'history', 'updates'], true)
    ? $requestedAlarmTab
    : 'system';
$alarmFlash = $renderAlarmPage ? (array) ($_SESSION['system_alarms_flash'] ?? []) : [];
if ($renderAlarmPage) {
    unset($_SESSION['system_alarms_flash']);
}
$alarmDeptId = current_dept_id();
$alarmDefinitions = astera_alarm_definitions();
$alarmRules = [];
$alarmSmtp = [];
$alarmRecipients = [];
$alarmEvents = [];
$systemUpdates = [];
$systemUpdateError = '';

if ($renderAlarmPage && $alarmTab === 'updates' && is_super()) {
    try {
        $systemUpdates = astera_system_updates();
    } catch (Throwable $exception) {
        $systemUpdateError = $exception->getMessage();
    }
}

if ($renderAlarmPage && $alarmDeptId !== null && dept_by_id($alarmDeptId) !== null) {
    astera_alarm_ensure_rules($alarmDeptId);
    foreach (callcenter_rows(
        'SELECT r.*,s.status,s.current_value,s.summary,s.last_checked_at '
        . 'FROM callcenter_alarm_rules r LEFT JOIN callcenter_alarm_states s '
        . 'ON s.dept_id=r.dept_id AND s.alarm_key=r.alarm_key '
        . 'WHERE r.dept_id=:dept ORDER BY r.alarm_key',
        ['dept' => $alarmDeptId]
    ) as $rule) {
        $alarmRules[(string) $rule['alarm_key']] = $rule;
    }
    $alarmSmtp = callcenter_rows(
        'SELECT * FROM callcenter_alarm_smtp_settings WHERE dept_id=:dept',
        ['dept' => $alarmDeptId]
    )[0] ?? [];
    $alarmRecipients = callcenter_rows(
        'SELECT * FROM callcenter_alarm_recipients WHERE dept_id=:dept ORDER BY email',
        ['dept' => $alarmDeptId]
    );
    $alarmEvents = callcenter_rows(
        'SELECT event_type,severity,summary,occurred_at,alarm_key FROM callcenter_alarm_events '
        . 'WHERE dept_id=:dept ORDER BY occurred_at DESC,event_id DESC LIMIT 50',
        ['dept' => $alarmDeptId]
    );
}

$alarmBool = static fn(mixed $value): bool =>
    in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
?>
<?php if (!$renderAlarmPage): ?>
<div class="grid-2">
    <article class="card">
        <h2>Santral</h2>
        <dl class="kv">
            <dt>IP</dt><dd><?= e(PBX_HOST) ?></dd>
            <dt>Panel</dt><dd>admin / yerel oturum</dd>
            <dt>AMI</dt><dd><?= e(AMI_USER) ?> · <?= e((string) AMI_PORT) ?></dd>
        </dl>
        <div class="toolbar left">
            <button class="btn primary" type="button" data-action="apply">Ayarları yaz ve yükle</button>
            <button class="btn" type="button" data-action="reload">Asterisk core reload</button>
        </div>
    </article>
    <article class="card">
        <h2>Özellik kodları</h2>
        <dl class="kv">
            <dt>*43</dt><dd>Echo</dd>
            <dt>*72 / *73</dt><dd>Yönlendirme</dd>
            <dt>*78 / *79</dt><dd>DND</dd>
            <dt>700</dt><dd>Park</dd>
            <dt>*411</dt><dd>Rehber</dd>
        </dl>
        <p class="muted">Aboneler kendi firmasının <code>from-&lt;kod&gt;</code> bağlamındadır; başka firmayı include etmez.</p>
    </article>
</div>
<article class="card">
    <h2>CLI</h2>
    <pre class="log"><?= e(trim($cli . "\n" . $uptime . "\n" . $transports)) ?></pre>
</article>
<?php endif; ?>

<?php if ($renderAlarmPage): ?>
<style>
.alarm-tabs{display:flex;gap:8px;flex-wrap:wrap}.alarm-tabs a{padding:9px 14px;border:1px solid var(--line);border-radius:10px;background:#fff;color:var(--muted);font-weight:700}.alarm-tabs a.active{background:var(--accent);border-color:var(--accent);color:#fff}.alarm-heading{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.alarm-heading h2{margin-right:auto}.alarm-heading>div:first-child{margin-right:auto}.alarm-bulk-actions{display:flex;gap:7px;flex-wrap:wrap}.alarm-row{display:grid;grid-template-columns:minmax(230px,2fr) 90px 110px 110px 110px minmax(120px,1fr);gap:12px;align-items:center;padding:13px 0;border-bottom:1px solid var(--line)}.alarm-row:last-child{border:0}.alarm-name strong,.alarm-name small{display:block}.alarm-name small{color:var(--muted);font-size:11px;margin-top:3px}.alarm-row label,.alarm-form label{display:grid;gap:5px;font-size:12px;color:var(--muted)}.alarm-row input[type=number]{width:100%}.alarm-switch{display:flex!important;align-items:center;gap:7px!important;color:var(--ink)!important}.alarm-status{display:block;margin-top:5px;font-size:11px;font-weight:700}.alarm-status.ok{color:var(--ok)}.alarm-status.alarm{color:var(--bad)}.alarm-status.warning{color:#9a6500}.alarm-actions{display:flex;justify-content:flex-end;margin-top:14px}.alarm-form{grid-template-columns:repeat(2,minmax(0,1fr));align-items:end}.alarm-form .wide{grid-column:1/-1}.alarm-table{width:100%;border-collapse:collapse}.alarm-table th,.alarm-table td{padding:10px 8px;border-bottom:1px solid var(--line);text-align:left}.alarm-table th{font-size:12px;color:var(--muted)}.update-blocked{opacity:.68}.update-version{max-width:260px;word-break:break-all;font-size:11px}.update-note{font-size:12px;color:var(--muted)}@media(max-width:950px){.alarm-row{grid-template-columns:1fr 1fr}.alarm-name{grid-column:1/-1}}@media(max-width:650px){.alarm-form{grid-template-columns:1fr}.alarm-form .wide{grid-column:auto}}
</style>

<article class="card">
    <h2>Alarmlar</h2>
    <p class="muted">Sistem kaynakları, PBX, güvenlik ve e-posta bildirimlerini yönetin.</p>
    <?php if ($alarmFlash): ?>
        <p class="alert <?= !empty($alarmFlash['error']) ? 'bad' : 'good' ?>"><?= e((string) ($alarmFlash['message'] ?? '')) ?></p>
    <?php endif; ?>
    <?php if ($alarmDeptId === null && $alarmTab !== 'updates'): ?>
        <p class="banner">Alarm ayarlarını görüntülemek ve düzenlemek için sağ üstten bir firma seçin.</p>
    <?php endif; ?>
    <?php if ($alarmDeptId !== null || is_super()): ?>
        <nav class="alarm-tabs" aria-label="Alarm bölümleri">
            <a class="<?= $alarmTab === 'system' ? 'active' : '' ?>" href="index.php?p=alarmlar&amp;alarm_tab=system">Sistem Alarmları</a>
            <a class="<?= $alarmTab === 'pbx' ? 'active' : '' ?>" href="index.php?p=alarmlar&amp;alarm_tab=pbx">PBX / Güvenlik</a>
            <a class="<?= $alarmTab === 'email' ? 'active' : '' ?>" href="index.php?p=alarmlar&amp;alarm_tab=email">E-posta Bildirimi</a>
            <a class="<?= $alarmTab === 'history' ? 'active' : '' ?>" href="index.php?p=alarmlar&amp;alarm_tab=history">Alarm Geçmişi</a>
            <?php if (is_super()): ?><a class="<?= $alarmTab === 'updates' ? 'active' : '' ?>" href="index.php?p=alarmlar&amp;alarm_tab=updates">Güncellemeler</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</article>

<?php if ($alarmTab === 'updates' && is_super()): ?>
<article class="card">
    <div class="alarm-heading">
        <div>
            <h2>Güncellemeler</h2>
            <p class="muted">APT paketlerini tek tek seçin. PBX, veritabanı, ağ, SSH, web ve işletim sistemi için kritik paketler panelden güncellenemez.</p>
        </div>
        <form method="post" action="system_updates.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="refresh">
            <button class="btn" type="submit">Paket Listesini Yenile</button>
        </form>
    </div>
    <?php if ($systemUpdateError !== ''): ?>
        <p class="alert bad"><?= e($systemUpdateError) ?></p>
    <?php elseif (!$systemUpdates): ?>
        <p class="banner">Kurulabilir paket güncellemesi bulunamadı.</p>
    <?php else: ?>
        <form method="post" action="system_updates.php" data-update-form>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="apply">
            <input type="hidden" name="confirmation" value="">
            <div class="alarm-bulk-actions" style="margin:12px 0">
                <button class="btn sm" type="button" data-update-toggle="1">Güvenli Olanların Tümünü Seç</button>
                <button class="btn sm" type="button" data-update-toggle="0">Tümünü Kaldır</button>
            </div>
            <div style="overflow:auto">
                <table class="alarm-table">
                    <thead><tr><th>Seç</th><th>Paket</th><th>Mevcut</th><th>Yeni</th><th>Etkilenen servis</th><th>Neden gerekli?</th><th>Güvenlik kararı</th></tr></thead>
                    <tbody>
                    <?php foreach ($systemUpdates as $update): ?>
                        <tr class="<?= $update['blocked'] ? 'update-blocked' : '' ?>">
                            <td><input type="checkbox" name="packages[]" value="<?= e($update['package']) ?>" data-update-package <?= $update['blocked'] ? 'disabled' : '' ?>></td>
                            <td><strong><?= e($update['package']) ?></strong></td>
                            <td class="update-version"><?= e($update['installed']) ?></td>
                            <td class="update-version"><?= e($update['candidate']) ?></td>
                            <td><?= e($update['service']) ?></td>
                            <td class="update-note"><?= e($update['necessity']) ?></td>
                            <td><span class="pill <?= $update['blocked'] ? 'bad' : 'ok' ?>"><?= $update['blocked'] ? 'ENGELLİ' : 'ÖN KONTROLLÜ' ?></span><br><span class="update-note"><?= e($update['reason']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="alarm-actions"><button class="btn primary" type="submit">Seçilenleri Güncelle</button></div>
        </form>
    <?php endif; ?>
</article>
<?php elseif ($alarmDeptId !== null && ($alarmTab === 'system' || $alarmTab === 'pbx')): ?>
<form method="post" action="system_alarms.php" data-alarm-rule-form>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_rules">
    <input type="hidden" name="return_tab" value="<?= e($alarmTab) ?>">
    <article class="card">
        <div class="alarm-heading">
            <h2><?= $alarmTab === 'system' ? 'Sistem Kaynakları ve Servisler' : 'PBX, Trunk ve Güvenlik' ?></h2>
            <div class="alarm-bulk-actions">
                <button class="btn sm" type="button" data-alarm-toggle="1">Tümünü Seç</button>
                <button class="btn sm" type="button" data-alarm-toggle="0">Tümünü Kaldır</button>
            </div>
        </div>
        <?php foreach ($alarmDefinitions as $key => $definition):
            if ($definition[0] !== $alarmTab) continue;
            $rule = $alarmRules[$key] ?? [];
            $status = (string) ($rule['status'] ?? 'unknown');
        ?>
            <div class="alarm-row">
                <div class="alarm-name"><strong><?= e($definition[1]) ?></strong><small><?= e($definition[5]) ?></small></div>
                <label class="alarm-switch"><input type="checkbox" data-alarm-enabled name="enabled[<?= e($key) ?>]" <?= $alarmBool($rule['enabled'] ?? false) ? 'checked' : '' ?>> Etkin</label>
                <label>Uyarı<?php if ($definition[4] !== null): ?><input type="number" step="0.01" min="0" name="warning[<?= e($key) ?>]" value="<?= e((string) ($rule['warning_value'] ?? $definition[4])) ?>"><?php else: ?><small>Olay bazlı</small><?php endif; ?></label>
                <label>Alarm<?php if ($definition[3] !== null): ?><input type="number" step="0.01" min="0" name="threshold[<?= e($key) ?>]" value="<?= e((string) ($rule['threshold_value'] ?? $definition[3])) ?>"><?php else: ?><small>Olay bazlı</small><?php endif; ?></label>
                <label>Tekrar (dk)<input type="number" min="1" max="10080" name="cooldown[<?= e($key) ?>]" value="<?= e((string) ($rule['cooldown_minutes'] ?? 60)) ?>"></label>
                <div>
                    <label class="alarm-switch"><input type="checkbox" name="recovery[<?= e($key) ?>]" <?= $alarmBool($rule['recovery_notification'] ?? true) ? 'checked' : '' ?>> Düzelince bildir</label>
                    <span class="alarm-status <?= e($status) ?>"><?= e(strtoupper($status)) ?><?= isset($rule['current_value']) ? ' · ' . e((string) $rule['current_value']) . ' ' . e($definition[2]) : '' ?></span>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="alarm-actions"><button class="btn primary" type="submit">Alarm Ayarlarını Kaydet</button></div>
    </article>
</form>
<?php elseif ($alarmDeptId !== null && $alarmTab === 'email'): ?>
<div class="grid-2">
    <article class="card">
        <h2>SMTP Sunucusu</h2>
        <form method="post" action="system_alarms.php" class="form-grid alarm-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_smtp"><input type="hidden" name="return_tab" value="email">
            <label class="alarm-switch wide"><input type="checkbox" name="smtp_enabled" <?= $alarmBool($alarmSmtp['enabled'] ?? false) ? 'checked' : '' ?>> E-posta bildirimlerini etkinleştir</label>
            <label>SMTP sunucusu<input name="smtp_host" maxlength="255" value="<?= e((string) ($alarmSmtp['smtp_host'] ?? '')) ?>" placeholder="smtp.example.com"></label>
            <label>Port<input type="number" name="smtp_port" min="1" max="65535" value="<?= e((string) ($alarmSmtp['smtp_port'] ?? 587)) ?>"></label>
            <label>Şifreleme<select name="encryption"><option value="tls" <?= ($alarmSmtp['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS</option><option value="ssl" <?= ($alarmSmtp['encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL/TLS</option><option value="none" <?= ($alarmSmtp['encryption'] ?? '') === 'none' ? 'selected' : '' ?>>Yok</option></select></label>
            <label>Kullanıcı adı<input name="username" maxlength="255" value="<?= e((string) ($alarmSmtp['username'] ?? '')) ?>"></label>
            <label>Parola<input type="password" name="password" autocomplete="new-password" placeholder="<?= !empty($alarmSmtp['password_encrypted']) ? 'Değiştirmek için yazın' : '' ?>"></label>
            <label>Gönderen adresi<input type="email" name="from_email" maxlength="254" value="<?= e((string) ($alarmSmtp['from_email'] ?? '')) ?>"></label>
            <label>Gönderen adı<input name="from_name" maxlength="160" value="<?= e((string) ($alarmSmtp['from_name'] ?? 'Astera PBX')) ?>"></label>
            <button class="btn primary wide" type="submit">SMTP Ayarlarını Kaydet</button>
        </form>
    </article>
    <article class="card">
        <h2>Bildirim Alıcıları</h2>
        <form method="post" action="system_alarms.php" class="form-grid alarm-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_recipient"><input type="hidden" name="return_tab" value="email">
            <label>Ad / açıklama<input name="display_name" maxlength="160"></label>
            <label>E-posta<input type="email" name="email" maxlength="254" required></label>
            <button class="btn primary wide" type="submit">Alıcı Ekle</button>
        </form>
        <table class="alarm-table"><thead><tr><th>Ad</th><th>E-posta</th><th>İşlem</th></tr></thead><tbody>
        <?php foreach ($alarmRecipients as $recipient): ?><tr><td><?= e((string) $recipient['display_name']) ?></td><td><?= e((string) $recipient['email']) ?></td><td><form method="post" action="system_alarms.php" onsubmit="return confirm('Alıcı silinsin mi?')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_recipient"><input type="hidden" name="return_tab" value="email"><input type="hidden" name="recipient_id" value="<?= (int) $recipient['recipient_id'] ?>"><button class="btn danger sm" type="submit">Sil</button></form></td></tr><?php endforeach; ?>
        <?php if (!$alarmRecipients): ?><tr><td colspan="3" class="muted">Henüz alıcı eklenmedi.</td></tr><?php endif; ?>
        </tbody></table>
        <form method="post" action="system_alarms.php" style="margin-top:14px"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="test_email"><input type="hidden" name="return_tab" value="email"><button class="btn" type="submit">Test E-postası Gönder</button></form>
    </article>
</div>
<?php elseif ($alarmDeptId !== null): ?>
<article class="card">
    <h2>Son 50 Alarm Olayı</h2>
    <table class="alarm-table"><thead><tr><th>Zaman</th><th>Alarm</th><th>Olay</th><th>Seviye</th><th>Açıklama</th></tr></thead><tbody>
    <?php foreach ($alarmEvents as $event): ?><tr><td><?= e(date('d.m.Y H:i:s', strtotime((string) $event['occurred_at']))) ?></td><td><?= e($alarmDefinitions[(string) $event['alarm_key']][1] ?? (string) $event['alarm_key']) ?></td><td><?= e((string) $event['event_type']) ?></td><td><span class="pill <?= $event['severity'] === 'critical' ? 'bad' : 'dim' ?>"><?= e((string) $event['severity']) ?></span></td><td><?= e((string) $event['summary']) ?></td></tr><?php endforeach; ?>
    <?php if (!$alarmEvents): ?><tr><td colspan="5" class="muted">Henüz alarm olayı yok.</td></tr><?php endif; ?>
    </tbody></table>
</article>
<?php endif; ?>
<script>
document.querySelectorAll('[data-alarm-toggle]').forEach(button => {
    button.addEventListener('click', () => {
        const form = button.closest('[data-alarm-rule-form]');
        if (!form) return;
        const checked = button.dataset.alarmToggle === '1';
        form.querySelectorAll('[data-alarm-enabled]').forEach(input => { input.checked = checked; });
    });
});
document.querySelectorAll('[data-update-toggle]').forEach(button => {
    button.addEventListener('click', () => {
        const form = button.closest('[data-update-form]');
        if (!form) return;
        const checked = button.dataset.updateToggle === '1';
        form.querySelectorAll('[data-update-package]:not(:disabled)').forEach(input => { input.checked = checked; });
    });
});
document.querySelectorAll('[data-update-form]').forEach(form => {
    form.addEventListener('submit', event => {
        const selected = form.querySelectorAll('[data-update-package]:checked');
        if (!selected.length) {
            event.preventDefault();
            alert('Güncellenecek en az bir paket seçin.');
            return;
        }
        if (!confirm(`${selected.length} paket APT ön kontrolünden sonra güncellenecek. Devam edilsin mi?`)) {
            event.preventDefault();
            return;
        }
        form.elements.confirmation.value = 'UPDATE';
    });
});
</script>
<?php endif; ?>
