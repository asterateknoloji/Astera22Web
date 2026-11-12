<?php
$deptId = current_dept_id();
$exts = scoped('extensions');
$trunks = scoped('trunks');
$queues = scoped('queues');
$groups = scoped('ringgroups');
$firms = $deptId ? 1 : count(departments());
$dailyCalls = pbx_daily_call_summary($deptId);
$analytics = pbx_dashboard_analytics($deptId);
$trend = $analytics['trend'] ?? [];
$trendMax = max(1, ...array_map(
    static fn(array $day): int => max((int) $day['incoming'], (int) $day['outgoing']),
    $trend
));
$chartPoints = static function (array $rows, string $field, int $maximum): string {
    $lastIndex = max(1, count($rows) - 1);
    return implode(' ', array_map(
        static fn(array $row, int $index): string => number_format(25 + (($index / $lastIndex) * 650), 1, '.', '')
            . ',' . number_format(145 - (((int) ($row[$field] ?? 0) / $maximum) * 115), 1, '.', ''),
        $rows,
        array_keys($rows)
    ));
};
$outcomes = [
    'Answered' => (int) ($analytics['answered'] ?? 0),
    'No Answer' => (int) ($analytics['no_answer'] ?? 0),
    'Busy' => (int) ($analytics['busy'] ?? 0),
    'Failed' => (int) ($analytics['failed'] ?? 0),
];
$outcomeMax = max(1, ...array_values($outcomes));
$formatDuration = static function (int $seconds): string {
    return $seconds >= 3600
        ? gmdate('H\h i\m', $seconds)
        : gmdate('i\m s\s', $seconds);
};
usort($exts, static function (array $left, array $right): int {
    return strnatcasecmp((string) ($left['exten'] ?? ''), (string) ($right['exten'] ?? ''));
});

$systemResources = null;
$serviceStates = [];
if (is_super()) {
    $cpuCount = max(1, (int) preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo')));
    $loadAverage = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: null) : null;
    $memInfo = [];
    foreach ((array) @file('/proc/meminfo') as $memLine) {
        if (preg_match('/^(\w+):\s+(\d+)/', (string) $memLine, $memMatch)) {
            $memInfo[$memMatch[1]] = (float) $memMatch[2] * 1024;
        }
    }
    $diskTotal = @disk_total_space('/');
    $diskFree = @disk_free_space('/');
    $uptimeSeconds = (int) (float) explode(' ', (string) @file_get_contents('/proc/uptime'))[0];
    $formatBytes = static function (float $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        return number_format($bytes, $index >= 3 ? 1 : 0, ',', '.') . ' ' . $units[$index];
    };
    $memTotal = (float) ($memInfo['MemTotal'] ?? 0);
    $memUsed = $memTotal - (float) ($memInfo['MemAvailable'] ?? $memTotal);
    $systemResources = [
        'cpu' => $loadAverage !== null ? min(100, ($loadAverage[0] / $cpuCount) * 100) : null,
        'cpu_note' => $loadAverage !== null
            ? 'Yük ortalaması ' . number_format($loadAverage[0], 2, ',', '.') . ' · ' . $cpuCount . ' çekirdek'
            : 'Ölçülemedi',
        'ram' => $memTotal > 0 ? ($memUsed / $memTotal) * 100 : null,
        'ram_note' => $memTotal > 0 ? $formatBytes($memUsed) . ' / ' . $formatBytes($memTotal) : 'Ölçülemedi',
        'disk' => $diskTotal ? (($diskTotal - (float) $diskFree) / $diskTotal) * 100 : null,
        'disk_note' => $diskTotal ? $formatBytes($diskTotal - (float) $diskFree) . ' / ' . $formatBytes((float) $diskTotal) : 'Ölçülemedi',
        'uptime' => $uptimeSeconds > 0
            ? sprintf('%d gün %02d sa %02d dk', intdiv($uptimeSeconds, 86400), intdiv($uptimeSeconds % 86400, 3600), intdiv($uptimeSeconds % 3600, 60))
            : 'Ölçülemedi',
    ];
    $serviceLabels = ['asterisk' => 'Asterisk', 'postgresql' => 'PostgreSQL', 'nginx' => 'Nginx web sunucusu', 'fail2ban' => 'Fail2ban'];
    $serviceOutput = [];
    if (function_exists('exec')) {
        @exec('systemctl is-active ' . implode(' ', array_keys($serviceLabels)) . ' 2>/dev/null', $serviceOutput);
    }
    foreach (array_keys($serviceLabels) as $serviceIndex => $serviceName) {
        $serviceStates[] = [
            'label' => $serviceLabels[$serviceName],
            'state' => trim((string) ($serviceOutput[$serviceIndex] ?? '')),
        ];
    }
}
$meterClass = static fn(?float $value): string => $value === null ? '' : ($value >= 90 ? 'bad' : ($value >= 75 ? 'warn' : ''));
?>
<?php if (!$deptId && is_super()): ?>
    <p class="banner">Üstteki listeden bir firma seçin; her firma kendi aboneleri, dış hattı ve raporlarıyla izole çalışır. Firmalar birbirini arayamaz.</p>
