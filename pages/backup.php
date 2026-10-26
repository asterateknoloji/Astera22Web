<?php
require_once dirname(__DIR__) . '/includes/backups.php';

$backupSettings = backup_settings();
$backupRows = backup_archives();
$backupJobs = backup_jobs();
$scheduleOnceDate = '';
if (!empty($backupSettings['schedule_once_at'])) {
    try {
        $scheduleOnceDate = (new DateTimeImmutable((string) $backupSettings['schedule_once_at']))
            ->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m-d');
    } catch (Throwable) {
        $scheduleOnceDate = '';
    }
}
$scheduleNextText = '';
if (!empty($backupSettings['schedule_next_run'])) {
    try {
        $scheduleNextText = (new DateTimeImmutable((string) $backupSettings['schedule_next_run']))
            ->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('d.m.Y H:i');
    } catch (Throwable) {
        $scheduleNextText = '';
    }
}
$activeBackupJob = null;
foreach ($backupJobs as $job) {
    if (in_array((string) $job['status'], ['queued', 'running'], true)) {
        $activeBackupJob = $job;
        break;
    }
}
?>
<style>
.backup-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.backup-search{max-width:320px}.backup-table{width:100%;border-collapse:collapse}.backup-table th,.backup-table td{padding:10px 12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}.backup-table th{font-size:12px;color:var(--muted)}.backup-actions{display:flex;gap:6px;flex-wrap:wrap}.backup-settings{grid-template-columns:repeat(3,minmax(0,1fr));align-items:end}.backup-settings .wide{grid-column:1/-1}.backup-settings label{display:grid;gap:5px;font-size:13px;color:var(--muted)}.backup-job{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--line)}.backup-job:last-child{border:0}.backup-job-main{flex:1}.backup-job-main strong,.backup-job-main small{display:block}.backup-job-main small{margin-top:4px;color:var(--muted)}@media(max-width:800px){.backup-settings{grid-template-columns:1fr 1fr}}@media(max-width:560px){.backup-settings{grid-template-columns:1fr}.backup-settings .wide{grid-column:auto}}
</style>

<article class="card">
    <div class="backup-toolbar">
        <h2 style="margin:0 auto 0 0">Yedekleme</h2>
        <button class="btn primary" type="button" id="backupNow" <?= $activeBackupJob ? 'disabled' : '' ?>>Şimdi Yedekle</button>
    </div>
    <p class="muted">PostgreSQL, Asterisk, PRI/DAHDI, IVR/MOH/voicemail, SSL, Nginx, güvenlik duvarı, güvenli IP, Ethernet/Netplan, panel uygulaması ve isteğe bağlı görüşme kayıtlarını yedekleyin.</p>
    <p class="banner">Backup özel anahtar ve parola içerebilir. Arşiv şifreli değildir; yalnız güvenli depoda saklayın. Uzak aktarım SSH ile şifrelenir.</p>
    <?php if ($activeBackupJob): ?>
        <p class="banner">İşlem devam ediyor: <?= e(strtoupper((string) $activeBackupJob['action'])) ?> · <?= e((string) $activeBackupJob['status']) ?></p>
    <?php endif; ?>
    <input class="backup-search" id="backupSearch" placeholder="Backup ara…" autocomplete="off">
</article>

