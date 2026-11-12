<?php
$firewall = find_by('security_settings', 'id', 'firewall') ?? [];
$managementNetworks = (array) ($firewall['management_networks'] ?? [PBX_LOCAL_NET]);
$sipNetworks = (array) ($firewall['sip_networks'] ?? [PBX_LOCAL_NET]);
foreach (store_read('trunks') as $trunk) {
    $sources = array_merge(
        [trim((string) ($trunk['host'] ?? ''))],
        (array) ($trunk['identify_matches'] ?? [])
    );
    foreach ($sources as $source) {
        $source = trim((string) $source);
        if (filter_var($source, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $sipNetworks[] = $source . '/32';
        } elseif (preg_match('/^[0-9.]+\/(?:[0-9]|[12][0-9]|3[0-2])$/D', $source) === 1) {
            $sipNetworks[] = $source;
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
$ufwOutput = trim((string) ($ufw['output'] ?? ''));
$ufwActive = preg_match('/^(?:Status:\s*active|Durum:\s*etkin)\s*$/mi', $ufwOutput) === 1;
$currentIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$geoPolicyMode = geoip_normalize_policy_mode($firewall['geoip_policy_mode'] ?? 'allow_only');
$geoRules = geoip_normalize_rules(
    array_key_exists('geoip_rules', $firewall) ? $firewall['geoip_rules'] : geoip_default_rules()
);
$geoRulesByCountry = [];
foreach ($geoRules as $geoRule) {
    $geoRulesByCountry[$geoRule['country']] = $geoRule;
}
$geoContinents = geoip_continents();
$geoStatus = geoip_remote_status();
$geoLastUpdate = !empty($geoStatus['last_update'])
    ? date('d.m.Y H:i:s', (int) $geoStatus['last_update'])
    : 'Henüz güncellenmedi';
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
    <div class="security-intro-side">
        <div class="security-ufw-state <?= $ufwActive ? 'is-on' : 'is-off' ?>"
            id="securityUfwState" role="status"
            aria-label="UFW <?= $ufwActive ? 'açık' : 'kapalı' ?>">
            <span class="security-ufw-switch" aria-hidden="true"><i></i></span>
            <span>
                <small>Güvenlik duvarı</small>
                <strong id="securityUfwStateText">UFW <?= $ufwActive ? 'AÇIK' : 'KAPALI' ?></strong>
            </span>
        </div>
        <div class="security-current-ip">
            <span>Şu an bağlandığınız IP</span>
            <strong><?= e($currentIp ?: 'Bilinmiyor') ?></strong>
            <small>Kurallar uygulanırken yönetim listesine otomatik eklenir.</small>
        </div>
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

<form id="securityGeoipForm" class="security-form security-geoip-form">
    <article class="card security-geoip-card">
        <div class="security-rule-heading">
            <span class="security-step">4</span>
            <div>
                <h2>SIP ve SSH ülke filtresi</h2>
                <p>
                    Ülke CIDR listeleri IPv4 ve IPv6 için ayrı indirilir. Operatör ve yönetim
                    ağlarınız filtreden önce korunur. İzinli ülkeler SIP 5060 ve tanımlı SSH
                    portlarına erişebilir; HTTPS ve RTP bu filtreden etkilenmez.
                </p>
            </div>
        </div>

        <div class="security-geoip-status">
            <span>
                <small>Altyapı</small>
                <strong><?= !empty($geoStatus['installed']) ? 'Kurulu' : 'Kurulu değil' ?></strong>
            </span>
            <span>
                <small>Son başarılı güncelleme</small>
                <strong id="geoipLastUpdate"><?= e($geoLastUpdate) ?></strong>
            </span>
            <span>
                <small>Otomatik güncelleme</small>
                <strong><?= !empty($geoStatus['timer_enabled']) ? 'Günlük etkin' : 'Bekliyor' ?></strong>
            </span>
            <span>
                <small>Canlı varsayılan politika</small>
                <strong>
                    <?= ($geoStatus['policy_mode'] ?? '') === 'allow_only'
                        ? 'Allow-only · diğer ülkeler engelli'
                        : 'Mevcut UFW’ye devam' ?>
                </strong>
            </span>
            <span>
                <small>Yüklü CIDR</small>
                <strong>
                    IPv4 <?= (int) (($geoStatus['allow_ipv4'] ?? 0) + ($geoStatus['block_ipv4'] ?? 0)) ?>
                    · IPv6 <?= (int) (($geoStatus['allow_ipv6'] ?? 0) + ($geoStatus['block_ipv6'] ?? 0)) ?>
                </strong>
            </span>
        </div>

        <label class="security-geoip-policy">
            <span>
                <strong>Varsayılan ülke politikası</strong>
                <small>
                    Allow-only seçildiğinde operatör/yönetim istisnaları ve izin verilen ülkeler
                    dışındaki tüm SIP 5060 TCP/UDP ve SSH trafiği düşürülür.
                </small>
            </span>
            <select id="geoipPolicyMode" name="geo_policy_mode">
                <option value="allow_only" <?= $geoPolicyMode === 'allow_only' ? 'selected' : '' ?>>
                    Yalnız izin verilen ülkeler · diğerlerini engelle
                </option>
                <option value="passthrough" <?= $geoPolicyMode === 'passthrough' ? 'selected' : '' ?>>
                    Seçilmeyen ülkeleri mevcut UFW kurallarına bırak
                </option>
            </select>
        </label>

        <div class="security-geoip-toolbar">
            <label>
                <span>Kıta seçimi</span>
                <select id="geoipContinent">
                    <?php foreach ($geoContinents as $continentCode => $continent): ?>
                        <option value="<?= e($continentCode) ?>"><?= e($continent['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Ülke ara</span>
                <input id="geoipCountrySearch" type="search" placeholder="Türkiye, Almanya…" autocomplete="off">
            </label>
            <div class="security-geoip-legend">
                <span><i class="allow"></i> İzin: ülkenin SIP ve SSH trafiğini kabul eder</span>
                <span><i class="block"></i> Engel: ülkenin SIP ve SSH trafiğini düşürür</span>
                <span id="geoipNeutralLegend"><i class="neutral"></i>
                    Kural yok: <?= $geoPolicyMode === 'allow_only'
                        ? 'SIP ve SSH trafiği varsayılan olarak engellenir'
                        : 'mevcut UFW davranışı sürer' ?>
                </span>
            </div>
        </div>

        <div class="security-country-list" id="geoipCountryList">
            <?php foreach ($geoContinents as $continentCode => $continent): ?>
                <?php foreach ($continent['countries'] as $countryCode): ?>
                    <?php
                    $countryName = geoip_country_name($countryCode);
                    $rule = $geoRulesByCountry[$countryCode] ?? [];
                    $action = (string) ($rule['action'] ?? '');
                    ?>
                    <div class="security-country-row"
                        data-continent="<?= e($continentCode) ?>"
                        data-country="<?= e($countryCode) ?>"
                        data-search="<?= e(mb_strtolower($countryName . ' ' . $countryCode, 'UTF-8')) ?>">
                        <span class="security-country-name">
                            <strong><?= e($countryName) ?></strong>
                            <small><?= e($countryCode) ?></small>
                        </span>
                        <select data-geo-action aria-label="<?= e($countryName) ?> kuralı">
                            <option value="" <?= $action === '' ? 'selected' : '' ?>>Kural yok</option>
                            <option value="allow" <?= $action === 'allow' ? 'selected' : '' ?>>İzin ver</option>
                            <option value="block" <?= $action === 'block' ? 'selected' : '' ?>>Engelle</option>
                        </select>
                        <label class="security-family-check">
                            <input type="checkbox" data-geo-ipv4 <?= !empty($rule['ipv4']) ? 'checked' : '' ?>>
                            IPv4
                        </label>
                        <label class="security-family-check">
                            <input type="checkbox" data-geo-ipv6 <?= !empty($rule['ipv6']) ? 'checked' : '' ?>>
                            IPv6
                        </label>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <div class="security-geoip-actions">
            <div class="security-safety">
                <strong>Atomik güncelleme</strong>
                <span>Yeni listeler doğrulanmadan mevcut ipset listeleri ve güvenlik kuralları değiştirilmez.</span>
            </div>
            <span class="security-action-status" id="geoipActionStatus" role="status" aria-live="polite"></span>
            <button class="btn" type="button" id="geoipUpdateNow">Listeleri şimdi güncelle</button>
            <button class="btn primary" type="submit">Ülke kurallarını uygula</button>
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
    <pre class="log" id="securityOutput"><?= e($ufwOutput) ?></pre>
</details>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageSecurity());</script>