<?php endif; ?>
<section class="dashboard-kpis" id="dashStats" aria-label="Bugünkü çağrı göstergeleri">
    <article class="dashboard-kpi incoming">
        <span class="dashboard-kpi-icon"><?= ui_icon('phone-incoming') ?></span>
        <div><small>Bugün gelen</small><strong><?= (int) ($dailyCalls['incoming'] ?? 0) ?></strong></div>
        <span><?= (int) ($dailyCalls['incoming_answered'] ?? 0) ?> cevaplanan</span>
    </article>
    <article class="dashboard-kpi outgoing">
        <span class="dashboard-kpi-icon"><?= ui_icon('phone-outgoing') ?></span>
        <div><small>Bugün giden</small><strong><?= (int) ($dailyCalls['outgoing'] ?? 0) ?></strong></div>
        <span><?= (int) ($dailyCalls['outgoing_answered'] ?? 0) ?> cevaplanan</span>
    </article>
    <article class="dashboard-kpi service">
        <span class="dashboard-kpi-icon"><?= ui_icon('circle-check') ?></span>
        <div><small>Cevaplanma</small><strong>%<?= e(number_format((float) ($analytics['answer_rate'] ?? 0), 1, ',', '.')) ?></strong></div>
        <span>bugünkü servis oranı</span>
    </article>
    <article class="dashboard-kpi duration">
        <span class="dashboard-kpi-icon"><?= ui_icon('clock') ?></span>
        <div><small>Ort. görüşme</small><strong><?= e($formatDuration((int) ($analytics['avg_billsec'] ?? 0))) ?></strong></div>
        <span>cevaplanan çağrılar</span>
    </article>
    <article class="dashboard-kpi live">
        <span class="dashboard-kpi-icon"><?= ui_icon('radio') ?></span>
        <div><small>Kayıtlı SIP</small><strong id="regCount">—</strong></div>
        <span><?= count($exts) ?> toplam abone</span>
    </article>
    <article class="dashboard-kpi active">
        <span class="dashboard-kpi-icon"><?= ui_icon('phone') ?></span>
        <div><small>Aktif çağrı</small><strong id="callCount">—</strong></div>
        <span>şu anda</span>
    </article>
</section>

<div class="dashboard-resource-strip">
    <span><strong><?= $firms ?></strong> firma</span>
    <span><strong><?= count($trunks) ?></strong> dış hat</span>
    <span><strong><?= count($queues) ?></strong> kuyruk</span>
    <span><strong><?= count($groups) ?></strong> ring grup</span>
    <span><strong><?= count(scoped('ivrs')) ?></strong> IVR</span>
    <span><strong><?= count(scoped('flows')) ?></strong> akış</span>
    <span><strong><?= count(scoped('conferences')) ?></strong> konferans</span>