<article class="card flush">
    <table class="backup-table" id="backupTable">
        <thead><tr><th>Adı</th><th>Tarih</th><th>Boyut</th><th>Depolama</th><th>Checksum</th><th>İşlemler</th></tr></thead>
        <tbody>
        <?php foreach ($backupRows as $archive): ?>
            <tr data-backup-row="<?= e(strtolower((string) $archive['filename'])) ?>">
                <td><strong><?= e((string) $archive['filename']) ?></strong><?php if (backup_bool($archive['includes_recordings'])): ?><br><small class="muted">Ses kayıtları dahil</small><?php endif; ?></td>
                <td><?= e(date('d.m.Y H:i:s', strtotime((string) $archive['created_at']))) ?></td>
                <td><?= e(backup_format_bytes((int) $archive['size_bytes'])) ?></td>
                <td><span class="pill <?= $archive['storage_status'] === 'both' ? 'ok' : 'dim' ?>"><?= e((string) $archive['storage_status']) ?></span></td>
                <td><code title="<?= e((string) $archive['sha256_checksum']) ?>"><?= e(substr((string) $archive['sha256_checksum'], 0, 12)) ?>…</code></td>
                <td><div class="backup-actions">
                    <?php if (is_file((string) $archive['local_path'])): ?><a class="btn sm" href="backup_download.php?id=<?= rawurlencode((string) $archive['backup_id']) ?>">İndir</a><?php endif; ?>
                    <button class="btn sm" type="button" data-restore-backup="<?= e((string) $archive['backup_id']) ?>">Restore</button>
                    <?php if (backup_bool($backupSettings['remote_enabled']) && (string) $archive['storage_status'] === 'local'): ?><button class="btn sm" type="button" data-upload-backup="<?= e((string) $archive['backup_id']) ?>">Uzağa Aktar</button><?php endif; ?>
                    <button class="btn sm danger" type="button" data-delete-backup="<?= e((string) $archive['backup_id']) ?>">Sil</button>
                </div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$backupRows): ?><tr><td colspan="6" class="muted">Henüz backup oluşturulmadı.</td></tr><?php endif; ?>
        </tbody>
    </table>
</article>

