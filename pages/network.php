<?php
$interfaces = network_inventory();
$savedRows = network_settings();
$liveRoutes = network_live_routes();
$liveRules = network_live_rules();
$staticRoutes = network_static_routes();
$routeIdentity = static function (
    string $destination,
    string $gateway,
    string $interface,
    string $metric
): string {
    $destination = $destination === 'default' ? '0.0.0.0/0' : $destination;
    return implode('|', [$destination, $gateway, $interface, (string) (int) $metric]);
};
$managedRouteByIdentity = [];
foreach ($staticRoutes as $route) {
    if (!filter_var($route['enabled'] ?? false, FILTER_VALIDATE_BOOL)) {
        continue;
    }
    $managedRouteByIdentity[$routeIdentity(
        (string) ($route['destination'] ?? ''),
        (string) ($route['gateway'] ?? ''),
        (string) ($route['interface_name'] ?? ''),
        (string) ($route['metric'] ?? '0')
    )] = $route;
}
$savedByInterface = [];
foreach ($savedRows as $row) {
    $savedByInterface[(string) ($row['interface_name'] ?? '')] = $row;
}
$routeColorByInterface = [];
foreach ($interfaces as $interface) {
    $interfaceName = (string) ($interface['interface_name'] ?? '');
    if ($interfaceName !== '' && !isset($routeColorByInterface[$interfaceName])) {
        $routeColorByInterface[$interfaceName] = count($routeColorByInterface) % 6;
    }
}
foreach ($liveRoutes as $route) {
    $interfaceName = (string) ($route['interface'] ?? '');
    if ($interfaceName !== '' && !isset($routeColorByInterface[$interfaceName])) {
        $routeColorByInterface[$interfaceName] = count($routeColorByInterface) % 6;
    }
}
?>
<article class="card network-intro">
    <div>
        <span class="security-kicker">ETHERNET YÖNETİMİ</span>
        <h2>Santralin ağ bağlantılarını yönetin</h2>
        <p class="muted">
            Her Ethernet kartını bir firmaya atayın; otomatik veya statik IPv4 bilgilerini
            tek ekrandan güvenle yapılandırın.
        </p>
    </div>
    <div class="network-intro-status">
        <span>Algılanan Ethernet kartı</span>
        <strong><?= count($interfaces) ?></strong>
        <small>Panel adresi: <?= e(PBX_HOST) ?></small>
        <button class="btn sm" type="button" id="networkRefresh">Durumu yenile</button>
    </div>
</article>

<div class="network-interface-list">
<?php foreach ($interfaces as $interfaceIndex => $live):
    $name = (string) $live['interface_name'];
    $saved = $savedByInterface[$name] ?? [];
    $method = (string) ($saved['method'] ?? $live['method'] ?? 'manual');
    $pendingConfirmation = !empty($saved['rollback_unit']) && empty($saved['confirmed']);
    $savedIpv4 = (string) ($saved['ipv4_cidr'] ?? '');
    $connected = (string) ($live['ipv4_cidr'] ?? '') !== ''
        || str_starts_with((string) $live['state'], '100 ');
