<?php
$deptId = current_dept_id();
$exts = scoped('extensions');
$trunks = scoped('trunks');
$queues = scoped('queues');
$groups = scoped('ringgroups');
$firms = $deptId ? 1 : count(departments());
$dailyCalls = pbx_daily_call_summary($deptId);
usort($exts, static function (array $left, array $right): int {
    return strnatcasecmp((string) ($left['exten'] ?? ''), (string) ($right['exten'] ?? ''));
});
?>
<?php if (!$deptId && is_super()): ?>
    <p class="banner">Üstteki listeden bir firma seçin; her firma kendi aboneleri, dış hattı ve raporlarıyla izole çalışır. Firmalar birbirini arayamaz.</p>
<?php endif; ?>
<div class="stats dashboard-stats" id="dashStats">
    <article class="stat">
        <small>Firma</small>
        <strong><?= $firms ?></strong>
        <span><?= $deptId ? e(dept_name($deptId)) : 'kayıtlı' ?></span>
    </article>
    <article class="stat">
        <small>Abone</small>
        <strong><?= count($exts) ?></strong>
        <span>dahili</span>
    </article>
    <article class="stat">
        <small>Kayıtlı SIP</small>
        <strong id="regCount">—</strong>
        <span>online SIP telefonu</span>
    </article>
    <article class="stat">
        <small>Aktif çağrı</small>
        <strong id="callCount">—</strong>
        <span>şu anda</span>
    </article>
    <article class="stat">
        <small>Dış hat / kuyruk</small>
        <strong><?= count($trunks) ?> / <?= count($queues) ?></strong>
        <span>ring grup <?= count($groups) ?></span>
    </article>
    <article class="stat">
        <small>IVR / akış</small>
        <strong><?= count(scoped('ivrs')) ?> / <?= count(scoped('flows')) ?></strong>
        <span>konferans <?= count(scoped('conferences')) ?></span>
    </article>
    <article class="stat">
        <small>Bugün gelen</small>
        <strong><?= (int) ($dailyCalls['incoming'] ?? 0) ?></strong>
        <span><?= (int) ($dailyCalls['incoming_answered'] ?? 0) ?> cevaplanan çağrı</span>
    </article>
    <article class="stat">
        <small>Bugün giden</small>
        <strong><?= (int) ($dailyCalls['outgoing'] ?? 0) ?></strong>
        <span><?= (int) ($dailyCalls['outgoing_answered'] ?? 0) ?> cevaplanan çağrı</span>
    </article>
</div>

<div class="grid-2">
    <article class="card">
        <h2>Kayıtlı SIP aboneleri</h2>
        <div id="regList" class="list">Santral durumu okunuyor…</div>
    </article>
    <article class="card">
        <h2>Aktif kanallar <span class="pill dim" id="chanCount">—</span></h2>
        <div id="chanList" class="list">—</div>
    </article>
</div>

<article class="card flush">
    <h2>Abone durumları</h2>
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
            <tr data-dashboard-extension data-exten="<?= e((string) $row['exten']) ?>"
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
</article>
<article class="card">
    <h2>Uptime</h2>
    <pre class="log" id="uptimeBox">yükleniyor…</pre>
</article>
<script>
document.addEventListener('DOMContentLoaded', () => Astera.pollDashboard());
</script>