<div class="grid-2">
    <article class="card">
        <h2>Yerel Backup Ayarları</h2>
        <form id="backupSettingsForm" class="form-grid backup-settings">
            <label>Firma adı<input name="company_name" maxlength="120" required value="<?= e((string) $backupSettings['company_name']) ?>" placeholder="Firma adı"></label>
            <label>Yerel dizin<input value="<?= e((string) $backupSettings['local_path']) ?>" disabled></label>
            <label>Saklanacak backup sayısı<input type="number" name="retention_count" min="1" max="100" value="<?= (int) $backupSettings['retention_count'] ?>"></label>
            <label style="display:flex;align-items:center;gap:8px;color:var(--ink)"><input type="checkbox" name="include_recordings" <?= backup_bool($backupSettings['include_recordings']) ? 'checked' : '' ?>> Görüşme kayıtlarını dahil et</label>
            <label class="wide" style="display:flex;align-items:center;gap:8px;color:var(--ink)"><input type="checkbox" name="schedule_enabled" id="backupScheduleEnabled" <?= backup_bool($backupSettings['schedule_enabled'] ?? false) ? 'checked' : '' ?>> Otomatik yedeklemeyi etkinleştir</label>
            <div class="wide" data-backup-schedule>
                <div class="form-grid backup-settings">
                    <label>Tekrar<select name="schedule_frequency" id="backupScheduleFrequency">
                        <?php foreach (['once' => 'Tek sefer', 'daily' => 'Her gün', 'weekly' => 'Her hafta', 'monthly' => 'Her ay', 'yearly' => 'Her yıl'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($backupSettings['schedule_frequency'] ?? 'daily') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label>Saat<input type="time" name="schedule_time" required value="<?= e(substr((string) ($backupSettings['schedule_time'] ?? '02:00'), 0, 5)) ?>"></label>
                    <label data-schedule-field="once">Tarih<input type="date" name="schedule_date" value="<?= e($scheduleOnceDate) ?>"></label>
                    <label data-schedule-field="weekly">Haftanın günü<select name="schedule_weekday">
                        <?php foreach ([1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= (int) ($backupSettings['schedule_weekday'] ?? 1) === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label data-schedule-field="monthly yearly">Ayın günü<input type="number" name="schedule_day" min="1" max="31" value="<?= (int) ($backupSettings['schedule_day'] ?? 1) ?>"></label>
                    <label data-schedule-field="yearly">Ay<select name="schedule_month">
                        <?php foreach ([1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= (int) ($backupSettings['schedule_month'] ?? 1) === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                </div>
                <small class="muted"><?= $scheduleNextText !== '' ? 'Sonraki otomatik yedekleme: ' . e($scheduleNextText) : 'Zamanlama kaydedildiğinde sonraki çalışma tarihi hesaplanır.' ?></small>
            </div>
            <label class="wide" style="display:flex;align-items:center;gap:8px;color:var(--ink)"><input type="checkbox" name="remote_enabled" id="remoteBackupEnabled" <?= backup_bool($backupSettings['remote_enabled']) ? 'checked' : '' ?>> Uzak SFTP backup etkin</label>
            <div class="wide" data-remote-backup>
                <div class="form-grid backup-settings">
                    <label>Sunucu<input name="remote_host" maxlength="255" value="<?= e((string) $backupSettings['remote_host']) ?>" placeholder="backup.example.com"></label>
                    <label>Port<input type="number" name="remote_port" min="1" max="65535" value="<?= (int) $backupSettings['remote_port'] ?>"></label>
                    <label>Kullanıcı<input name="remote_username" maxlength="120" value="<?= e((string) $backupSettings['remote_username']) ?>"></label>
                    <label>Uzak dizin<input name="remote_path" maxlength="500" value="<?= e((string) $backupSettings['remote_path']) ?>" placeholder="/backups/astera"></label>
                    <label>Kimlik doğrulama<select name="remote_auth_mode" id="remoteAuthMode"><option value="password" <?= $backupSettings['remote_auth_mode'] === 'password' ? 'selected' : '' ?>>Parola</option><option value="key" <?= $backupSettings['remote_auth_mode'] === 'key' ? 'selected' : '' ?>>SSH anahtarı</option></select></label>
                    <label data-password-auth>Parola<input type="password" name="remote_password" autocomplete="new-password" placeholder="<?= $backupSettings['remote_password_encrypted'] !== '' ? 'Değiştirmek için yazın' : '' ?>"></label>
                    <label data-key-auth>Anahtar yolu<input name="remote_key_path" maxlength="500" value="<?= e((string) $backupSettings['remote_key_path']) ?>" placeholder="/etc/astera/backup_id_ed25519"></label>
                </div>
            </div>
            <div class="backup-actions wide">
                <button class="btn primary" type="submit">Backup Ayarlarını Kaydet</button>
                <button class="btn" type="button" id="backupRemoteTest">Uzak Bağlantıyı Test Et</button>
            </div>
        </form>
    </article>
    <article class="card">
        <h2>Son İşlemler</h2>
        <?php foreach ($backupJobs as $job): ?>
            <div class="backup-job">
                <span class="pill <?= $job['status'] === 'completed' ? 'ok' : ($job['status'] === 'failed' ? 'bad' : 'dim') ?>"><?= e((string) $job['status']) ?></span>
                <div class="backup-job-main">
                    <strong><?= e(strtoupper((string) $job['action'])) ?> · #<?= (int) $job['job_id'] ?></strong>
                    <small><?= e(date('d.m.Y H:i:s', strtotime((string) $job['requested_at']))) ?> · <?= e((string) $job['requested_by']) ?></small>
                    <?php if ($job['output_message'] !== ''): ?><small><?= e((string) $job['output_message']) ?></small><?php endif; ?>
                    <?php if ($job['error_message'] !== ''): ?><small style="color:var(--bad)"><?= e((string) $job['error_message']) ?></small><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$backupJobs): ?><p class="muted">Henüz backup işlemi yok.</p><?php endif; ?>
    </article>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const postJob = async (action, data = {}) => {
        const result = await Astera.post(action, data);
        Astera.toast(`İş sıraya alındı (#${result.job_id})`);
        setTimeout(() => location.reload(), 700);
    };
    document.getElementById('backupNow').addEventListener('click', async () => {
        try { await postJob('backup_create'); } catch (error) { Astera.toast(error.message, true); }
    });
    const form = document.getElementById('backupSettingsForm');
    const remoteEnabled = document.getElementById('remoteBackupEnabled');
    const authMode = document.getElementById('remoteAuthMode');
    const scheduleEnabled = document.getElementById('backupScheduleEnabled');
    const scheduleFrequency = document.getElementById('backupScheduleFrequency');
    const syncSchedule = () => {
        document.querySelectorAll('[data-backup-schedule]').forEach(section => {
            section.style.opacity = scheduleEnabled.checked ? '1' : '.55';
        });
        document.querySelectorAll('[data-schedule-field]').forEach(field => {
            field.hidden = !field.dataset.scheduleField.split(' ').includes(scheduleFrequency.value);
        });
    };
    scheduleEnabled.addEventListener('change', syncSchedule);
    scheduleFrequency.addEventListener('change', syncSchedule);
    syncSchedule();
    const syncRemote = () => {
        document.querySelectorAll('[data-remote-backup]').forEach(section => {
            section.style.opacity = remoteEnabled.checked ? '1' : '.55';
        });
        document.querySelectorAll('[data-password-auth]').forEach(label => label.hidden = authMode.value !== 'password');
        document.querySelectorAll('[data-key-auth]').forEach(label => label.hidden = authMode.value !== 'key');
    };
    remoteEnabled.addEventListener('change', syncRemote);
    authMode.addEventListener('change', syncRemote);
    syncRemote();
    const saveSettings = async () => {
        const data = Object.fromEntries(new FormData(form).entries());
        data.include_recordings = form.elements.include_recordings.checked ? '1' : '';
        data.schedule_enabled = form.elements.schedule_enabled.checked ? '1' : '';
        data.remote_enabled = form.elements.remote_enabled.checked ? '1' : '';
        await Astera.post('backup_settings_save', data);
    };
    form.addEventListener('submit', async event => {
        event.preventDefault();
        try { await saveSettings(); Astera.toast('Backup ayarları kaydedildi'); setTimeout(() => location.reload(), 600); }
        catch (error) { Astera.toast(error.message, true); }
    });
    document.getElementById('backupRemoteTest').addEventListener('click', async () => {
        try {
            await saveSettings();
            const result = await Astera.post('backup_remote_test');
            Astera.toast(result.message || 'Uzak bağlantı başarılı');
        } catch (error) {
            Astera.toast(error.message, true);
        }
    });
    document.querySelectorAll('[data-restore-backup]').forEach(button => button.addEventListener('click', async () => {
        const confirmation = prompt('Bu işlem santral veritabanını ve yapılandırmasını geri yükleyecek. Devam etmek için RESTORE yazın.');
        if (confirmation !== 'RESTORE') return;
        try { await postJob('backup_restore', {backup_id: button.dataset.restoreBackup, confirmation}); }
        catch (error) { Astera.toast(error.message, true); }
    }));
    document.querySelectorAll('[data-delete-backup]').forEach(button => button.addEventListener('click', async () => {
        if (!confirm('Yerel ve uzak backup kalıcı olarak silinsin mi?')) return;
        try { await postJob('backup_delete', {backup_id: button.dataset.deleteBackup}); }
        catch (error) { Astera.toast(error.message, true); }
    }));
    document.querySelectorAll('[data-upload-backup]').forEach(button => button.addEventListener('click', async () => {
        try { await postJob('backup_remote_upload', {backup_id: button.dataset.uploadBackup}); }
        catch (error) { Astera.toast(error.message, true); }
    }));
    document.getElementById('backupSearch').addEventListener('input', event => {
        const query = event.target.value.trim().toLowerCase();
        document.querySelectorAll('[data-backup-row]').forEach(row => row.hidden = !row.dataset.backupRow.includes(query));
    });
    <?php if ($activeBackupJob): ?>setTimeout(() => location.reload(), 5000);<?php endif; ?>
});
</script>
