<?php
$certificate = pbx_ssh(
    "certbot certificates 2>&1 || true\n"
    . "printf '\\n--- Otomatik yenileme ---\\n'\n"
    . "systemctl is-enabled certbot.timer 2>/dev/null || true\n"
    . "systemctl is-active certbot.timer 2>/dev/null || true\n"
    . "systemctl list-timers certbot.timer --no-pager 2>/dev/null || true"
);
?>
<div class="grid-2">
    <article class="card">
        <h2>Let’s Encrypt sertifikası</h2>
        <p class="muted">
            Sertifika 90 gün geçerlidir. Certbot zamanlayıcısı sertifikayı süresi dolmadan otomatik yeniler
            ve Nginx’i yeniden yükler.
        </p>
        <form id="sslIssueForm">
            <label>Alan adı
                <input name="domain" value="<?= e(PANEL_DOMAIN) ?>" readonly>
            </label>
            <label>Let’s Encrypt bildirim e-postası
                <input name="email" type="email" required placeholder="admin@astera.com.tr">
            </label>
            <div class="modal-actions">
                <button class="btn primary" type="submit">Sertifikayı al / yapılandır</button>
            </div>
        </form>
        <p class="tiny muted">
            İşlemden önce TCP 80 ve 443 portları bu santrale yönlendirilmiş olmalıdır.
        </p>
    </article>
    <article class="card">
        <h2>Yenileme kontrolü</h2>
        <p class="muted">Gerçek sertifikayı değiştirmeden Let’s Encrypt yenileme işlemini sınar.</p>
        <button class="btn" type="button" id="sslDryRun">Yenilemeyi test et</button>
    </article>
</div>
<article class="card">
    <h2>Durum</h2>
    <pre class="log" id="sslOutput"><?= e(trim((string) ($certificate['output'] ?? ''))) ?></pre>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageSsl());</script>
