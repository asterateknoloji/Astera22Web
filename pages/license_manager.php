<?php
if (!is_super() || !license_issuer_enabled()) {
    http_response_code(403);
    exit('Vendor lisans yöneticisi bu santralda etkin değil');
}

$issuerError = '';
$issuerData = ['customers' => [], 'documents' => []];
try {
    $issuerData = license_issuer_list();
} catch (Throwable $exception) {
    $issuerError = $exception->getMessage();
}
$issuerFeatureLabels = [
    'subscribers.extensions' => 'Dahili aboneler',
    'concurrent.external_calls' => 'Eşzamanlı harici çağrı',
    'callcenter.queues' => 'Çağrı merkezi kuyrukları',
    'callcenter.ringgroups' => 'Ring grupları',
    'callcenter.agents' => 'Çağrı merkezi agent',
    'callcenter.supervisors' => 'Supervisor',
    'callcenter.wallboard' => 'Wallboard',
    'callcenter.monitor' => 'Dinleme / Whisper / Barge',
    'ivr.enabled' => 'IVR',
    'integrations.enabled' => 'Entegrasyonlar',
    'ai.enabled' => 'Yapay zekâ',
    'enterprise.multitenant' => 'Multi-tenant',
];
?>
<article class="card license-hero">
    <div>
        <span class="security-kicker">YALNIZ VENDOR MASTER</span>
        <h2>Vendor lisans yöneticisi</h2>
        <p class="muted">Müşteri PBX kurulumlarına Ed25519 imzalı, çevrimdışı doğrulanabilir lisans üretir.</p>
    </div>
    <div class="license-state is-valid"><small>Issuer</small><strong>Etkin</strong><span>Özel anahtar yerel</span></div>
</article>

<?php if ($issuerError !== ''): ?><p class="alert bad"><?= e($issuerError) ?></p><?php endif; ?>

<div class="grid-2 license-manager-grid">
    <article class="card">
        <h2>Müşteri kaydı</h2>
        <form id="licenseCustomerForm" class="form-grid">
            <input type="hidden" name="customer_id" value="">
            <label><span>Müşteri kodu</span><input name="customer_code" maxlength="48" required placeholder="ACME"></label>
            <label><span>Firma / unvan</span><input name="legal_name" maxlength="200" required></label>
            <label><span>Yetkili</span><input name="contact_name" maxlength="160"></label>
            <label><span>E-posta</span><input name="contact_email" type="email" maxlength="254"></label>
            <div class="toolbar left"><button class="btn" type="submit">Müşteriyi kaydet</button></div>
        </form>
    </article>

    <article class="card">
        <h2>Lisans üret / yenile</h2>
        <form id="licenseIssueForm">
            <input type="hidden" name="installation_id">
            <input type="hidden" name="previous_document_id">
            <div class="form-grid">
                <label><span>Müşteri</span>
                    <select name="customer_id" required>
                        <option value="">Seçin</option>
                        <?php foreach ($issuerData['customers'] as $customer): ?>
                            <option value="<?= (int) $customer['customer_id'] ?>">
                                <?= e($customer['customer_code'] . ' · ' . $customer['legal_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span>PBX adı</span><input name="pbx_name" maxlength="160" required></label>
                <label class="span-2"><span>Kurulum parmak izi</span>
                    <input name="fingerprint" minlength="64" maxlength="64" pattern="[0-9a-f]{64}" required spellcheck="false">
                </label>
                <label><span>Başlangıç</span><input name="not_before" type="datetime-local" required></label>
                <label><span>Bitiş</span><input name="expires_at" type="datetime-local" required></label>
                <label><span>Ek süre (gün)</span><input name="grace_days" type="number" min="0" max="365" value="14" required></label>
                <label><span>İmza anahtarı parolası</span>
                    <input name="signing_passphrase" type="password" maxlength="512"
                        autocomplete="off" required>
                    <small>Parola kaydedilmez ve her lisans üretiminde yeniden istenir.</small>
                </label>
            </div>
            <h3>Özellikler ve limitler</h3>
            <div class="license-entitlement-editor">
                <?php foreach ($issuerFeatureLabels as $feature => $label): ?>
                    <div class="license-entitlement-row">
                        <label>
                            <input type="checkbox" name="entitlement_enabled[<?= e($feature) ?>]" value="1">
                            <span><strong><?= e($label) ?></strong><code><?= e($feature) ?></code></span>
                        </label>
                        <input type="number" name="entitlement_limit[<?= e($feature) ?>]"
                            min="0" placeholder="Limitsiz" aria-label="<?= e($label) ?> limiti">
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="toolbar left">
                <button class="btn primary" type="submit">İmzalı lisans üret</button>
                <button class="btn" type="button" data-license-issue-reset>Yeni form</button>
                <span id="licenseManagerStatus" class="security-action-status" role="status"></span>
            </div>
        </form>
    </article>
</div>

<article class="card" id="licenseEnvelopeCard" hidden>
    <div class="section-head">
        <div><h2>Üretilen lisans zarfı</h2><p class="muted" id="licenseEnvelopeId"></p></div>
        <div class="toolbar">
            <button class="btn" type="button" data-copy-license-envelope>Kopyala</button>
            <button class="btn" type="button" data-download-license-envelope>JSON indir</button>
        </div>
    </div>
    <textarea id="licenseEnvelopeOutput" rows="10" readonly spellcheck="false"></textarea>
</article>

<article class="card">
    <h2>Lisans geçmişi ve durum</h2>
    <p class="muted">Çevrimdışı lisans iptali kayıt yönetimidir; müşteriye yüklenmiş dosya bitiş tarihine kadar çalışır. Anlık iptal için müşteri PBX’ine yeni lisans yüklenmelidir.</p>
    <div class="table-wrap">
        <table class="data-table license-history">
            <thead><tr><th>Müşteri / PBX</th><th>Lisans</th><th>Geçerlilik</th><th>Durum</th><th>İşlem</th></tr></thead>
            <tbody>
            <?php foreach ($issuerData['documents'] as $document): ?>
                <tr>
                    <td><strong><?= e($document['customer_code'] . ' · ' . $document['pbx_name']) ?></strong>
                        <small><?= e($document['fingerprint']) ?></small></td>
                    <td><code><?= e($document['license_id']) ?></code><small><?= e($document['issued_at']) ?></small></td>
                    <td><?= e((string) $document['expires_at']) ?><small>Grace: <?= e((string) $document['grace_until']) ?></small></td>
                    <td><span class="pill <?= in_array($document['effective_status'], ['revoked', 'expired'], true) ? 'bad' : 'ok' ?>"><?= e($document['effective_status']) ?></span></td>
                    <td class="toolbar">
                        <?php if ($document['status'] !== 'revoked'): ?>
                            <button class="btn" type="button" data-license-renew
                                data-document="<?= (int) $document['document_id'] ?>"
                                data-installation="<?= (int) $document['installation_id'] ?>"
                                data-customer="<?= (int) $document['customer_id'] ?>"
                                data-pbx="<?= e($document['pbx_name']) ?>"
                                data-fingerprint="<?= e($document['fingerprint']) ?>"
                                data-entitlements="<?= e(json_encode($document['entitlements'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?>">Yenile</button>
                            <button class="btn danger" type="button"
                                data-license-revoke="<?= (int) $document['document_id'] ?>">İptal</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$issuerData['documents']): ?><tr><td colspan="5" class="muted">Henüz lisans üretilmedi.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</article>

<script>document.addEventListener('DOMContentLoaded', () => Astera.pageLicenseManager());</script>