</div>

<section class="dashboard-analytics-grid">
    <article class="card dashboard-chart-card">
        <header>
            <div><small>SON 7 GÜN</small><h2>Gelen / giden çağrı trendi</h2></div>
            <div class="dashboard-legend"><span class="incoming">Gelen</span><span class="outgoing">Giden</span></div>
        </header>
        <svg class="dashboard-line-chart" viewBox="0 0 700 180" role="img" aria-label="Son 7 günlük çağrı trendi">
            <line x1="25" y1="30" x2="675" y2="30"></line>
            <line x1="25" y1="87" x2="675" y2="87"></line>
            <line x1="25" y1="145" x2="675" y2="145"></line>
            <polyline class="incoming" points="<?= e($chartPoints($trend, 'incoming', $trendMax)) ?>"></polyline>
            <polyline class="outgoing" points="<?= e($chartPoints($trend, 'outgoing', $trendMax)) ?>"></polyline>
            <?php foreach ($trend as $index => $day): ?>
                <?php $x = 25 + (($index / max(1, count($trend) - 1)) * 650); ?>
                <text x="<?= e(number_format($x, 1, '.', '')) ?>" y="171" text-anchor="middle"><?= e($day['label']) ?></text>
            <?php endforeach; ?>
        </svg>
    </article>

    <article class="card dashboard-chart-card">
        <header>
            <div><small>BUGÜN</small><h2>Çağrı sonuçları</h2></div>
            <div class="dashboard-rate-ring" style="--rate: <?= e((string) ($analytics['answer_rate'] ?? 0)) ?>">
                <strong>%<?= e(number_format((float) ($analytics['answer_rate'] ?? 0), 1, ',', '.')) ?></strong>
            </div>
        </header>
        <div class="dashboard-outcomes">
            <?php foreach ($outcomes as $label => $value): ?>
                <div class="dashboard-outcome <?= e(strtolower(str_replace(' ', '-', $label))) ?>">
                    <div><span><?= e(['Answered' => 'Cevaplanan', 'No Answer' => 'Cevapsız', 'Busy' => 'Meşgul', 'Failed' => 'Başarısız'][$label] ?? $label) ?></span><strong><?= $value ?></strong></div>
                    <i style="--width: <?= e(number_format(($value / $outcomeMax) * 100, 1, '.', '')) ?>%"></i>
                </div>
            <?php endforeach; ?>
        </div>
    </article>
</section>

<section class="dashboard-live-grid">
    <article class="card dashboard-live-card">
        <h2>Aktif kanallar <span class="pill dim" id="chanCount">—</span></h2>
        <div id="chanList" class="list dashboard-scroll-list">—</div>
    </article>
    <article class="card dashboard-live-card dashboard-uptime">
        <h2>Sistem çalışma süresi</h2>
        <pre class="log" id="uptimeBox">yükleniyor…</pre>
    </article>
</section>

