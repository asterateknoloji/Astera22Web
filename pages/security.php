<?php
$firewall = find_by('security_settings', 'id', 'firewall') ?? [];
$managementNetworks = (array) ($firewall['management_networks'] ?? [PBX_LOCAL_NET]);
$sipNetworks = (array) ($firewall['sip_networks'] ?? [PBX_LOCAL_NET]);
if (!$firewall) {
    foreach (store_read('trunks') as $trunk) {
        $host = trim((string) ($trunk['host'] ?? ''));
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $sipNetworks[] = $host . '/32';
        }
    }
}
$managementNetworks = array_values(array_unique($managementNetworks));
$sipNetworks = array_values(array_unique($sipNetworks));
$publicWeb = array_key_exists('public_web', $firewall) ? !empty($firewall['public_web']) : true;
$ufw = pbx_ssh(
    "printf '%s\\n' '--- UFW ---'\nufw status numbered 2>&1 || true\n"
    . "printf '%s\\n' '--- Açık portlar ---'\nss -lntp 2>&1 || true"
);
$currentIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
?>
<article class="card security-intro">
    <div>
        <span class="security-kicker">GÜVENLİK DUVARI</span>
        <h2>Santrale kimlerin erişebileceğini belirleyin</h2>
        <p class="muted">
            Her satıra bir IP adresi veya ağ yazın. Tek cihaz için <code>/32</code>,
            bir yerel ağ için <code>/24</code> kullanabilirsiniz.
        </p>
    </div>
    <div class="security-current-ip">
        <span>Şu an bağlandığınız IP</span>
        <strong><?= e($currentIp ?: 'Bilinmiyor') ?></strong>
        <small>Kurallar uygulanırken yönetim listesine otomatik eklenir.</small>
    </div>
</article>

<form id="securityFirewallForm" class="security-form">
    <div class="security-rule-grid">
        <section class="card security-rule-card">
            <div class="security-rule-heading">
                <span class="security-step">1</span>
                <div>
                    <h2>Yönetim erişimi</h2>
                    <p>Santrala doğrudan bağlı özel yerel ağlar otomatik olarak tamamen izinlidir. Aşağıya diğer güvenilir yönetim ağlarını ekleyebilirsiniz.</p>
                </div>
            </div>
            <label class="security-field">
                <span>İzin verilen IP adresleri / ağlar</span>
                <textarea name="management_networks" rows="7" required
                    spellcheck="false"
                    placeholder="192.168.181.0/24"><?= e(implode("\n", $managementNetworks)) ?></textarea>
            </label>
            <div class="security-port-list" aria-label="Yönetim portları">
                <span>SSH <strong>22, 45555 ve sunucuda tanımlı SSH portları</strong> (daima açık)</span>
                <span>PostgreSQL <strong>5432</strong></span>
                <span>Webmin <strong>10000</strong></span>
            </div>
        </section>

        <section class="card security-rule-card">
            <div class="security-rule-heading">
                <span class="security-step">2</span>
                <div>
                    <h2>Telefon ve operatör erişimi</h2>
                    <p>SIP operatörleri, IP telefonlar ve yerel WebRTC cihazları için izin verilen ağlar.</p>
                </div>
            </div>
            <label class="security-field">
                <span>İzin verilen IP adresleri / ağlar</span>
                <textarea name="sip_networks" rows="7" required
                    spellcheck="false"
                    placeholder="45.10.252.253/32"><?= e(implode("\n", $sipNetworks)) ?></textarea>
            </label>
            <div class="security-port-list" aria-label="Telefon portları">
                <span>SIP <strong>5060 / 5061</strong></span>
                <span>WebRTC <strong>8088 / 8089</strong></span>
            </div>
        </section>
    </div>

    <article class="card security-web-card">
        <label class="security-toggle">
            <input type="checkbox" name="public_web" value="1" <?= $publicWeb ? 'checked' : '' ?>>
            <span>
                <strong>HTTPS panelini internete açık tut</strong>
                <small>
                    Açık olduğunda uzaktaki kullanıcılar panele ve WebPhone’a erişebilir.
                    Şifreli ses için UDP 10000–20000 açılır; SIP portları yukarıdaki listeyle sınırlı kalır.
                </small>
            </span>
        </label>
    </article>

    <article class="card security-apply-card">
        <div class="security-rule-heading">
            <span class="security-step">3</span>
            <div>
                <h2>Kuralları uygulayın ve doğrulayın</h2>
                <p>
                    Uygulamadan sonra 5 dakikalık güvenlik süresi başlar. Panel, telefonlar ve
                    trunk’lar çalışıyorsa kuralları kalıcılaştırın.
                </p>
            </div>
        </div>
        <div class="security-safety">
            <strong>Erişim koruması etkin</strong>
            <span>Onay vermezseniz UFW otomatik kapatılır; sunucuya erişiminiz kaybolmaz.</span>
        </div>
        <div class="security-actions">
            <button class="btn danger" type="submit">1. Kuralları uygula</button>
            <button class="btn primary" type="button" id="securityConfirm"
                <?= !empty($firewall['rollback_unit']) && empty($firewall['confirmed']) ? '' : 'disabled' ?>>
                2. Çalıştığını onayla
            </button>
        </div>
    </article>
</form>

<details class="card security-live">
    <summary>
        <span>
            <strong>Teknik durum ve açık portlar</strong>
            <small>UFW kurallarını ve dinleyen servisleri görüntüleyin</small>
        </span>
        <span class="pill dim">Teknik ayrıntı</span>
    </summary>
    <pre class="log" id="securityOutput"><?= e(trim((string) ($ufw['output'] ?? ''))) ?></pre>
</details>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageSecurity());</script>