?>
    <article class="card network-interface-card" data-network-card="<?= e($name) ?>">
        <header class="network-interface-header">
            <div class="network-interface-title">
                <span class="security-kicker">ETHERNET <?= $interfaceIndex + 1 ?></span>
                <div>
                    <h2><?= e($name) ?></h2>
                    <span class="pill <?= $connected ? 'ok' : 'dim' ?>">
                        <?= $connected ? 'Bağlı' : 'Bağlı değil' ?>
                    </span>
                </div>
                <p><?= e((string) ($live['connection_name'] ?: 'NetworkManager profili bulunamadı')) ?></p>
            </div>
            <div class="network-live-grid">
                <div>
                    <span>Aktif IPv4</span>
                    <strong><?= e((string) ($live['ipv4_cidr'] ?: 'Atanmamış')) ?></strong>
                    <?php if (!$live['ipv4_cidr'] && $savedIpv4 !== ''): ?><small>Kayıtlı: <?= e($savedIpv4) ?></small><?php endif; ?>
                </div>
                <div><span>Ağ geçidi</span><strong><?= e((string) ($live['gateway'] ?: 'Atanmamış')) ?></strong></div>
                <div><span>DNS</span><strong><?= e((string) ($live['dns_servers'] ?: 'Otomatik / yok')) ?></strong></div>
                <div><span>MAC adresi</span><strong><?= e((string) ($live['mac_address'] ?: '—')) ?></strong></div>
            </div>
        </header>
        <form class="network-interface-form">
            <input type="hidden" name="interface_name" value="<?= e($name) ?>">
            <div class="network-setting-grid">
                <section class="network-step-card">
                    <div class="security-rule-heading">
                        <span class="security-step">1</span>
                        <div>
                            <h2>Firma ve bağlantı tipi</h2>
                            <p>Bu fiziksel portun ait olduğu firmayı ve IP alma yöntemini seçin.</p>
                        </div>
                    </div>
                    <div class="network-step-fields">
                        <label>Firma
                            <select name="dept" required>
                                <option value="">Firma seçin</option>
                                <?php foreach (departments() as $department): ?>
                                    <option value="<?= e((string) $department['id']) ?>"
                                        <?= (string) ($saved['dept_id'] ?? current_dept_id() ?? '') === (string) $department['id'] ? 'selected' : '' ?>>
                                        <?= e((string) $department['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>IPv4 yöntemi
                            <select name="method">
                                <option value="dhcp" <?= $method === 'dhcp' ? 'selected' : '' ?>>DHCP — otomatik al</option>
                                <option value="manual" <?= $method === 'manual' ? 'selected' : '' ?>>Statik — elle gir</option>
                            </select>
                        </label>
                    </div>
                    <p class="network-method-help" data-network-method-help></p>
                </section>

                <section class="network-step-card">
                    <div class="security-rule-heading">
                        <span class="security-step">2</span>
                        <div>
                            <h2>IPv4 ve internet bilgileri</h2>
                            <p>Statik kullanımda adresi prefix ile girin. Örnek: <code>192.168.181.74/24</code></p>
                        </div>
                    </div>
                    <div class="network-step-fields">
                        <label>IPv4 adresi / prefix
                            <input name="ipv4_cidr" value="<?= e((string) ($saved['ipv4_cidr'] ?? $live['ipv4_cidr'])) ?>"
                                placeholder="192.168.181.74/24" inputmode="decimal">
                        </label>
                        <label>Varsayılan ağ geçidi
                            <input name="gateway" value="<?= e((string) ($saved['gateway'] ?? $live['gateway'])) ?>"
                                placeholder="192.168.181.249" inputmode="decimal">
                        </label>
                        <label>DNS sunucuları
                            <input name="dns_servers" value="<?= e((string) ($saved['dns_servers'] ?? $live['dns_servers'])) ?>"
                                placeholder="1.1.1.1, 8.8.8.8">
                            <small>Birden fazlaysa virgülle ayırın.</small>
                        </label>
                        <label>MTU
                            <input name="mtu" type="number" min="576" max="9000"
                                value="<?= (int) ($saved['mtu'] ?? $live['mtu'] ?? 1500) ?>">
                            <small>Standart Ethernet değeri 1500’dür.</small>
                        </label>
                    </div>
                </section>
            </div>

            <section class="network-apply-card">
                <div class="security-rule-heading">
                    <span class="security-step">3</span>
                    <div>
                        <h2>Kaydedin, uygulayın ve doğrulayın</h2>
                        <p>Önce ayarı kaydedebilir veya doğrudan santrale uygulayabilirsiniz.</p>
                    </div>
                </div>
                <div class="network-safety <?= $pendingConfirmation ? 'pending' : '' ?>">
                    <strong><?= $pendingConfirmation ? 'Onay bekleniyor' : 'Erişim koruması etkin' ?></strong>
                    <span>
                        <?= $pendingConfirmation
                            ? 'Yeni bağlantı çalışıyorsa 3 dakika dolmadan onaylayın.'
                            : 'Onaylanmayan değişiklik 3 dakika sonra otomatik geri alınır.' ?>
                    </span>
                </div>
                <div class="network-actions">
                    <button class="btn" type="button" data-network-save>1. Ayarı kaydet</button>
                    <button class="btn danger" type="button" data-network-apply>2. Kaydet ve uygula</button>
                    <button class="btn primary" type="button" data-network-confirm
                        <?= $pendingConfirmation ? '' : 'disabled' ?>>3. Çalıştığını onayla</button>
                </div>
            </section>
            <pre class="log network-output" hidden></pre>
        </form>
    </article>
<?php endforeach; ?>
<?php if (!$interfaces): ?>
    <article class="card"><p class="muted">Santralde Ethernet arayüzü algılanmadı.</p></article>
<?php endif; ?>
</div>

<div class="grid-2 network-routing-grid">
    <article class="card">
        <div class="network-routing-heading">
            <div>
                <span class="security-kicker">CANLI DURUM</span>
                <h2>Aktif Kernel Routing Tablosu</h2>
            </div>
            <div class="network-route-filter">
                <label>Ethernet kartı
                    <select id="networkRouteInterfaceFilter">
                        <option value="">Tüm kartlar</option>
                        <?php foreach ($interfaces as $interface):
                            $filterInterface = (string) $interface['interface_name'];
                            $filterLiveIp = (string) ($interface['ipv4_cidr'] ?? '');
                            $filterSavedIp = (string) ($savedByInterface[$filterInterface]['ipv4_cidr'] ?? '');
                            $filterIp = $filterLiveIp !== '' ? $filterLiveIp : $filterSavedIp;
                        ?>
                            <option value="<?= e($filterInterface) ?>"><?= e($filterInterface) ?> · <?= e($filterIp !== '' ? $filterIp : 'IP yok') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <span class="pill ok" id="networkRouteVisibleCount"><?= count($liveRoutes) ?> aktif · <?= count($staticRoutes) ?> tanımlı</span>
                <button class="btn primary sm" type="button" id="networkRouteAdd">Yeni rota ekle</button>
            </div>
        </div>
        <div class="network-route-table-wrap">
            <table class="network-route-table">
                <thead><tr><th>Hedef</th><th>Gateway</th><th>Arayüz</th><th>Kaynak</th><th>Metric</th><th>Tablo / Protokol</th><th>Tür</th><th>İşlem</th></tr></thead>
                <tbody>
                <?php foreach ($liveRoutes as $route):
                    $routeInterface = (string) ($route['interface'] ?? '');
                    $routeColor = $routeColorByInterface[$routeInterface] ?? 0;
                    $managedRoute = $managedRouteByIdentity[$routeIdentity(
                        (string) ($route['destination'] ?? ''),
                        (string) ($route['gateway'] ?? ''),
                        $routeInterface,
                        (string) ($route['metric'] ?? '0')
                    )] ?? null;
                    $managedRouteData = $managedRoute ? [
                        'route_id' => (int) $managedRoute['route_id'],
                        'dept' => (string) $managedRoute['dept_id'],
                        'interface_name' => $routeInterface,
                        'destination' => (string) $managedRoute['destination'],
                        'gateway' => (string) $managedRoute['gateway'],
                        'metric' => (int) $managedRoute['metric'],
                        'description' => (string) $managedRoute['description'],
                    ] : null;
                ?>
                    <tr class="network-route-color-<?= $routeColor ?>" data-route-kind="live" data-route-interface="<?= e($routeInterface) ?>">
                        <td><code><?= e((string) $route['destination']) ?></code></td>
                        <td><?= e((string) ($route['gateway'] ?: 'doğrudan')) ?></td>
                        <td><span class="network-route-interface"><?= e((string) ($routeInterface ?: '—')) ?></span></td>
                        <td><?= e((string) ($route['source'] ?: '—')) ?></td>
                        <td><?= e((string) ($route['metric'] !== '' ? $route['metric'] : '—')) ?></td>
                        <td><?= e((string) $route['table']) ?> · <?= e((string) ($route['protocol'] ?: '—')) ?></td>
                        <td>
                            <span class="pill <?= $managedRoute ? 'ok' : 'dim' ?>">
                                <?= $managedRoute ? 'Panel rotası' : 'Sistem / sabit' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($managedRoute): ?>
                                <button class="btn sm" type="button"
                                    data-network-route-edit="<?= e(json_encode($managedRouteData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">Düzenle</button>
                                <button class="btn sm danger" type="button"
                                    data-network-route-delete="<?= (int) $managedRoute['route_id'] ?>">Sil</button>
                            <?php else: ?>
                                <span class="muted">Korunuyor</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$liveRoutes): ?><tr><td colspan="8" class="muted">Aktif IPv4 rotası bulunamadı.</td></tr><?php endif; ?>
                <tr id="networkRouteFilterEmpty" hidden><td id="networkRouteFilterEmptyText" colspan="8" class="muted">Seçilen Ethernet kartına ait aktif rota bulunamadı.</td></tr>
                </tbody>
            </table>
        </div>
        <?php if ($liveRules): ?>
            <details class="network-route-rules">
                <summary>Policy routing kuralları (<?= count($liveRules) ?>)</summary>
                <pre class="log"><?= e(implode("\n", $liveRules)) ?></pre>
            </details>
        <?php endif; ?>
    </article>

    <article class="card">
        <span class="security-kicker">KALICI YAPILANDIRMA</span>
        <h2>Statik Rota Ekle</h2>
        <p class="muted">Eklenen rotalar hemen uygulanır ve santral yeniden başladığında otomatik yüklenir.</p>
        <form id="networkRouteForm" class="form-grid network-route-form">
            <input type="hidden" name="route_id" value="">
            <label>Firma
                <select name="dept" required>
                    <option value="">Firma seçin</option>
                    <?php foreach (departments() as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>"><?= e((string) $department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Ethernet kartı
                <select name="interface_name" required>
                    <?php foreach ($interfaces as $interface):
                        $interfaceName = (string) $interface['interface_name'];
                        $liveIpv4 = (string) ($interface['ipv4_cidr'] ?? '');
                        $configuredIpv4 = (string) ($savedByInterface[$interfaceName]['ipv4_cidr'] ?? '');
                        $interfaceDept = (string) ($savedByInterface[$interfaceName]['dept_id'] ?? '');
                        $ipLabel = $liveIpv4 !== ''
                            ? $liveIpv4 . ' (aktif)'
                            : ($configuredIpv4 !== '' ? $configuredIpv4 . ' (kayıtlı, aktif değil)' : 'IP yok');
                    ?>
                        <option value="<?= e($interfaceName) ?>" data-dept="<?= e($interfaceDept) ?>"><?= e($interfaceName) ?> · <?= e($ipLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Hedef IP / ağ
                <input name="destination" required placeholder="10.0.10.10 veya 10.20.0.0/16" inputmode="decimal">
                <small>Tek IP girilirse otomatik olarak /32 host rotası oluşturulur.</small>
            </label>
            <label>Ağ geçidi
                <input name="gateway" required placeholder="192.168.181.1" inputmode="decimal">
            </label>
            <label>Metric
                <input name="metric" type="number" min="0" max="65535" value="100" required>
            </label>
            <label>Açıklama
                <input name="description" maxlength="160" placeholder="Otel PMS ağı">
            </label>
            <div class="network-route-form-actions wide">
                <button class="btn primary" type="submit">Rotayı Kaydet ve Uygula</button>
                <button class="btn" type="button" id="networkRouteEditCancel" hidden>Düzenlemeyi İptal Et</button>
            </div>
        </form>
        <pre class="log network-route-output" hidden></pre>
    </article>
</div>

<article class="card">
    <div class="network-routing-heading">
        <div>
            <span class="security-kicker">VERİTABANI</span>
            <h2>Tanımlı Kalıcı Rotalar</h2>
        </div>
        <span class="pill"><?= count($staticRoutes) ?> kayıt</span>
    </div>
    <div class="network-route-table-wrap">
        <table class="network-route-table">
            <thead><tr><th>Firma</th><th>Hedef</th><th>Gateway</th><th>Arayüz</th><th>Metric</th><th>Durum</th><th>Açıklama</th><th>İşlem</th></tr></thead>
            <tbody>
            <?php foreach ($staticRoutes as $route):
                $routeInterface = (string) ($route['interface_name'] ?? '');
                $routeColor = $routeColorByInterface[$routeInterface] ?? 0;
                $routeEnabled = filter_var($route['enabled'] ?? false, FILTER_VALIDATE_BOOL);
            ?>
                <tr class="network-route-color-<?= $routeColor ?>" data-route-kind="static" data-route-interface="<?= e($routeInterface) ?>">
                    <td><?= e((string) $route['dept_name']) ?></td>
                    <td><code><?= e((string) $route['destination']) ?></code></td>
                    <td><?= e((string) $route['gateway']) ?></td>
                    <td><span class="network-route-interface"><?= e($routeInterface) ?></span></td>
                    <td><?= (int) $route['metric'] ?></td>
                    <td><span class="pill <?= $routeEnabled ? 'ok' : 'dim' ?>"><?= $routeEnabled ? 'uygulandı' : 'bekliyor' ?></span></td>
                    <td><?= e((string) ($route['description'] ?: '—')) ?></td>
                    <td>
                        <button class="btn sm" type="button"
                            data-network-route-edit="<?= e(json_encode([
                                'route_id' => (int) $route['route_id'],
                                'dept' => (string) $route['dept_id'],
                                'interface_name' => $routeInterface,
                                'destination' => (string) $route['destination'],
                                'gateway' => (string) $route['gateway'],
                                'metric' => (int) $route['metric'],
                                'description' => (string) $route['description'],
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">Düzenle</button>
                        <button class="btn sm danger" type="button" data-network-route-delete="<?= (int) $route['route_id'] ?>">Sil</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$staticRoutes): ?><tr><td colspan="8" class="muted">Henüz kalıcı statik rota tanımlanmadı.</td></tr><?php endif; ?>
            <tr id="networkStaticRouteFilterEmpty" hidden><td colspan="8" class="muted">Seçilen Ethernet kartına ait kalıcı rota bulunamadı.</td></tr>
            </tbody>
        </table>
    </div>
</article>

<script>
window.ASTERA = window.ASTERA || {};
window.ASTERA.networkInterfaces = <?= json_encode($interfaces, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
document.addEventListener('DOMContentLoaded', () => Astera.pageNetwork());
</script>
