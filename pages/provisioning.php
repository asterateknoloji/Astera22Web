<?php
$devices = provisioning_devices(current_dept_id());
$extensions = scoped('extensions');
$templates = provisioning_templates(current_dept_id());
$scheduleSettings = [];
foreach (departments() as $department) {
    $deptId = (string) ($department['id'] ?? '');
    if ($deptId === '' || (current_dept_id() !== null && current_dept_id() !== $deptId)) {
        continue;
    }
    $scheduleSettings[$deptId] = provisioning_settings($deptId);
}
$templateIndex = [];
foreach ($templates as $template) {
    $templateIndex[(string) ($template['id'] ?? '')] = $template;
}
$deviceKeys = [];
foreach ($devices as $device) {
    $deviceKeys[(string) $device['id']] = provisioning_device_keys(
        (string) $device['id'],
        (string) $device['dept']
    );
}
$extensionIndex = [];
foreach ($extensions as $extension) {
    $extensionIndex[(string) ($extension['id'] ?? '')] = $extension;
}
$clientExtensions = array_map(static fn(array $extension): array => [
    'id' => (string) ($extension['id'] ?? ''),
    'dept' => (string) ($extension['dept'] ?? ''),
    'exten' => (string) ($extension['exten'] ?? ''),
    'name' => (string) ($extension['name'] ?? ''),
], $extensions);
$vendorLabels = ['yealink' => 'Yealink', 'grandstream' => 'Grandstream', 'gigaset' => 'Gigaset'];
$baseUrl = 'http://' . PBX_HOST . '/provision/';
?>
<article class="card provisioning-intro">
    <div>
        <span class="security-kicker">ZERO-TOUCH TELEFON KURULUMU</span>
        <h2>Telefonu ağa bağlayın, hesabı otomatik yüklensin</h2>
        <p class="muted">
            MAC adresini bir dahiliyle eşleştirin. Astera; Yealink, Grandstream ve Gigaset için
            üreticiye uygun dosyayı oluşturur.
        </p>
    </div>
    <div class="provisioning-url">
        <span>DHCP Option 66 / Provision URL</span>
        <strong><?= e($baseUrl) ?></strong>
        <button class="btn sm" type="button" data-copy="<?= e($baseUrl) ?>">Adresi kopyala</button>
    </div>
</article>

<section class="grid-2 provisioning-guide">
    <article class="card">
        <div class="security-rule-heading">
            <span class="security-step">1</span>
            <div>
                <h2>DHCP sunucusuna adresi girin</h2>
                <p>Modem/router veya Windows DHCP üzerinde Option 66 değerini yukarıdaki URL yapın.</p>
            </div>
        </div>
    </article>
    <article class="card">
        <div class="security-rule-heading">
            <span class="security-step">2</span>
            <div>
                <h2>Cihazı ekleyip yeniden başlatın</h2>
                <p>MAC ve dahili eşleştikten sonra telefon açılışta SIP hesabını otomatik alır.</p>
            </div>
        </div>
    </article>
</section>

<div class="toolbar provisioning-toolbar">
    <div>
        <h2>Tanımlı cihazlar</h2>
        <p class="muted"><?= count($devices) ?> cihaz · <?= count($extensions) ?> kullanılabilir dahili</p>
    </div>
    <div class="toolbar-actions">
        <button class="btn" type="button" id="provisionSchedule">Otomatik kontrol zamanı</button>
        <button class="btn" type="button" id="addProvisionTemplate">Özel şablon ekle</button>
        <button class="btn" type="button" id="publishProvisioning">Tüm dosyaları yeniden üret</button>
        <button class="btn primary" type="button" id="addProvisionDevice">Cihaz ekle</button>
    </div>
</div>

<article class="card flush">
    <table>
        <thead>
            <tr>
                <th>Marka / model</th>
                <th>MAC adresi</th>
                <?php if (current_dept_id() === null): ?><th>Firma</th><?php endif; ?>
                <th>Dahili</th>
                <th>Provision dosyası</th>
                <th>Durum</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($devices as $device):
            $extension = $extensionIndex[(string) ($device['extension_id'] ?? '')] ?? [];
            $file = provisioning_file_names($device)[0] ?? '';
            $template = $templateIndex[(string) ($device['template_id'] ?? '')] ?? [];
        ?>
            <tr>
                <td>
                    <strong><?= e($vendorLabels[(string) $device['vendor']] ?? (string) $device['vendor']) ?></strong>
                    <small><?= e((string) ($device['model'] ?: 'Genel profil')) ?></small>
                    <small><?= e((string) ($template['name'] ?? 'Genel şablon')) ?></small>
                    <?php if ((string) $device['vendor'] === 'yealink'): ?>
                        <small>Account <?= (int) ($device['account_slot'] ?? 1) ?></small>
                    <?php endif; ?>
                </td>
                <td><code><?= e(implode(':', str_split((string) $device['mac'], 2))) ?></code></td>
                <?php if (current_dept_id() === null): ?><td><?= e(dept_name((string) $device['dept'])) ?></td><?php endif; ?>
                <td>
                    <strong><?= e((string) ($extension['exten'] ?? 'Bulunamadı')) ?></strong>
                    <small><?= e((string) ($device['display_name'] ?: $extension['name'] ?? '')) ?></small>
                </td>
                <td>
                    <code><?= e($file) ?></code>
                    <?php if ((string) $device['vendor'] === 'gigaset'): ?>
                        <small>Gigaset URL: <?= e($baseUrl . '%MACD.xml') ?></small>
                    <?php endif; ?>
                </td>
                <td><span class="pill <?= !empty($device['enabled']) ? 'ok' : 'dim' ?>">
                    <?= !empty($device['enabled']) ? 'Etkin' : 'Kapalı' ?>
                </span></td>
                <td class="right">
                    <?php if ((int) ($template['key_count'] ?? 0) > 0): ?>
                        <button class="btn sm" type="button"
                            data-provision-keys="<?= e((string) $device['id']) ?>">Tuşlar</button>
                    <?php endif; ?>
                    <button class="btn sm" type="button"
                        data-edit-provision='<?= e(json_encode($device, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                        Düzenle
                    </button>
                    <button class="btn sm danger" type="button" data-delete-provision="<?= e((string) $device['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$devices): ?>
            <tr><td colspan="<?= current_dept_id() === null ? 7 : 6 ?>" class="muted">
                Henüz cihaz tanımlanmadı. İlk telefonun MAC adresini ekleyin.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>

