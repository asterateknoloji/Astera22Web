<?php
$ntpSettings = [];
$ntpStatus = [];
try {
    $ntpSettings = ntp_settings();
    $ntpStatus = ntp_live_status();
} catch (Throwable $exception) {
    $ntpStatus = ['ok' => false, 'error' => $exception->getMessage()];
}
$ntpEnabled = in_array(
    strtolower((string) ($ntpSettings['enabled'] ?? 'true')),
    ['1', 't', 'true', 'yes', 'on'],
    true
);
$ntpSynchronized = !empty($ntpStatus['synchronized']);
$ntpServerText = preg_replace(
    '/[\s,;]+/',
    "\n",
    trim((string) ($ntpSettings['ntp_servers'] ?? implode(', ', ntp_default_servers())))
) ?? '';
?>
<style>
.ntp-settings-form{display:grid;gap:18px}
.ntp-server-field{display:grid;gap:7px;width:100%}
.ntp-server-list{width:100%;min-height:190px;resize:vertical;font-family:ui-monospace,Consolas,monospace;line-height:1.65}
</style>
<article class="card">
    <div class="toolbar">
        <div>
            <h2>NTP Saat Eşitleme</h2>
            <p class="muted">Santral saatini güvenilir internet saat sunucularıyla otomatik eşitler.</p>
        </div>
        <span class="pill <?= $ntpSynchronized ? 'ok' : 'bad' ?>">
            <?= $ntpSynchronized ? 'Eşitlenmiş' : 'Eşitlenmemiş' ?>
        </span>
    </div>
    <?php if (empty($ntpStatus['ok'])): ?>
        <p class="alert bad"><?= e((string) ($ntpStatus['error'] ?? 'NTP durumu okunamadı')) ?></p>
    <?php else: ?>
        <dl class="kv">
            <dt>Sistem tarihi / saati</dt><dd><?= e((string) ($ntpStatus['system_time'] ?? '—')) ?></dd>
            <dt>Saat dilimi</dt><dd><?= e((string) ($ntpStatus['timezone'] ?? '—')) ?></dd>
            <dt>NTP servisi</dt><dd><?= e((string) ($ntpStatus['service'] ?? '—')) ?></dd>
            <dt>Aktif sunucu</dt>
            <dd>
                <?= e((string) (($ntpStatus['server'] ?? '') ?: 'Henüz seçilmedi')) ?>
                <?= !empty($ntpStatus['address']) ? ' · ' . e((string) $ntpStatus['address']) : '' ?>
            </dd>
        </dl>
    <?php endif; ?>
    <form id="ntpSettingsForm" class="ntp-settings-form">
        <label><input type="checkbox" name="enabled" <?= $ntpEnabled ? 'checked' : '' ?>>
            Otomatik NTP eşitlemesini etkinleştir
        </label>
        <label class="ntp-server-field">NTP sunucuları
            <textarea class="ntp-server-list" name="ntp_servers" rows="7"
                placeholder="tr.pool.ntp.org&#10;pool.ntp.org&#10;time.cloudflare.com"><?= e($ntpServerText) ?></textarea>
            <small class="muted">Her satıra bir NTP sunucusu yazın. En fazla 8 sunucu.</small>
        </label>
        <div class="toolbar left">
            <button class="btn primary" type="submit">NTP Ayarlarını Uygula</button>
            <button class="btn" type="button" id="ntpSyncNow">Şimdi Eşitle</button>
        </div>
    </form>
</article>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('ntpSettingsForm');
    const syncButton = document.getElementById('ntpSyncNow');
    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submitButton = form.querySelector('[type="submit"]');
        submitButton.disabled = true;
        try {
            const response = await Astera.post('ntp_setting_apply', {
                enabled: form.elements.enabled.checked ? '1' : '',
                ntp_servers: form.elements.ntp_servers.value
            });
            Astera.toast(response.message || 'NTP ayarları uygulandı');
            setTimeout(() => location.reload(), 700);
        } catch (error) {
            Astera.toast(error.message, true);
            submitButton.disabled = false;
        }
    });
    syncButton?.addEventListener('click', async () => {
        syncButton.disabled = true;
        try {
            const response = await Astera.post('ntp_sync_now');
            Astera.toast(response.message || 'NTP eşitlemesi başlatıldı');
            setTimeout(() => location.reload(), 700);
        } catch (error) {
            Astera.toast(error.message, true);
            syncButton.disabled = false;
        }
    });
});
</script>