<?php if ($systemResources !== null): ?>
<section class="dashboard-system-grid">
    <article class="card">
        <span class="dashboard-section-kicker">SUNUCU</span>
        <h2>Sistem kaynakları</h2>
        <?php foreach ([
            ['cpu', 'cpu', 'İşlemci yükü'],
            ['ram', 'memory-stick', 'Bellek'],
            ['disk', 'hard-drive', 'Sistem diski'],
        ] as [$resourceKey, $resourceIcon, $resourceLabel]): ?>
            <?php $resourceValue = $systemResources[$resourceKey]; ?>
            <div class="resource-meter <?= $meterClass($resourceValue) ?>">
                <div class="resource-meter-head">
                    <span><?= ui_icon($resourceIcon) ?><?= e($resourceLabel) ?></span>
                    <strong><?= $resourceValue === null ? '—' : '%' . e(number_format($resourceValue, 0, ',', '.')) ?></strong>
                </div>
                <div class="resource-meter-bar"><i style="--value: <?= e(number_format((float) $resourceValue, 1, '.', '')) ?>%"></i></div>
                <small><?= e($systemResources[$resourceKey . '_note']) ?></small>
            </div>
        <?php endforeach; ?>
    </article>
    <article class="card">
        <span class="dashboard-section-kicker">SERVİSLER</span>
        <h2>Sunucu durumu</h2>
        <ul class="service-list">
            <li>
                <span><?= ui_icon('server') ?>Sunucu çalışma süresi</span>
                <strong><?= e($systemResources['uptime']) ?></strong>
            </li>
            <?php foreach ($serviceStates as $service): ?>
                <li>
                    <span><?= ui_icon('activity') ?><?= e($service['label']) ?></span>
                    <?php if ($service['state'] === 'active'): ?>
                        <span class="pill ok">Çalışıyor</span>
                    <?php elseif ($service['state'] === ''): ?>
                        <span class="pill dim">Bilinmiyor</span>
                    <?php elseif (in_array($service['state'], ['activating', 'reloading'], true)): ?>
                        <span class="pill warn">Başlatılıyor</span>
                    <?php else: ?>
                        <span class="pill bad"><?= e($service['state'] === 'inactive' ? 'Durmuş' : ucfirst($service['state'])) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </article>
</section>
<?php endif; ?>

<details class="card dashboard-extension-details">
    <summary>Abone durumları <span class="pill dim"><?= count($exts) ?> abone</span></summary>
    <div class="dashboard-extension-filters" id="dashboardExtensionFilters" data-active-filter="all">
        <div class="dashboard-extension-filter-buttons">
            <button class="btn sm active" type="button" data-extension-filter="all">Tümü <span><?= count($exts) ?></span></button>
            <button class="btn sm" type="button" data-extension-filter="registered">Kayıtlı <span id="dashboardRegisteredCount">—</span></button>
            <button class="btn sm" type="button" data-extension-filter="unregistered">Kayıtsız <span id="dashboardUnregisteredCount">—</span></button>
        </div>
        <input id="dashboardExtensionSearch" type="search" placeholder="Dahili veya ad ara">
    </div>
    <div class="dashboard-extension-table">
        <table>
            <thead><tr><th>Dahili</th><th>Ad</th><th>Bağlantı</th><th>Durum</th></tr></thead>
            <tbody id="dashboardExtensionBody">
            <?php foreach ($exts as $row): ?>
                <?php
                $technology = strtolower((string) ($row['technology'] ?? 'pjsip'));
                $sip = (string) ($row['sipuser'] ?? $row['exten']);
                $connection = match ($technology) {
                    'dahdi' => 'DAHDI/g' . (int) ($row['dahdi_group'] ?? 0)
                        . '/' . (string) ($row['dahdi_dial'] ?? $row['exten']),
                    'custom' => (string) ($row['custom_dial'] ?? ''),
                    default => $sip,
                };
                ?>
                <tr data-dashboard-extension data-registration="pending"
                    data-search="<?= e(strtolower(trim((string) $row['exten'] . ' ' . (string) ($row['name'] ?? '')))) ?>"
                    data-exten="<?= e((string) $row['exten']) ?>"
                    data-technology="<?= e($technology) ?>" data-sip="<?= e($sip) ?>">
                    <td><code><?= e($row['exten']) ?></code></td>
                    <td><?= e($row['name'] ?? '') ?></td>
                    <td><code><?= e($connection) ?></code></td>
                    <td>
                        <?php if ($technology === 'pjsip'): ?>
                            <span class="pill dim ext-state" data-sip="<?= e($sip) ?>">—</span>
                        <?php elseif ($technology === 'dahdi'): ?>
                            <span class="pill ok">DAHDI / PRI</span>
                        <?php else: ?>
                            <span class="pill">Custom kanal</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$exts): ?><tr><td colspan="4" class="muted">Abone yok.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</details>
<script>
document.addEventListener('DOMContentLoaded', () => Astera.pollDashboard());
</script>
