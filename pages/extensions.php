<?php
$rows = scoped('extensions');
$outboundRoutes = scoped('outbound');
$priSpans = scoped('pri_spans');
$clientRows = array_map(static function (array $row): array {
    unset($row['password'], $row['vm_pin']);
    $row['pickup_groups'] = extension_pickup_groups($row['pickup_groups'] ?? [1]);
    return $row;
}, $rows);
$clientOutboundRoutes = array_map(static fn(array $route): array => [
    'id' => (string) ($route['id'] ?? ''),
    'dept' => (string) ($route['dept'] ?? ''),
    'name' => (string) ($route['name'] ?? $route['id'] ?? ''),
    'pattern' => (string) ($route['pattern'] ?? ''),
    'trunk' => (string) ($route['trunk'] ?? ''),
], $outboundRoutes);
$clientPriSpans = array_map(static fn(array $span): array => [
    'id' => (string) ($span['id'] ?? ''),
    'dept' => (string) ($span['dept'] ?? ''),
    'name' => (string) ($span['name'] ?? $span['id'] ?? ''),
    'group' => (int) ($span['group'] ?? 0),
    'enabled' => !empty($span['enabled']),
], $priSpans);
$outboundRouteNames = [];
foreach ($outboundRoutes as $route) {
    $outboundRouteNames[(string) ($route['id'] ?? '')] = (string) ($route['name'] ?? $route['id'] ?? '');
}
$defaultFallbacks = [];
foreach (scoped('ivrs') as $ivr) {
    if ((string) ($ivr['exten'] ?? '') === '8502') {
        $defaultFallbacks[(string) ($ivr['dept'] ?? '')] = [
            'type' => 'ivr',
            'dest' => (string) ($ivr['id'] ?? ''),
        ];
    }
}
$showDept = current_dept_id() === null;
$departmentIndex = [];
foreach (departments() as $department) {
    $departmentIndex[(string) ($department['id'] ?? '')] = $department;
}
$licenseScope = current_dept_id();
$extensionLicense = [
    'blocked' => false,
    'enabled' => true,
    'limit' => null,
    'usage' => 0,
    'message' => '',
];
try {
    $extensionLicense['enabled'] = license_feature_enabled('subscribers.extensions', null);
    $extensionLicense['limit'] = license_limit('subscribers.extensions', null);
    $extensionLicense['usage'] = license_usage('subscribers.extensions', null);
    if (!$extensionLicense['enabled']) {
        $extensionLicense['blocked'] = true;
        $extensionLicense['message'] = 'Abone lisansı etkin değil. Yeni abone eklenemez.';
    } elseif ($extensionLicense['limit'] !== null
        && $extensionLicense['usage'] >= $extensionLicense['limit']) {
        $extensionLicense['blocked'] = true;
        $extensionLicense['message'] = sprintf(
            'Abone lisansınız %d, mevcut abone %d. Yeni abone eklenemez.',
            $extensionLicense['limit'],
            $extensionLicense['usage']
        );
    }
    if (!$extensionLicense['blocked'] && $licenseScope !== null
        && license_has_tenant_entitlement('subscribers.extensions', $licenseScope)) {
        $extensionLicense['enabled'] = license_feature_enabled('subscribers.extensions', $licenseScope);
        $extensionLicense['limit'] = license_limit('subscribers.extensions', $licenseScope);
        $extensionLicense['usage'] = license_usage('subscribers.extensions', $licenseScope);
        if (!$extensionLicense['enabled']) {
            $extensionLicense['blocked'] = true;
            $extensionLicense['message'] = 'Firma abone lisansı etkin değil. Yeni abone eklenemez.';
        } elseif ($extensionLicense['limit'] !== null
            && $extensionLicense['usage'] >= $extensionLicense['limit']) {
            $extensionLicense['blocked'] = true;
            $extensionLicense['message'] = sprintf(
                'Firma abone lisansınız %d, mevcut abone %d. Yeni abone eklenemez.',
                $extensionLicense['limit'],
                $extensionLicense['usage']
            );
        }
    }
} catch (Throwable) {
    // API tarafındaki zorunlu lisans kontrolü her durumda korunur.
}
?>
<div class="toolbar">
    <p class="muted">Dahili numara firma içinde kullanılır. SIP kullanıcı adı farklı firmada aynı dahili varsa otomatik öneklenir. Mevcut 1001/1002: <code>1001</code> / <code>1002</code>.</p>
    <div class="toolbar">
        <button class="btn" type="button" id="importExt">CSV ile içe aktar</button>
        <button class="btn" type="button" id="exportExt">Dahili aboneleri dışa aktar</button>
        <button class="btn" type="button" id="copyExtFeatures" disabled>Seçilenleri Düzenle</button>
        <button class="btn primary" type="button" id="addExt">Abone ekle</button>
    </div>
