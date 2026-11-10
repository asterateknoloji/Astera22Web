<?php
require_once dirname(__DIR__) . '/includes/feature_codes.php';
$featureDeptId = current_dept_id();
$featureDefinitions = feature_code_definitions();
$featureSettings = [];
foreach ($featureDefinitions as $key => $definition) {
    $featureSettings[$key] = [
        'code' => (string) $definition['default_code'],
        'enabled' => true,
        'is_default' => true,
    ];
}
if ($featureDeptId !== null) {
    $featureSettings = feature_code_settings($featureDeptId);
}
$takeoverSettings = $featureDeptId !== null
    ? feature_takeover_settings($featureDeptId)
    : feature_takeover_defaults('');
$takeoverConflicts = $featureDeptId !== null
    ? feature_takeover_conflicts(
        $featureDeptId,
        (string) $takeoverSettings['activation_prefix'],
        (string) $takeoverSettings['cancel_code']
    )
    : [];
?>
<article class="card">
    <h2>Özellik Kodları</h2>
    <p class="muted">Her firma kendi kodlarını kullanabilir. Kodları değiştirebilir veya kullanılmayan işlevleri kapatabilirsiniz.</p>
    <?php if ($featureDeptId === null): ?>
        <p class="banner">Ayarları değiştirmek için önce bir firma seçin.</p>
    <?php else: ?>
        <form id="featureCodesForm">
            <input type="hidden" name="dept" value="<?= e($featureDeptId) ?>">
            <div style="overflow-x:auto">
                <table>
                    <thead><tr><th>İşlev</th><th style="width:150px">Kod</th><th style="width:90px">Etkin</th><th>Açıklama</th><th style="width:110px">Durum</th></tr></thead>
                    <tbody>
                    <?php foreach ($featureDefinitions as $key => $definition):
                        $setting = $featureSettings[$key];
                    ?>
                        <tr data-feature-key="<?= e($key) ?>">
                            <td><strong><?= e((string) $definition['label']) ?></strong></td>
                            <td><input class="feature-code-input" required maxlength="16" pattern="[\*#0-9]{1,16}" value="<?= e((string) $setting['code']) ?>" data-default="<?= e((string) $definition['default_code']) ?>" style="min-width:105px"></td>
                            <td><input class="feature-enabled-input" type="checkbox" <?= !empty($setting['enabled']) ? 'checked' : '' ?> data-default="1" aria-label="<?= e((string) $definition['label']) ?> etkin"></td>
                            <td class="muted"><?= e((string) $definition['description']) ?></td>
                            <td><span class="badge feature-default-state"><?= !empty($setting['is_default']) ? 'Varsayılan' : 'Özel' ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
                <button class="btn" type="button" id="featureDefaultsButton">Varsayılanları Yükle</button>
                <button class="btn primary" type="submit">Kaydet ve Santrala Uygula</button>
            </div>
        </form>
    <?php endif; ?>

    <h3 style="margin-top:20px">Sistem ve diğer kodlar</h3>
    <table>
        <tbody>
            <tr><td><code>*2</code></td><td>Kontrollü transfer — sistem genelidir, firma bazında değiştirilemez</td></tr>
            <tr><td><code>700 / 701–709</code></td><td>Çağrı park etme ve geri alma — Park ayarlarından değiştirilir</td></tr>
            <tr><td><code>*28</code></td><td>Gündüz/gece anahtarı — Akış üzerinde tanımlanır</td></tr>
            <tr><td><code>*01</code>…</td><td>Hızlı arama — Hızlı arama kayıtlarından tanımlanır</td></tr>
        </tbody>
    </table>
    <p class="muted">Kontrollü transferde görüşme sırasında <code>*2</code>, ardından hedef dahiliyi tuşlayın. Hedefle görüştükten sonra telefonu kapatarak veya tekrar <code>*2</code> ile aktarımı tamamlayın; <code>*1</code> ile iptal edin. Yönlendirme, DND ve gündüz/gece Asterisk DB’de tutulur.</p>
</article>

