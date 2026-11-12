<?php
$certificate = pbx_ssh(
    "certbot certificates 2>&1 || true\n"
    . "printf '\\n--- DNS eklentisi ---\\n'\n"
    . "certbot plugins 2>/dev/null | grep -A3 -B1 'dns-cloudflare' || true\n"
    . "printf '\\n--- Otomatik yenileme ---\\n'\n"
    . "systemctl is-enabled certbot.timer 2>/dev/null || true\n"
    . "systemctl is-active certbot.timer 2>/dev/null || true\n"
    . "systemctl list-timers certbot.timer --no-pager 2>/dev/null || true\n"
    . "printf '\\n--- Asterisk sertifika dağıtım logu ---\\n'\n"
    . "tail -n 20 /var/log/asterisk/cert-renewal.log 2>/dev/null || true"
);
$dnsBaseDomain = 'asterapbx.net';
$dnsPbxName = str_ends_with(PANEL_DOMAIN, '.' . $dnsBaseDomain)
    ? substr(PANEL_DOMAIN, 0, -(strlen($dnsBaseDomain) + 1))
    : '';
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
        <h2>Cloudflare DNS ile sertifika</h2>
        <p class="muted">
            DNS-01 doğrulamasıyla sertifika alınır; sertifika işlemi için 80/443 port yönlendirmesi gerekmez.
            Sertifika Asterisk TLS/WebRTC ve Nginx’e kurulur; yeni alt alan adı otomatik olarak etkinleştirilir.
        </p>
        <form id="sslDnsIssueForm" class="ssl-dns-form" autocomplete="off">
            <label class="ssl-field">Santral adı
                <input name="pbx_name" required pattern="[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?"
                       value="<?= e($dnsPbxName) ?>" placeholder="pbx001">
                <span class="tiny muted">Yalnız küçük harf, rakam ve tire kullanın.</span>
            </label>
            <label class="ssl-field">Oluşacak alan adı
                <input id="sslDnsDomainPreview" value="&lt;santral-adı&gt;.<?= e($dnsBaseDomain) ?>" readonly>
            </label>
            <label class="ssl-field ssl-field-wide">Let’s Encrypt bildirim e-postası
                <input name="email" type="email" required placeholder="admin@astera.com.tr">
            </label>
            <label class="ssl-field ssl-field-wide">Cloudflare API Token
                <div class="ssl-secret">
                    <input name="cloudflare_token" type="password" required autocomplete="new-password"
                           spellcheck="false" placeholder="Cloudflare API Token değerini buraya yapıştırın">
                    <button class="btn" type="button" id="sslTokenToggle" aria-pressed="false">Göster</button>
                </div>
            </label>
            <p class="tiny muted ssl-field-wide ssl-token-help">
                Token yalnız <code><?= e($dnsBaseDomain) ?></code> bölgesi için Zone / DNS / Edit yetkisine sahip olmalıdır.
                Sunucuda root erişimli dosyada saklanır; panel veritabanına yazılmaz.
            </p>
            <div class="modal-actions ssl-field-wide">
                <button class="btn primary" type="submit">DNS ile al ve santrale kur</button>
            </div>
        </form>
        <p class="tiny muted">
            DNS doğrulaması port istemez. Web paneline internetten erişim için ayrıca DNS A/AAAA kaydı ve kullanılan HTTPS portu erişilebilir olmalıdır.
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
