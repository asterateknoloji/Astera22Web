<?php
$rows = scoped('extensions');
$outboundRoutes = scoped('outbound');
$priSpans = scoped('pri_spans');
$clientRows = array_map(static function (array $row): array {
    unset($row['password'], $row['vm_pin']);
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
<article class="card flush">
    <table>
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAllExt" aria-label="Görünen abonelerin tümünü seç"></th>
                <?php if ($showDept): ?><th>Firma</th><?php endif; ?>
                <th>Dahili</th>
                <th>Ad</th>
                <th>Tür</th>
                <th>SIP kullanıcı</th>
                <th>Auth kullanıcı</th>
                <th>SIP şifre</th>
                <th>Telefon</th>
                <th>CRM</th>
                <th>Dış arama</th>
                <th>Kayıt</th>
                <th>Durum</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php $technology = strtolower((string) ($row['technology'] ?? 'pjsip')); ?>
            <tr data-ext-row data-search="<?= e(strtolower(trim(
                (string) ($row['exten'] ?? '') . ' '
                . (string) ($row['sipuser'] ?? '') . ' '
                . (string) ($row['name'] ?? '')
            ))) ?>">
                <td><input type="checkbox" class="ext-select" value="<?= e($row['id'] ?? ext_id((string) $row['dept'], (string) $row['exten'])) ?>" aria-label="<?= e((string) $row['exten']) ?> abonesini seç"></td>
                <?php if ($showDept): ?><td><?= e(dept_name((string) ($row['dept'] ?? ''))) ?></td><?php endif; ?>
                <td><code><?= e($row['exten']) ?></code></td>
                <td><?= e($row['name'] ?? '') ?></td>
                <td><span class="pill <?= $technology !== 'pjsip' ? 'ok' : '' ?>"><?= e([
                    'dahdi' => 'DAHDI',
                    'custom' => 'CUSTOM',
                    'pjsip' => 'PJSIP',
                ][$technology] ?? 'PJSIP') ?></span></td>
                <td><code><?= $technology === 'dahdi'
                    ? 'g' . (int) ($row['dahdi_group'] ?? 0) . '/' . e((string) ($row['dahdi_dial'] ?? $row['exten']))
                    : ($technology === 'custom'
                        ? e((string) ($row['custom_dial'] ?? ''))
                        : e($row['sipuser'] ?? $row['exten'])) ?></code></td>
                <td><code><?= $technology !== 'pjsip' ? '—' : e($row['authuser'] ?? $row['sipuser'] ?? $row['exten']) ?></code></td>
                <td>
                    <?php if ($technology !== 'pjsip'): ?>
                        —
                    <?php else: ?>
                    <div class="extension-secret" data-extension-secret="<?= e($row['id'] ?? ext_id((string) $row['dept'], (string) $row['exten'])) ?>">
                        <input type="password" readonly value="" placeholder="••••••••" aria-label="<?= e((string) $row['exten']) ?> SIP şifresi">
                        <button class="btn sm icon-btn" type="button" data-secret-toggle aria-label="SIP şifresini göster" title="Şifreyi göster" aria-pressed="false">👁</button>
                        <button class="btn sm" type="button" data-secret-copy>Şifreyi kopyala</button>
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
                <td><?php if ($phoneMode === 'webrtc'): ?>
                    <a class="pill ok" href="index.php?p=webrtc&amp;ext=<?= e($row['exten']) ?>"><?= e($phoneLabel) ?></a>
                <?php else: ?><span class="pill"><?= e($phoneLabel) ?></span><?php endif; ?></td>
                <?php $crmTrigger = (string) ($row['crm_trigger'] ?? 'disabled'); ?>
                <td><span class="pill <?= $crmTrigger === 'enabled' ? 'ok' : 'dim' ?>">
                    <?= e([
                        'enabled' => 'açık',
                        'disabled' => 'kapalı',
                        'inherit' => 'firma',
                    ][$crmTrigger] ?? 'firma') ?>
                </span></td>
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
                <td><span class="pill <?= $outboundLabel !== 'Kapalı' ? 'ok' : 'bad' ?>"><?= e($outboundLabel) ?></span></td>
                <?php
                $extensionDept = $departmentIndex[(string) ($row['dept'] ?? '')] ?? [];
                $recordEnabled = array_key_exists('record', $row)
                    ? !empty($row['record'])
                    : !empty($extensionDept['record']);
                ?>
                <td><?= $recordEnabled ? strtoupper(e((string) ($row['record_format'] ?? 'wav'))) : 'Kapalı' ?></td>
                <td><?php if ($technology !== 'pjsip'): ?>
                    <span class="pill dim"><?= $technology === 'dahdi' ? 'PRI' : 'CUSTOM' ?></span>
                <?php else: ?>
                    <span class="pill dim ext-state" data-sip="<?= e($row['sipuser'] ?? $row['exten']) ?>">—</span>
                <?php endif; ?></td>
                <td class="right">
                    <?php $editableRow = $row; unset($editableRow['password']); ?>
                    <button class="btn sm" type="button" data-edit-ext='<?= e(json_encode($editableRow, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-ext="<?= e($row['id'] ?? ext_id((string) $row['dept'], (string) $row['exten'])) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $showDept ? 14 : 13 ?>" class="muted">Bu kapsamda abone yok.</td></tr>
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
document.addEventListener('DOMContentLoaded', () => Astera.pageExtensions());
</script>
