<?php
if (!is_super()) {
    http_response_code(403);
    exit('Sadece sistem yöneticisi');
}

$licenseError = '';
try {
    $licenseStatus = license_status();
    $licenseIdentity = license_installation_identity();
} catch (Throwable $exception) {
    $licenseError = $exception->getMessage();
    $licenseStatus = ['mode' => 'legacy', 'status' => 'unknown', 'unlimited' => true];
    $licenseIdentity = ['fingerprint' => 'Okunamadı'];
}

$licenseFeatures = [
    'subscribers.extensions' => ['Abone', 'Dahili numara', true],
    'concurrent.external_calls' => ['Eşzamanlı çağrı', 'Harici trunk görüşmesi', true],
    'callcenter.queues' => ['Çağrı merkezi', 'Kuyruk', true],
    'callcenter.ringgroups' => ['Çağrı merkezi', 'Ring grup', true],
    'callcenter.agents' => ['Çağrı merkezi', 'Agent', true],
    'callcenter.supervisors' => ['Çağrı merkezi', 'Supervisor', true],
    'callcenter.wallboard' => ['Çağrı merkezi', 'Wallboard', false],
    'callcenter.monitor' => ['Çağrı merkezi', 'Dinleme / Whisper / Barge', false],
    'ivr.enabled' => ['IVR ve otomasyon', 'IVR / otomasyon', true],
    'integrations.enabled' => ['Entegrasyon', 'CRM / API / Webhook', false],
    'ai.enabled' => ['Yapay zekâ', 'AI özellikleri', false],
    'enterprise.multitenant' => ['Kurumsal altyapı', 'Multi-tenant', false],
];
?>
<article class="card license-hero">
    <div>
        <span class="security-kicker">LİSANSLAMA</span>
        <h2>Santral lisansı ve kapasite kullanımı</h2>
        <p class="muted">İmzalı lisans yalnız bu santral kimliğiyle çalışır. Özel imza anahtarı santralda tutulmaz.</p>
    </div>
    <div class="license-state is-<?= e((string) ($licenseStatus['status'] ?? 'unknown')) ?>">
        <small>Durum</small>
        <strong>
            <?php if (($licenseStatus['mode'] ?? '') === 'legacy'): ?>
                Legacy · sınırsız
            <?php elseif (($licenseStatus['status'] ?? '') === 'valid'): ?>
                Lisans geçerli
            <?php elseif (($licenseStatus['status'] ?? '') === 'grace'): ?>
                Ek süre
            <?php else: ?>
                Süresi dolmuş
            <?php endif; ?>
        </strong>
        <span><?= e((string) ($licenseStatus['license_id'] ?? 'Lisans yüklenmedi')) ?></span>
    </div>
</article>

<?php if ($licenseError !== ''): ?>
    <p class="alert bad"><?= e($licenseError) ?></p>
<?php endif; ?>

<div class="grid-2 license-summary">
    <article class="card">
        <h2>Kurulum kimliği</h2>
        <p class="muted">Lisans düzenlenirken aşağıdaki parmak izi kullanılmalıdır.</p>
        <code class="license-fingerprint" id="licenseFingerprint"><?= e((string) $licenseIdentity['fingerprint']) ?></code>
        <button class="btn" type="button" data-copy-license-fingerprint>Kimliği kopyala</button>
    </article>
    <article class="card">
        <h2>Geçerlilik</h2>
        <dl class="kv">
            <dt>Başlangıç</dt><dd><?= e((string) ($licenseStatus['not_before'] ?? '—')) ?></dd>
            <dt>Bitiş</dt><dd><?= e((string) ($licenseStatus['expires_at'] ?? 'Süresiz')) ?></dd>
            <dt>Ek süre sonu</dt><dd><?= e((string) ($licenseStatus['grace_until'] ?? '—')) ?></dd>
            <dt>Politika</dt><dd>Mevcut hizmet sürer; yeni kapasite artışı engellenir</dd>
        </dl>
    </article>
</div>

<article class="card">
    <div class="section-head">
        <div>
            <h2>Özellikler ve limitler</h2>
            <p class="muted">V1 kapsamındaki özellikler canlı olarak zorlanır; diğerleri sonraki aşamaya hazırdır.</p>
        </div>
    </div>
    <div class="license-feature-grid">
        <?php foreach ($licenseFeatures as $featureCode => [$category, $label, $enforced]): ?>
            <?php
            $enabled = license_feature_enabled($featureCode);
            $limit = license_limit($featureCode);
            $usage = license_usage($featureCode);
            ?>
            <div class="license-feature <?= $enabled ? 'is-enabled' : 'is-disabled' ?>">
                <span>
                    <small><?= e($category) ?></small>
                    <strong><?= e($label) ?></strong>
                    <code><?= e($featureCode) ?></code>
                </span>
                <span class="license-feature-value">
                    <strong><?= $enabled ? 'Etkin' : 'Kapalı' ?></strong>
                    <small>
                        Kullanım <?= $usage ?> /
                        <?= $limit === null ? '∞' : (int) $limit ?>
                    </small>
                    <em><?= $enforced ? 'V1 aktif' : 'Hazır' ?></em>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</article>

<article class="card">
    <h2>İmzalı lisans yükle</h2>
    <p class="muted">Astera tarafından üretilen <code>payload</code> ve <code>signature</code> alanlarını içeren JSON zarfını yapıştırın.</p>
    <form id="licenseImportForm">
        <label class="security-field">
            <span>Lisans zarfı</span>
            <textarea name="license_envelope" rows="8" required spellcheck="false"
                placeholder='{"payload":"...","signature":"..."}'></textarea>
        </label>
        <div class="toolbar left">
            <button class="btn primary" type="submit">Lisansı doğrula ve yükle</button>
            <span class="security-action-status" id="licenseImportStatus" role="status" aria-live="polite"></span>
        </div>
    </form>
</article>

<script>document.addEventListener('DOMContentLoaded', () => Astera.pageLicense());</script>
