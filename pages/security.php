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
<div class="grid-2">
    <article class="card">
        <h2>IP izinleri</h2>
        <p class="muted">
            Mevcut bağlantı IP’niz: <code><?= e($currentIp ?: 'bilinmiyor') ?></code>.
            Uygulama sırasında bu adres yönetim listesine otomatik eklenir.
        </p>
        <form id="securityFirewallForm">
            <label>Yönetim IP/ağları
                <textarea name="management_networks" rows="6" required
                    placeholder="192.168.181.0/24"><?= e(implode("\n", $managementNetworks)) ?></textarea>
                <small class="muted">SSH 22 ve PostgreSQL 5432 yalnız bu adreslerden kabul edilir.</small>
            </label>
            <label>SIP operatörü / telefon IP-ağları
                <textarea name="sip_networks" rows="6" required
                    placeholder="45.10.252.253/32"><?= e(implode("\n", $sipNetworks)) ?></textarea>
                <small class="muted">SIP 5060/5061 ile yerel WebRTC 8088/8089 yalnız bu adreslerden kabul edilir.</small>
            </label>
            <label>
                <input type="checkbox" name="public_web" value="1" <?= $publicWeb ? 'checked' : '' ?>>
                HTTPS paneli internete açık tut
            </label>
            <p class="tiny muted">
                Panel internete açıksa uzaktaki WebRTC kullanıcılarının şifreli ses trafiği için
                UDP 10000–20000 de açılır. SIP yönetim portları IP listesiyle sınırlı kalır.
            </p>
            <div class="modal-actions">
                <button class="btn danger" type="submit">Kuralları uygula</button>
            </div>
        </form>
    </article>
    <article class="card">
        <h2>Güvenli uygulama</h2>
        <p class="muted">
            Yeni kurallar uygulandıktan sonra 5 dakikalık otomatik geri alma başlar.
            Panel, trunk ve telefonlar çalışıyorsa aşağıdaki düğmeyle kuralları kalıcılaştırın.
        </p>
        <button class="btn primary" type="button" id="securityConfirm"
            <?= !empty($firewall['rollback_unit']) && empty($firewall['confirmed']) ? '' : 'disabled' ?>>
            Kuralları onayla
        </button>
        <p class="tiny muted">
            Onay verilmezse UFW otomatik kapatılır; erişiminiz kaybolmaz.
        </p>
    </article>
</div>
<article class="card">
    <h2>Canlı durum</h2>
    <pre class="log" id="securityOutput"><?= e(trim((string) ($ufw['output'] ?? ''))) ?></pre>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageSecurity());</script>
