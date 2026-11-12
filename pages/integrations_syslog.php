<?php
require_once dirname(__DIR__) . '/includes/syslog_integration.php';

$syslog = integration_syslog_connector((string) $integrationDept);
$syslogEnabled = !empty($syslog['enabled']);
$syslogStatus = (string) ($syslog['connection_status'] ?? 'disabled');
$syslogStatusLabels = [
    'disabled' => 'Kapalı', 'waiting' => 'Gönderim bekleniyor',
    'connecting' => 'Bağlanıyor', 'connected' => 'Çalışıyor', 'error' => 'Hata',
];
?>
<article class="card">
    <div class="integration-status">
        <h2 style="margin-right:auto">Remote Syslog</h2>
        <span class="pill <?= $syslogStatus === 'connected' ? 'ok' : ($syslogStatus === 'error' ? 'bad' : 'dim') ?>">
            <?= e($syslogStatusLabels[$syslogStatus] ?? 'Bilinmiyor') ?>
        </span>
    </div>
    <?php if (!empty($syslog['last_error'])): ?><p class="alert bad"><?= e((string) $syslog['last_error']) ?></p><?php endif; ?>
    <form id="syslogForm" class="form-grid integration-form">
        <label class="wide">Remote Logging Provider
            <select name="provider" id="syslogProvider">
                <option value="off" <?= !$syslogEnabled ? 'selected' : '' ?>>Off</option>
                <option value="remote_syslog" <?= $syslogEnabled ? 'selected' : '' ?>>Remote Syslog (RFC 5424)</option>
            </select>
        </label>
        <div class="wide" data-syslog-settings>
            <div class="form-grid integration-form">
                <label>Bağlantı adı<input name="name" maxlength="160" value="<?= e((string) ($syslog['name'] ?? 'Remote Syslog')) ?>"></label>
                <label>Syslog sunucusu<input name="remote_host" maxlength="255" value="<?= e((string) ($syslog['remote_host'] ?? '')) ?>" placeholder="192.168.1.20 veya logs.example.com"></label>
                <label>Port<input type="number" name="port" min="1" max="65535" value="<?= e((string) ($syslog['port'] ?? 514)) ?>"></label>
                <label>Protokol<select name="transport" id="syslogTransport"><option value="udp" <?= ($syslog['transport'] ?? 'udp') === 'udp' ? 'selected' : '' ?>>UDP</option><option value="tcp" <?= ($syslog['transport'] ?? '') === 'tcp' ? 'selected' : '' ?>>TCP</option><option value="tls" <?= ($syslog['transport'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option></select></label>
                <label>Facility<select name="facility"><?php for ($facility = 0; $facility <= 23; $facility++): ?><option value="<?= $facility ?>" <?= (int) ($syslog['facility'] ?? 16) === $facility ? 'selected' : '' ?>><?= $facility >= 16 ? 'local' . ($facility - 16) . ' (' . $facility . ')' : (string) $facility ?></option><?php endfor; ?></select></label>
                <label>Uygulama adı<input name="app_name" maxlength="48" value="<?= e((string) ($syslog['app_name'] ?? 'astera-pbx')) ?>"></label>
                <label>Bağlantı zaman aşımı (sn)<input type="number" name="connect_timeout_seconds" min="1" max="120" value="<?= e((string) ($syslog['connect_timeout_seconds'] ?? 10)) ?>"></label>
            </div>
        </div>
        <fieldset class="fieldset wide" data-syslog-settings>
            <legend>Aktarılacak Veriler</legend>
            <label class="alarm-switch"><input type="checkbox" name="export_system_alarms" <?= !isset($syslog['export_system_alarms']) || integration_syslog_bool($syslog['export_system_alarms']) ? 'checked' : '' ?>> Sistem Alarmları</label>
            <label class="alarm-switch"><input type="checkbox" name="export_pbx_alarms" <?= !isset($syslog['export_pbx_alarms']) || integration_syslog_bool($syslog['export_pbx_alarms']) ? 'checked' : '' ?>> PBX / Trunk / Güvenlik Alarmları</label>
            <label class="alarm-switch"><input type="checkbox" name="export_audit_logs" <?= integration_syslog_bool($syslog['export_audit_logs'] ?? false) ? 'checked' : '' ?>> Audit / İşlem Logları</label>
        </fieldset>
        <div class="integration-actions wide">
            <button class="btn primary" type="submit">Kaydet</button>
            <button class="btn" type="button" id="testSyslog" <?= !$syslog ? 'disabled' : '' ?>>Test Mesajı Gönder</button>
        </div>
    </form>
</article>

<article class="card">
    <h2>Syslog Aktarım Bilgisi</h2>
    <dl class="kv">
        <dt>Format</dt><dd>RFC 5424</dd>
        <dt>Varsayılan port</dt><dd>UDP/TCP 514 · TLS 6514</dd>
        <dt>Sistem alarmları</dt><dd>CPU, RAM, disk, servis ve güncelleme olayları</dd>
        <dt>PBX alarmları</dt><dd>Trunk, güvenlik, abone ve çağrı olayları</dd>
        <dt>Audit logları</dt><dd>Panel kullanıcılarının giriş, değişiklik ve erişim işlemleri</dd>
    </dl>
    <p class="muted">Aktarım etkinleştirildiğinde yalnızca yeni oluşan kayıtlar gönderilir; geçmiş kayıtlar topluca aktarılmaz.</p>
</article>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('syslogForm');
    const provider = document.getElementById('syslogProvider');
    const transport = document.getElementById('syslogTransport');
    const port = form.elements.port;
    const syncProvider = () => {
        const enabled = provider.value !== 'off';
        document.querySelectorAll('[data-syslog-settings]').forEach(section => {
            section.style.opacity = enabled ? '1' : '.55';
            section.querySelectorAll('input,select').forEach(input => input.disabled = !enabled);
        });
    };
    provider.addEventListener('change', syncProvider);
    transport.addEventListener('change', () => {
        if (port.value === '514' || port.value === '6514') port.value = transport.value === 'tls' ? '6514' : '514';
    });
    syncProvider();
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        data.enabled = provider.value === 'off' ? '' : '1';
        data.export_system_alarms = form.elements.export_system_alarms?.checked ? '1' : '';
        data.export_pbx_alarms = form.elements.export_pbx_alarms?.checked ? '1' : '';
        data.export_audit_logs = form.elements.export_audit_logs?.checked ? '1' : '';
        data.dept = window.ASTERA.dept;
        if (provider.value === 'off') {
            data.name = <?= json_encode((string) ($syslog['name'] ?? 'Remote Syslog')) ?>;
            data.remote_host = <?= json_encode((string) ($syslog['remote_host'] ?? '')) ?>;
            data.port = <?= json_encode((string) ($syslog['port'] ?? 514)) ?>;
            data.transport = <?= json_encode((string) ($syslog['transport'] ?? 'udp')) ?>;
            data.facility = <?= json_encode((string) ($syslog['facility'] ?? 16)) ?>;
            data.app_name = <?= json_encode((string) ($syslog['app_name'] ?? 'astera-pbx')) ?>;
            data.connect_timeout_seconds = <?= json_encode((string) ($syslog['connect_timeout_seconds'] ?? 10)) ?>;
        }
        try { await Astera.post('integration_syslog_save', data); location.reload(); }
        catch (error) { Astera.toast(error.message, true); }
    });
    document.getElementById('testSyslog').addEventListener('click', async () => {
        try {
            const result = await Astera.post('integration_syslog_test', {dept: window.ASTERA.dept});
            Astera.toast(result.message || 'Test mesajı gönderildi');
        } catch (error) { Astera.toast(error.message, true); }
    });
});
</script>
