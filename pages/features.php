<?php
require_once dirname(__DIR__) . '/includes/feature_codes.php';
$featureDeptId = current_dept_id();
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
    <h2>Firma içi özellik kodları</h2>
    <table>
        <thead><tr><th>Kod</th><th>İşlev</th></tr></thead>
        <tbody>
            <tr><td><code>*43</code></td><td>Echo test</td></tr>
            <tr><td><code>*65</code></td><td>Saat söyle</td></tr>
            <tr><td><code>*72</code></td><td>Çağrı yönlendirme aç (hedef dahili)</td></tr>
            <tr><td><code>*73</code></td><td>Çağrı yönlendirme kapat</td></tr>
            <tr><td><code>*78</code></td><td>Rahatsız etme (DND) aç</td></tr>
            <tr><td><code>*79</code></td><td>DND kapat</td></tr>
            <tr><td><code>*2</code></td><td>Kontrollü transfer (görüşme sırasında hedef dahiliyi tuşlayın)</td></tr>
            <tr><td><code>*8</code></td><td>Çağrı yakala (pickup grubu)</td></tr>
            <tr><td><code>*45</code></td><td>Kuyrukta duraklat (pause)</td></tr>
            <tr><td><code>*46</code></td><td>Kuyruk duraklatmayı kaldır</td></tr>
            <tr><td><code>*97</code></td><td>Kendi Mesaj Kutusu</td></tr>
            <tr><td><code>*98</code></td><td>Mesaj Kutusu (dahili numarası sorar)</td></tr>
            <tr><td><code>*411</code></td><td>Rehber (Mesaj Kutusu adı olan aboneler)</td></tr>
            <tr><td><code>700</code></td><td>Çağrıyı park et (park tuşu ayardan değişir)</td></tr>
            <tr><td><code>701–709</code></td><td>Park slotunu geri al</td></tr>
            <tr><td><code>*80</code> + dahili</td><td>Tekli anons / intercom (ör. *801001)</td></tr>
            <tr><td><code>*28</code></td><td>Gündüz/gece anahtarı (akış tanımlıysa)</td></tr>
            <tr><td><code>*01</code>…</td><td>Hızlı arama kodları</td></tr>
            <tr><td><code><?= e((string) $takeoverSettings['activation_prefix']) ?></code> + dahili</td><td>Geçici dahili devralma<?= feature_takeover_bool($takeoverSettings['enabled']) ? '' : ' (kapalı)' ?></td></tr>
            <tr><td><code><?= e((string) $takeoverSettings['cancel_code']) ?></code></td><td>Dahili devralmayı iptal et<?= feature_takeover_bool($takeoverSettings['enabled']) ? '' : ' (kapalı)' ?></td></tr>
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