<div class="toolbar">
    <div>
        <h2>Hazır ve özel telefon şablonları</h2>
        <p class="muted">Üretici profillerini doğrudan kullanın veya kopyalayıp firmaya özel düzenleyin.</p>
    </div>
    <div class="toolbar-actions">
        <select id="provisionTemplateVendorFilter" aria-label="Üretici filtresi">
            <option value="">Tüm üreticiler</option>
            <option value="yealink">Yealink</option>
            <option value="grandstream">Grandstream</option>
            <option value="gigaset">Gigaset</option>
        </select>
        <input id="provisionTemplateSearch" type="search" placeholder="Model veya şablon ara">
    </div>
</div>
<article class="card flush">
    <table>
        <thead><tr><th>Şablon</th><th>Marka / model</th><th>Hesap</th><th>Tuş</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($templates as $template): ?>
            <tr data-template-row
                data-template-vendor="<?= e((string) $template['vendor']) ?>"
                data-template-search="<?= e(
                    (string) $template['name'] . ' ' . (string) $template['model'] . ' '
                    . (string) ($template['common_file'] ?? '')
                ) ?>">
                <td>
                    <strong><?= e((string) $template['name']) ?></strong>
                    <small><?= !empty($template['builtin']) ? 'Hazır profil' : 'Firma özel' ?></small>
                    <?php if (!empty($template['common_file'])): ?>
                        <small><?= e((string) $template['common_file']) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= e(($vendorLabels[(string) $template['vendor']] ?? (string) $template['vendor'])
                    . ' · ' . ((string) $template['model'] ?: 'Genel')) ?></td>
                <td><?= (int) $template['account_count'] ?></td>
                <td><?= (int) $template['key_count'] ?></td>
                <td class="right">
                    <?php if (!empty($template['builtin'])): ?>
                        <button class="btn sm" type="button"
                            data-copy-provision-template='<?= e(json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>Kopyala ve özelleştir</button>
                    <?php else: ?>
                        <button class="btn sm" type="button"
                            data-edit-provision-template='<?= e(json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>Düzenle</button>
                        <button class="btn sm danger" type="button"
                            data-delete-provision-template="<?= e((string) $template['id']) ?>">Sil</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</article>
<p class="muted tiny">
    Resmî kaynaklar:
    <a href="https://support.yealink.com/forward2filesystem/attachment/upload/attachment/2020-12-10/3/582c3077-9d60-4baa-87de-5f9b6586d2e5/Yealink+SIP+IP+Phones+Auto+Provisioning+Guide+V1.2.pdf" target="_blank" rel="noopener">Yealink Auto Provisioning Guide</a> ·
    <a href="https://documentation.grandstream.com/knowledge-base/sip-device-provisioning-guide/" target="_blank" rel="noopener">Grandstream SIP Provisioning Guide</a> ·
    <a href="https://pro-wiki.gigaset.com/bin/view/GPPPO/Documentation/Whitepapers/Provisioning%20step%20by%20step/Provisioning%20after%20time/" target="_blank" rel="noopener">Gigaset Provisioning Guide</a> ·
    <a href="https://pro-wiki.gigaset.com/bin/view/GPPPO/Documentation/Whitepapers/Provisioning%20step%20by%20step/Autoprovisioning%20Templates/" target="_blank" rel="noopener">Gigaset N610/N670/N770/N870 Şablonları</a>
</p>

<article class="card provisioning-note">
    <strong>Güvenlik</strong>
    <span>Provision dosyaları SIP parolası içerir ve yalnızca yerel ağdan erişilebilir. İnternete açık değildir.</span>
</article>

<script>
window.ASTERA = window.ASTERA || {};
window.ASTERA.provisionDevices = <?= json_encode($devices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.ASTERA.provisionExtensions = <?= json_encode($clientExtensions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.ASTERA.provisionTemplates = <?= json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.ASTERA.provisionDeviceKeys = <?= json_encode($deviceKeys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.ASTERA.provisionScheduleSettings = <?= json_encode($scheduleSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
document.addEventListener('DOMContentLoaded', () => Astera.pageProvisioning());
</script>
