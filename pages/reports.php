<?php
require_once __DIR__ . '/../includes/report_period.php';
$filters = report_period_filters($_GET);
$startedAt = microtime(true);
$report = pbx_trunk_report(current_dept_id(), $filters);
$reportDuration = microtime(true) - $startedAt;
$totalsFor = static function (array $rows): array {
    $totals = [
        'total_calls' => 0,
        'billsec' => 0,
        'answered' => 0,
        'no_answer' => 0,
        'failed' => 0,
        'busy' => 0,
    ];
    foreach ($rows as $row) {
        foreach (array_keys($totals) as $field) {
            $totals[$field] += (int) ($row[$field] ?? 0);
        }
    }
    return $totals;
};
$renderReport = static function (string $title, array $rows) use ($totalsFor): void {
    $totals = $totalsFor($rows);
    ?>
    <article class="card flush">
        <header class="card-head">
            <div>
                <h2><?= e($title) ?></h2>
                <p class="muted">Konuşma süresi cevaplanan çağrıların billsec değerinden hesaplanır.</p>
            </div>
        </header>
        <table>
            <thead>
                <tr>
                    <th>Hat</th>
                    <th>Toplam çağrı</th>
                    <th>Dakika</th>
                    <th>Answered</th>
                    <th>No Answer</th>
                    <th>Failed</th>
                    <th>Busy</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><strong><?= e($row['trunk_name']) ?></strong></td>
                    <td><?= (int) $row['total_calls'] ?></td>
                    <td><?= e(number_format((int) $row['billsec'] / 60, 1, ',', '.')) ?></td>
                    <td><?= (int) $row['answered'] ?></td>
                    <td><?= (int) $row['no_answer'] ?></td>
                    <td><?= (int) $row['failed'] ?></td>
                    <td><?= (int) $row['busy'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="muted">Bu firma için tanımlı trunk bulunmuyor.</td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>TOPLAM</th>
                    <th><?= $totals['total_calls'] ?></th>
                    <th><?= e(number_format($totals['billsec'] / 60, 1, ',', '.')) ?></th>
                    <th><?= $totals['answered'] ?></th>
                    <th><?= $totals['no_answer'] ?></th>
                    <th><?= $totals['failed'] ?></th>
                    <th><?= $totals['busy'] ?></th>
                </tr>
            </tfoot>
        </table>
    </article>
    <?php
};
?>
<div class="toolbar">
    <p class="muted">
        Trunk çağrıları PostgreSQL CDR kayıtlarından hesaplanır.
        Hazırlama: <?= e(number_format($reportDuration, 3, ',', '.')) ?> sn.
    </p>
    <div class="toolbar-actions">
        <a class="btn sm" href="trunk_report_export.php?<?= e(http_build_query(array_merge($filters, ['format' => 'excel']))) ?>">Excel'e aktar</a>
        <a class="btn sm" href="trunk_report_export.php?<?= e(http_build_query(array_merge($filters, ['format' => 'pdf']))) ?>">PDF'e aktar</a>
    </div>
</div>

<article class="card">
    <form method="get" class="cdr-filters">
        <input type="hidden" name="p" value="reports">
        <?php render_report_period_filter($filters); ?>
        <div class="modal-actions">
            <a class="btn" href="index.php?p=reports">Temizle</a>
            <button class="btn primary" type="submit">Raporla</button>
        </div>
    </form>
</article>

<?php $renderReport('1. Giden aramalar (Outbound)', $report['outbound']); ?>
<?php $renderReport('2. Gelen aramalar (Inbound)', $report['inbound']); ?>