</div>
<article class="card">
    <div class="toolbar">
        <label>Dahili veya isim ara
            <input id="extSearch" autocomplete="off" placeholder="Örn. 121 veya Tuncay">
        </label>
        <div class="toolbar">
            <span class="muted" id="extSelectedCount">0 abone seçildi</span>
            <button class="btn danger" type="button" id="deleteSelectedExt" disabled>Seçilenleri sil</button>
        </div>
    </div>
</article>
<article class="card flush extensions-card">
    <table class="extensions-table">
        <thead>
            <tr>
                <th class="col-select"><input type="checkbox" id="selectAllExt" aria-label="Görünen abonelerin tümünü seç"></th>
                <?php if ($showDept): ?><th class="col-dept">Firma</th><?php endif; ?>
                <th data-extension-sort-header="exten">
                    <button class="table-sort-button" type="button" data-extension-sort="exten">
                        Dahili <span aria-hidden="true">↕</span>
                    </button>
                </th>
                <th data-extension-sort-header="name">
                    <button class="table-sort-button" type="button" data-extension-sort="name">
                        Ad <span aria-hidden="true">↕</span>
                    </button>
                </th>
                <th class="col-pickup">Toplama grupları</th>
                <th>SIP kullanıcı</th>
                <th class="col-auth">Auth kullanıcı</th>
                <th>SIP şifre</th>
                <th>Telefon</th>
                <th class="col-outbound">Dış arama</th>
                <th class="col-actions"><span class="sr-only">İşlemler</span></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php $technology = strtolower((string) ($row['technology'] ?? 'pjsip')); ?>
            <tr data-ext-row
                data-exten="<?= e((string) ($row['exten'] ?? '')) ?>"
                data-name="<?= e((string) ($row['name'] ?? '')) ?>"
                data-search="<?= e(strtolower(trim(
                (string) ($row['exten'] ?? '') . ' '
                . (string) ($row['sipuser'] ?? '') . ' '
                . (string) ($row['name'] ?? '')
            ))) ?>">
                <td class="col-select"><input type="checkbox" class="ext-select" value="<?= e($row['id'] ?? ext_id((string) $row['dept'], (string) $row['exten'])) ?>" aria-label="<?= e((string) $row['exten']) ?> abonesini seç"></td>
                <?php if ($showDept): ?><td class="col-dept" data-label="Firma"><?= e(dept_name((string) ($row['dept'] ?? ''))) ?></td><?php endif; ?>
                <td data-label="Dahili"><code><?= e($row['exten']) ?></code></td>
                <td data-label="Ad"><?= e($row['name'] ?? '') ?></td>
                <td class="col-pickup" data-label="Toplama grupları"><span class="pill"><?= e(implode(', ', extension_pickup_groups($row['pickup_groups'] ?? [1]))) ?></span></td>
                <td data-label="SIP kullanıcı"><code><?= $technology === 'dahdi'
                    ? 'g' . (int) ($row['dahdi_group'] ?? 0) . '/' . e((string) ($row['dahdi_dial'] ?? $row['exten']))
                    : ($technology === 'custom'
                        ? e((string) ($row['custom_dial'] ?? ''))
                        : e($row['sipuser'] ?? $row['exten'])) ?></code></td>
                <td class="col-auth" data-label="Auth kullanıcı"><code><?= $technology !== 'pjsip' ? '—' : e($row['authuser'] ?? $row['sipuser'] ?? $row['exten']) ?></code></td>
                <td data-label="SIP şifre">
                    <?php if ($technology !== 'pjsip'): ?>
                        —
                    <?php else: ?>
                    <div class="extension-secret" data-extension-secret="<?= e($row['id'] ?? ext_id((string) $row['dept'], (string) $row['exten'])) ?>">
                        <input type="password" readonly value="" placeholder="••••••••" aria-label="<?= e((string) $row['exten']) ?> SIP şifresi">
                        <button class="icon-btn" type="button" data-secret-toggle aria-label="SIP şifresini göster" title="Şifreyi göster" aria-pressed="false"><?= ui_icon('eye', 'secret-show') ?><?= ui_icon('eye-off', 'secret-hide') ?></button>
                        <button class="icon-btn" type="button" data-secret-copy aria-label="SIP şifresini kopyala" title="Şifreyi kopyala"><?= ui_icon('copy') ?></button>
                    </div>
                    <?php endif; ?>
                </td>
                <?php
                $phoneModes = is_array($row['phone_modes'] ?? null)
                    ? (array) $row['phone_modes']
                    : (!empty($row['webrtc']) ? ['webrtc'] : ['softphone']);
                $phoneMode = $technology !== 'pjsip' ? $technology : (string) ($phoneModes[0] ?? 'softphone');
                $phoneLabel = [
                    'dahdi' => 'DAHDI / PRI',
                    'custom' => 'Özel Kanal',
                    'webrtc' => 'WebRTC',
                    'softphone' => 'Softphone',
                    'desk_phone' => 'Masaüstü',
                ][$phoneMode] ?? 'Softphone';
                ?>
                <td data-label="Telefon"><?php if ($phoneMode === 'webrtc'): ?>
                    <a class="pill ok" href="index.php?p=webrtc&amp;ext=<?= e($row['exten']) ?>"><?= e($phoneLabel) ?></a>
                <?php else: ?><span class="pill"><?= e($phoneLabel) ?></span><?php endif; ?></td>
                <?php
                $outboundEnabled = !array_key_exists('outbound_enabled', $row) || !empty($row['outbound_enabled']);
                $allowedRoutes = is_array($row['outbound_routes'] ?? null)
                    ? array_values(array_filter(array_map(
                        static fn($routeId) => $outboundRouteNames[(string) $routeId] ?? '',
                        $row['outbound_routes']
                    )))
                    : [];
                $outboundLabel = array_key_exists('outbound_routes', $row)
                    ? ($allowedRoutes ? implode(', ', $allowedRoutes) : 'Kapalı')
                    : ($outboundEnabled ? 'Tüm çıkışlar' : 'Kapalı');
                ?>
                <td class="col-outbound" data-label="Dış arama"><span class="pill <?= $outboundLabel !== 'Kapalı' ? 'ok' : 'bad' ?>" title="<?= e($outboundLabel) ?>"><?= e($outboundLabel) ?></span></td>
                <td class="right col-actions">
                    <?php $editableRow = $row; unset($editableRow['password']); ?>
                    <button class="btn sm" type="button" data-edit-ext='<?= e(json_encode($editableRow, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-ext="<?= e($row['id'] ?? ext_id((string) $row['dept'], (string) $row['exten'])) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $showDept ? 11 : 10 ?>" class="muted">Bu kapsamda abone yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
<script>
window.ASTERA = window.ASTERA || {};
window.ASTERA.extensions = <?= json_encode($clientRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.ASTERA.outboundRoutes = <?= json_encode($clientOutboundRoutes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.ASTERA.priSpans = <?= json_encode($clientPriSpans, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.ASTERA.extensionDefaultFallbacks = <?= json_encode($defaultFallbacks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.ASTERA.extensionLicense = <?= json_encode($extensionLicense, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
document.addEventListener('DOMContentLoaded', () => Astera.pageExtensions());
</script>