<article class="card">
    <h2>Geçici Dahili Devralma</h2>
    <p class="muted">Bir PJSIP telefonunu başka bir dahili adına geçici olarak kullanır. Telefonun kayıt bilgisi değişmez; eşleştirme firma bazında Asterisk AstDB içinde tutulur.</p>
    <?php if ($featureDeptId === null): ?>
        <p class="banner">Ayarı görüntülemek ve değiştirmek için önce bir firma seçin.</p>
    <?php else: ?>
        <form id="takeoverFeatureForm" class="form-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));align-items:end">
            <input type="hidden" name="dept" value="<?= e($featureDeptId) ?>">
            <label style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="enabled" <?= feature_takeover_bool($takeoverSettings['enabled']) ? 'checked' : '' ?>>
                Dahili devralmayı etkinleştir
            </label>
            <span></span>
            <label>Devralma kodu başlangıcı
                <input name="activation_prefix" required maxlength="8" pattern="\*[0-9]{2,6}" value="<?= e((string) $takeoverSettings['activation_prefix']) ?>" placeholder="*88">
                <small class="muted">Hedef dahili sona eklenir. Örnek: <code>*88</code> + <code>1002</code>.</small>
            </label>
            <label>İptal kodu
                <input name="cancel_code" required maxlength="8" pattern="\*[0-9]{2,6}" value="<?= e((string) $takeoverSettings['cancel_code']) ?>" placeholder="*89">
                <small class="muted">Telefon kendi dahili kimliğine geri döner.</small>
            </label>
            <button class="btn primary" type="submit" style="grid-column:1/-1">Devralma Kodlarını Kaydet ve Santrala Uygula</button>
        </form>
        <?php if ($takeoverConflicts): ?>
            <p class="alert bad" style="margin-top:12px">Etkinleştirme engellenir: <?= e(implode(' · ', $takeoverConflicts)) ?></p>
        <?php else: ?>
            <p class="banner" style="margin-top:12px">Kodlar mevcut özellik kodları, gündüz/gece kodları, hızlı aramalar ve tekli anons kodlarıyla çakışmıyor.</p>
        <?php endif; ?>
        <p class="muted"><strong>Kullanım:</strong> Örneğin <code><?= e((string) $takeoverSettings['activation_prefix']) ?>1002</code> tuşlandığında aktif telefon 1002 kimliğini devralır. <code><?= e((string) $takeoverSettings['cancel_code']) ?></code> eşleştirmeyi kaldırır. Giden arama kimliği ve 1002’ye gelen çağrılar devralmaya uyar; BLF, mesaj kutusu ve telefon üzerindeki tuş programları değişmez.</p>
    <?php endif; ?>
</article>

<?php if ($featureDeptId !== null): ?>
<script>
const featureRows = [...document.querySelectorAll('[data-feature-key]')];
const refreshFeatureStates = () => {
    featureRows.forEach(row => {
        const code = row.querySelector('.feature-code-input');
        const enabled = row.querySelector('.feature-enabled-input');
        const isDefault = code.value === code.dataset.default
            && enabled.checked === (enabled.dataset.default === '1');
        row.querySelector('.feature-default-state').textContent = isDefault ? 'Varsayılan' : 'Özel';
    });
};
featureRows.forEach(row => {
    row.querySelector('.feature-code-input').addEventListener('input', refreshFeatureStates);
    row.querySelector('.feature-enabled-input').addEventListener('change', refreshFeatureStates);
});
document.getElementById('featureDefaultsButton')?.addEventListener('click', () => {
    featureRows.forEach(row => {
        const code = row.querySelector('.feature-code-input');
        const enabled = row.querySelector('.feature-enabled-input');
        code.value = code.dataset.default;
        enabled.checked = enabled.dataset.default === '1';
    });
    refreshFeatureStates();
});
document.getElementById('featureCodesForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const settings = {};
    featureRows.forEach(row => {
        settings[row.dataset.featureKey] = {
            code: row.querySelector('.feature-code-input').value.trim(),
            enabled: row.querySelector('.feature-enabled-input').checked
        };
    });
    try {
        const result = await Astera.post('feature_codes_save', {
            dept: form.elements.dept.value,
            settings_json: JSON.stringify(settings)
        });
        Astera.toast(result.message || 'Özellik kodları kaydedildi');
        setTimeout(() => location.reload(), 700);
    } catch (error) {
        Astera.toast(error.message, true);
    }
});

document.getElementById('takeoverFeatureForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const data = Object.fromEntries(new FormData(form).entries());
    data.enabled = form.elements.enabled.checked ? '1' : '';
    try {
        const result = await Astera.post('feature_takeover_save', data);
        Astera.toast(result.message || 'Devralma kodları kaydedildi');
        setTimeout(() => location.reload(), 700);
    } catch (error) {
        Astera.toast(error.message, true);
    }
});
</script>
<?php endif; ?>
