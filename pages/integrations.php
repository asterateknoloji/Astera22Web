<?php
require_once dirname(__DIR__) . '/includes/integrations.php';

$integrationDept = current_dept_id();
$requestedIntegrationSection = (string) ($_GET['section'] ?? 'pms');
$integrationSection = in_array($requestedIntegrationSection, ['pms', 'syslog'], true)
    ? $requestedIntegrationSection
    : 'pms';
$fidelio = null;
$roomMappings = [];
$fiasEvents = [];
if ($integrationDept !== null && $integrationSection === 'pms') {
    $fidelio = integration_fidelio_connector($integrationDept);
    if ($fidelio) {
        $roomMappings = callcenter_rows(
            'SELECT * FROM pms_room_mappings WHERE dept_id=:dept AND connector_id=:connector '
            . 'ORDER BY room_number,mapping_id',
            ['dept' => $integrationDept, 'connector' => (int) $fidelio['connector_id']]
        );
        $fiasEvents = callcenter_rows(
            'SELECT * FROM pms_fias_events WHERE dept_id=:dept AND connector_id=:connector '
            . 'ORDER BY occurred_at DESC,event_id DESC LIMIT 50',
            ['dept' => $integrationDept, 'connector' => (int) $fidelio['connector_id']]
        );
    }
}
$statusLabels = [
    'disabled' => 'Devre dışı', 'waiting' => 'Bağlantı bekleniyor',
    'connecting' => 'Bağlanıyor', 'connected' => 'Bağlı', 'error' => 'Hata',
];
?>
<style>
.integration-catalog{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.integration-item{display:flex;gap:14px;align-items:center;padding:16px;border:1px solid var(--line);border-radius:14px;background:#fff;color:var(--ink);text-decoration:none}.integration-item.active{border-color:var(--accent);box-shadow:0 0 0 2px color-mix(in srgb,var(--accent) 15%,transparent)}.integration-logo{display:grid;place-items:center;flex:0 0 48px;width:48px;height:48px;border-radius:12px;background:#17304d;color:#fff;font-weight:900}.integration-meta strong,.integration-meta small{display:block}.integration-meta small{margin-top:4px;color:var(--muted)}.integration-status{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.integration-form{grid-template-columns:repeat(3,minmax(0,1fr));align-items:end}.integration-form .wide{grid-column:1/-1}.integration-form label{display:grid;gap:5px;font-size:13px;color:var(--muted)}.integration-actions{display:flex;gap:8px;flex-wrap:wrap}.integration-table{width:100%;border-collapse:collapse}.integration-table th,.integration-table td{padding:10px 8px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}.integration-table th{font-size:12px;color:var(--muted)}.raw-fias{max-width:420px;overflow-wrap:anywhere;font-family:ui-monospace,monospace;font-size:12px}.direction-incoming{color:var(--ok)}.direction-outgoing{color:#6750a4}@media(max-width:900px){.integration-catalog,.integration-form{grid-template-columns:1fr 1fr}}@media(max-width:620px){.integration-catalog,.integration-form{grid-template-columns:1fr}.integration-form .wide{grid-column:auto}}
</style>

<article class="card">
    <h2>Entegrasyonlar</h2>
    <p class="muted">PMS, CRM ve harici sistem bağlantılarını ortak entegrasyon merkezi üzerinden yönetin.</p>
    <div class="integration-catalog">
        <a class="integration-item <?= $integrationSection === 'pms' ? 'active' : '' ?>" href="index.php?p=integrations&amp;section=pms">
            <span class="integration-logo">PMS</span>
            <span class="integration-meta"><strong>PMS</strong><small>Fidelio / OPERA FIAS otel entegrasyonu</small></span>
        </a>
        <a class="integration-item <?= $integrationSection === 'syslog' ? 'active' : '' ?>" href="index.php?p=integrations&amp;section=syslog">
            <span class="integration-logo">LOG</span>
            <span class="integration-meta"><strong>Syslog</strong><small>Uzak sistemlere alarm ve audit aktarımı</small></span>
        </a>
    </div>
</article>

<?php if ($integrationDept === null): ?>
    <p class="banner">Entegrasyon ayarları için sağ üstten bir firma seçin.</p>
<?php elseif ($integrationSection === 'pms'): ?>
<article class="card">
    <div class="integration-status">
        <h2 style="margin-right:auto">Fidelio FIAS Bağlantısı</h2>
        <span class="pill <?= ($fidelio['connection_status'] ?? 'disabled') === 'connected' ? 'ok' : (($fidelio['connection_status'] ?? '') === 'error' ? 'bad' : 'dim') ?>">
            <?= e($statusLabels[(string) ($fidelio['connection_status'] ?? 'disabled')] ?? 'Bilinmiyor') ?>
        </span>
        <?php if (!empty($fidelio['last_connected_at'])): ?><small class="muted">Son bağlantı: <?= e(date('d.m.Y H:i:s', strtotime((string) $fidelio['last_connected_at']))) ?></small><?php endif; ?>
    </div>
    <?php if (!empty($fidelio['last_error'])): ?><p class="alert bad"><?= e((string) $fidelio['last_error']) ?></p><?php endif; ?>
    <form id="fidelioForm" class="form-grid integration-form">
        <label class="wide" style="display:flex;align-items:center;gap:8px;color:var(--ink)"><input type="checkbox" name="enabled" <?= !empty($fidelio['enabled']) ? 'checked' : '' ?>> Fidelio entegrasyonunu etkinleştir</label>
        <label>Bağlantı adı<input name="name" maxlength="160" value="<?= e((string) ($fidelio['name'] ?? 'Fidelio PMS')) ?>" required></label>
        <label>Bağlantı modu<select name="connection_mode" id="fidelioMode"><option value="client" <?= ($fidelio['connection_mode'] ?? 'client') === 'client' ? 'selected' : '' ?>>İstemci — PMS sunucusuna bağlan</option><option value="server" <?= ($fidelio['connection_mode'] ?? '') === 'server' ? 'selected' : '' ?>>Sunucu — PMS bağlantısını dinle</option></select></label>
        <label>Port<input type="number" name="port" min="1" max="65535" value="<?= e((string) ($fidelio['port'] ?? 5001)) ?>" required></label>
        <label data-client-field>Fidelio sunucu adresi<input name="remote_host" maxlength="255" value="<?= e((string) ($fidelio['remote_host'] ?? '')) ?>" placeholder="192.168.1.50"></label>
        <label data-server-field>Dinleme IP adresi<input name="bind_address" value="<?= e((string) ($fidelio['bind_address'] ?? '0.0.0.0')) ?>"></label>
        <label>FIAS sürümü<input name="fias_version" maxlength="20" value="<?= e((string) ($fidelio['fias_version'] ?? '2.20')) ?>"></label>
        <label>Otel kodu<input name="hotel_code" maxlength="80" value="<?= e((string) ($fidelio['hotel_code'] ?? '')) ?>"></label>
        <label>Karakter seti<select name="charset"><?php foreach (['UTF-8', 'ISO-8859-9', 'WINDOWS-1254'] as $charset): ?><option value="<?= e($charset) ?>" <?= ($fidelio['charset'] ?? 'UTF-8') === $charset ? 'selected' : '' ?>><?= e($charset) ?></option><?php endforeach; ?></select></label>
        <label>Mesaj sonlandırıcı<select name="message_terminator"><option value="CRLF" <?= ($fidelio['message_terminator'] ?? 'CRLF') === 'CRLF' ? 'selected' : '' ?>>CRLF</option><option value="LF" <?= ($fidelio['message_terminator'] ?? '') === 'LF' ? 'selected' : '' ?>>LF</option><option value="ETX" <?= ($fidelio['message_terminator'] ?? '') === 'ETX' ? 'selected' : '' ?>>ETX</option></select></label>
        <label>Keepalive (sn)<input type="number" name="keepalive_seconds" min="10" max="3600" value="<?= e((string) ($fidelio['keepalive_seconds'] ?? 60)) ?>"></label>
        <label>Yeniden bağlanma (sn)<input type="number" name="reconnect_seconds" min="1" max="3600" value="<?= e((string) ($fidelio['reconnect_seconds'] ?? 10)) ?>"></label>
        <label>Bağlantı zaman aşımı (sn)<input type="number" name="connect_timeout_seconds" min="1" max="120" value="<?= e((string) ($fidelio['connect_timeout_seconds'] ?? 10)) ?>"></label>
        <label class="wide" style="display:flex;align-items:center;gap:8px;color:var(--ink)"><input type="checkbox" name="auto_create_room_mapping" <?= !empty($fidelio['auto_create_room_mapping']) ? 'checked' : '' ?>> Check-in mesajında oda numarasıyla otomatik dahili eşleştirmesi oluştur</label>
        <div class="integration-actions wide">
            <button class="btn primary" type="submit">Ayarları Kaydet</button>
            <button class="btn" type="button" id="testFidelio">Bağlantıyı Test Et</button>
        </div>
    </form>
</article>

<div class="grid-2">
    <article class="card">
        <h2>Oda / Dahili Eşleştirmesi</h2>
        <?php if (!$fidelio): ?>
            <p class="muted">Oda eşleştirmesi eklemek için önce bağlantı ayarlarını kaydedin.</p>
        <?php else: ?>
        <form id="roomMappingForm" class="form-grid integration-form" style="grid-template-columns:1fr 1fr">
            <label>Oda numarası<input name="room_number" maxlength="40" required></label>
            <label>Dahili<input name="extension_number" list="extensionList" inputmode="numeric" required></label>
            <datalist id="extensionList"><?php foreach (scoped('extensions') as $extension): ?><option value="<?= e((string) ($extension['exten'] ?? '')) ?>"><?= e((string) ($extension['name'] ?? '')) ?></option><?php endforeach; ?></datalist>
            <button class="btn primary wide" type="submit">Eşleştirme Ekle</button>
        </form>
        <table class="integration-table"><thead><tr><th>Oda</th><th>Dahili</th><th>Durum</th><th>Misafir</th><th></th></tr></thead><tbody>
        <?php foreach ($roomMappings as $mapping): ?><tr><td><?= e((string) $mapping['room_number']) ?></td><td><?= e((string) $mapping['extension_number']) ?></td><td><span class="pill <?= $mapping['occupancy_status'] === 'occupied' ? 'ok' : 'dim' ?>"><?= e((string) $mapping['occupancy_status']) ?></span></td><td><?= e((string) $mapping['guest_name']) ?></td><td><button class="btn sm danger" type="button" data-delete-mapping="<?= (int) $mapping['mapping_id'] ?>">Sil</button></td></tr><?php endforeach; ?>
        <?php if (!$roomMappings): ?><tr><td colspan="5" class="muted">Henüz oda eşleştirmesi yok.</td></tr><?php endif; ?>
        </tbody></table>
        <?php endif; ?>
    </article>
    <article class="card">
        <h2>Desteklenen İlk FIAS Olayları</h2>
        <dl class="kv">
            <dt>GI</dt><dd>Misafir check-in; oda dolu ve misafir bilgisi güncellenir.</dd>
            <dt>GO</dt><dd>Misafir check-out; oda boşaltılır.</dd>
            <dt>RS</dt><dd>Oda durumu güncellenir.</dd>
            <dt>LS / LD / LA</dt><dd>Bağlantı başlatma, tanımlama ve keepalive.</dd>
        </dl>
        <p class="muted">Uyandırma, ücretlendirme ve minibar kayıtları sonraki aşamada otelin kullandığı FIAS profilindeki kayıt kodlarına göre etkinleştirilir.</p>
    </article>
</div>

<article class="card">
    <h2>Son 50 FIAS Mesajı</h2>
    <table class="integration-table"><thead><tr><th>Zaman</th><th>Yön</th><th>Tür</th><th>Oda</th><th>Durum</th><th>Ham mesaj</th></tr></thead><tbody>
    <?php foreach ($fiasEvents as $event): ?><tr><td><?= e(date('d.m.Y H:i:s', strtotime((string) $event['occurred_at']))) ?></td><td class="direction-<?= e((string) $event['direction']) ?>"><?= $event['direction'] === 'incoming' ? 'Gelen' : 'Giden' ?></td><td><code><?= e((string) $event['record_type']) ?></code></td><td><?= e((string) $event['room_number']) ?></td><td><?= e((string) $event['processing_status']) ?><?= $event['error_message'] !== '' ? '<br><small>' . e((string) $event['error_message']) . '</small>' : '' ?></td><td class="raw-fias"><?= e((string) $event['raw_message']) ?></td></tr><?php endforeach; ?>
    <?php if (!$fiasEvents): ?><tr><td colspan="6" class="muted">Henüz FIAS mesajı alınmadı.</td></tr><?php endif; ?>
    </tbody></table>
</article>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('fidelioForm');
    const mode = document.getElementById('fidelioMode');
    const syncMode = () => {
        document.querySelectorAll('[data-client-field]').forEach(el => el.hidden = mode.value !== 'client');
        document.querySelectorAll('[data-server-field]').forEach(el => el.hidden = mode.value !== 'server');
    };
    mode.addEventListener('change', syncMode);
    syncMode();
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(form).entries());
        data.enabled = form.elements.enabled.checked ? '1' : '';
        data.auto_create_room_mapping = form.elements.auto_create_room_mapping.checked ? '1' : '';
        data.dept = window.ASTERA.dept;
        try { await Astera.post('integration_fidelio_save', data); location.reload(); }
        catch (error) { Astera.toast(error.message, true); }
    });
    document.getElementById('testFidelio').addEventListener('click', async () => {
        try { const result = await Astera.post('integration_fidelio_test', {dept: window.ASTERA.dept}); Astera.toast(result.message || 'Bağlantı başarılı'); }
        catch (error) { Astera.toast(error.message, true); }
    });
    const mappingForm = document.getElementById('roomMappingForm');
    if (mappingForm) mappingForm.addEventListener('submit', async event => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(mappingForm).entries());
        data.dept = window.ASTERA.dept;
        try { await Astera.post('integration_fidelio_mapping_save', data); location.reload(); }
        catch (error) { Astera.toast(error.message, true); }
    });
    document.querySelectorAll('[data-delete-mapping]').forEach(button => button.addEventListener('click', async () => {
        if (!confirm('Oda eşleştirmesi silinsin mi?')) return;
        try { await Astera.post('integration_fidelio_mapping_delete', {dept: window.ASTERA.dept, mapping_id: button.dataset.deleteMapping}); location.reload(); }
        catch (error) { Astera.toast(error.message, true); }
    }));
});
</script>
<?php elseif ($integrationSection === 'syslog'): ?>
    <?php require __DIR__ . '/integrations_syslog.php'; ?>
<?php endif; ?>
