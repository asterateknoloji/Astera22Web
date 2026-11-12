<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

function queue_analysis_date(string $name, string $fallback): string
{
    $value = trim((string) ($_GET[$name] ?? $fallback));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
}

$today = date('Y-m-d');
$from = queue_analysis_date('from', $today);
$to = queue_analysis_date('to', $from);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$mode = ($_GET['mode'] ?? 'simple') === 'detailed' ? 'detailed' : 'simple';
$validationMessage = '';
$simpleThreshold = (int) ($_GET['threshold'] ?? 30);
if ($simpleThreshold < 1 || $simpleThreshold > 3600) {
    $simpleThreshold = 30;
    $validationMessage = 'Basit eşik 1 ile 3600 saniye arasında olmalıdır; varsayılan 30 saniye kullanıldı.';
}
$thresholds = [];
$defaults = [10, 20, 30, 40, 50, 60];
foreach ($defaults as $index => $default) {
    $thresholds[] = (int) ($_GET['thresholds'][$index] ?? $default);
}
if ($mode === 'detailed') {
    $validRange = count(array_filter($thresholds, static fn(int $value): bool => $value >= 1 && $value <= 3600)) === 6;
    $strictlyIncreasing = $validRange;
    for ($index = 1; $index < 6; $index++) {
        $strictlyIncreasing = $strictlyIncreasing && $thresholds[$index] > $thresholds[$index - 1];
    }
    if (!$strictlyIncreasing) {
        $thresholds = $defaults;
        $validationMessage = 'Altı detaylı eşik 1–3600 aralığında ve kesin olarak artan olmalıdır; varsayılan eşikler kullanıldı.';
    }
}

$requestedQueues = array_values(array_unique(array_filter(array_map(
    static fn(mixed $value): string => trim((string) $value),
    (array) ($_GET['queues'] ?? [])
))));
$selectedQueues = array_values(array_intersect($queueIds, $requestedQueues));
$queueNames = [];
foreach ($queues as $queue) {
    $queueId = (string) ($queue['id'] ?? '');
    if ($queueId !== '') {
        $queueNames[$queueId] = (string) ($queue['name'] ?? $queueId);
    }
}

$params = ['from' => $from, 'to' => $to];
$where = [
    'q.time>=:from::date',
    "q.time<(:to::date+interval '1 day')",
    "q.callid NOT IN ('','NONE','MANAGER','REALTIME')",
    "q.event IN ('ABANDON','COMPLETEAGENT','COMPLETECALLER')",
    supervisor_queue_sql('q.queuename'),
];
if ($selectedQueues) {
    $placeholders = [];
    foreach ($selectedQueues as $index => $queueId) {
        $name = 'queue_' . $index;
        $placeholders[] = ':' . $name;
        $params[$name] = $queueId;
    }
    $where[] = 'q.queuename IN (' . implode(',', $placeholders) . ')';
}

$activeThresholds = $mode === 'simple' ? [$simpleThreshold] : $thresholds;
foreach ($activeThresholds as $index => $threshold) {
    $params['threshold_' . $index] = $threshold;
}
$bucketSql = [];
if ($mode === 'simple') {
    $bucketSql[] = 'count(*) FILTER (WHERE wait_seconds<=:threshold_0)::bigint AS bucket_1';
    $bucketSql[] = 'count(*) FILTER (WHERE wait_seconds>:threshold_0)::bigint AS bucket_2';
    for ($index = 3; $index <= 7; $index++) {
        $bucketSql[] = '0::bigint AS bucket_' . $index;
    }
    $bucketLabels = ['≤ ' . $simpleThreshold . ' sn', '> ' . $simpleThreshold . ' sn'];
} else {
    $bucketSql[] = 'count(*) FILTER (WHERE wait_seconds<=:threshold_0)::bigint AS bucket_1';
    for ($index = 1; $index < 6; $index++) {
        $bucketSql[] = 'count(*) FILTER (WHERE wait_seconds>:threshold_' . ($index - 1)
            . ' AND wait_seconds<=:threshold_' . $index . ')::bigint AS bucket_' . ($index + 1);
    }
    $bucketSql[] = 'count(*) FILTER (WHERE wait_seconds>:threshold_5)::bigint AS bucket_7';
    $bucketLabels = ['0–' . $thresholds[0] . ' sn'];
    for ($index = 1; $index < 6; $index++) {
        $bucketLabels[] = ($thresholds[$index - 1] + 1) . '–' . $thresholds[$index] . ' sn';
    }
    $bucketLabels[] = '> ' . $thresholds[5] . ' sn';
}

$numeric = static fn(string $field): string =>
    "CASE WHEN {$field}~'^[0-9]+([.][0-9]+)?$' THEN {$field}::numeric ELSE 0 END";
$completeWait = $numeric('t.data1');
$abandonWait = $numeric('t.data3');
$talk = $numeric('t.data2');
$metrics = "count(*)::bigint AS total_calls,"
    . "count(*) FILTER (WHERE event IN ('COMPLETEAGENT','COMPLETECALLER'))::bigint AS answered_calls,"
    . "count(*) FILTER (WHERE event='ABANDON')::bigint AS abandoned_calls,"
    . 'COALESCE(round(avg(wait_seconds)),0)::bigint AS avg_wait_seconds,'
    . "COALESCE(round(avg(talk_seconds) FILTER (WHERE event IN ('COMPLETEAGENT','COMPLETECALLER'))),0)::bigint AS avg_talk_seconds,"
    . implode(',', $bucketSql);

$sql = "WITH scoped AS (SELECT q.id,q.time,q.callid,q.queuename,q.event,q.data1,q.data2,q.data3 "
    . 'FROM queue_log q WHERE ' . implode(' AND ', $where)
    . "), terminal AS (SELECT DISTINCT ON (queuename,callid) id,time,callid,queuename,event,data1,data2,data3 "
    . 'FROM scoped ORDER BY queuename,callid,time DESC,id DESC), calls AS (SELECT t.*,'
    . "EXTRACT(HOUR FROM t.time)::integer AS call_hour,CASE WHEN t.event='ABANDON' THEN {$abandonWait} "
    . "ELSE {$completeWait} END AS wait_seconds,CASE WHEN t.event IN ('COMPLETEAGENT','COMPLETECALLER') "
    . "THEN {$talk} ELSE 0 END AS talk_seconds FROM terminal t), aggregates AS ("
    . "SELECT 'hour'::text AS dimension,call_hour::text AS dimension_key,{$metrics} FROM calls GROUP BY call_hour "
    . "UNION ALL SELECT 'queue'::text,queuename,{$metrics} FROM calls GROUP BY queuename) "
    . 'SELECT * FROM aggregates ORDER BY dimension,dimension_key';
$resultRows = callcenter_rows($sql, $params);

$hourRows = [];
$queueRows = [];
foreach ($resultRows as $row) {
    if ($row['dimension'] === 'hour') {
        $hourRows[(int) $row['dimension_key']] = $row;
    } else {
        $queueRows[(string) $row['dimension_key']] = $row;
    }
}
$emptyRow = [
    'total_calls' => 0, 'answered_calls' => 0, 'abandoned_calls' => 0,
    'avg_wait_seconds' => 0, 'avg_talk_seconds' => 0,
    'bucket_1' => 0, 'bucket_2' => 0, 'bucket_3' => 0, 'bucket_4' => 0,
    'bucket_5' => 0, 'bucket_6' => 0, 'bucket_7' => 0,
];
for ($hour = 0; $hour < 24; $hour++) {
    $hourRows[$hour] = ($hourRows[$hour] ?? $emptyRow) + ['dimension_key' => (string) $hour];
}
ksort($hourRows, SORT_NUMERIC);
uksort($queueRows, 'strnatcasecmp');

$totalCalls = array_sum(array_map('intval', array_column($hourRows, 'total_calls')));
$answeredCalls = array_sum(array_map('intval', array_column($hourRows, 'answered_calls')));
$abandonedCalls = array_sum(array_map('intval', array_column($hourRows, 'abandoned_calls')));
$answerRate = $totalCalls > 0 ? round($answeredCalls * 100 / $totalCalls, 1) : 0.0;

supervisor_page_start('Kuyruk Analiz');
if ($validationMessage !== '') {
    supervisor_notice($validationMessage, true);
}
?>
<form class="filter-bar operational-report-filter" method="get" id="analysis-filter">
    <label class="filter-group"><span class="filter-label">Başlangıç</span><input type="date" name="from" value="<?= e($from) ?>" required></label>
    <label class="filter-group"><span class="filter-label">Bitiş</span><input type="date" name="to" value="<?= e($to) ?>" required></label>
    <label class="filter-group operational-multi"><span class="filter-label">Kuyruklar · Çoklu seçim</span><select name="queues[]" multiple size="4">
        <?php foreach ($queues as $queue): $queueId = (string) ($queue['id'] ?? ''); ?>
            <option value="<?= e($queueId) ?>"<?= in_array($queueId, $selectedQueues, true) ? ' selected' : '' ?>><?= e($queueId . ' · ' . (string) ($queue['name'] ?? $queueId)) ?></option>
        <?php endforeach; ?>
    </select></label>
    <label class="filter-group"><span class="filter-label">Analiz Modu</span><select name="mode" id="analysis-mode">
        <option value="simple"<?= $mode === 'simple' ? ' selected' : '' ?>>Basit eşik</option>
        <option value="detailed"<?= $mode === 'detailed' ? ' selected' : '' ?>>6 sıralı eşik</option>
    </select></label>
    <label class="filter-group simple-threshold"><span class="filter-label">Basit Eşik (sn)</span><input type="number" name="threshold" min="1" max="3600" value="<?= $simpleThreshold ?>" required></label>
    <?php foreach ($thresholds as $index => $threshold): ?>
        <label class="filter-group detailed-threshold"><span class="filter-label"><?= $index + 1 ?>. Eşik (sn)</span><input type="number" name="thresholds[]" min="1" max="3600" value="<?= $threshold ?>" required></label>
    <?php endforeach; ?>
    <button class="btn btn-primary" type="submit">Analiz Et</button>
    <a class="btn btn-outline" href="kuyruk_analiz.php">Temizle</a>
</form>

<section class="stats-grid operational-report-stats">
    <article class="stat-card"><div class="stat-label">Toplam Benzersiz Çağrı</div><div class="stat-value"><?= $totalCalls ?></div></article>
    <article class="stat-card"><div class="stat-label">Cevaplanan</div><div class="stat-value"><?= $answeredCalls ?></div></article>
    <article class="stat-card"><div class="stat-label">Kaçan</div><div class="stat-value"><?= $abandonedCalls ?></div></article>
    <article class="stat-card"><div class="stat-label">Cevap Oranı</div><div class="stat-value"><?= e(number_format($answerRate, 1, ',', '.')) ?>%</div></article>
</section>

<div class="operational-report-actions">
    <span>Excel çıktısı ve yazdırma yalnız bu sayfadaki filtrelenmiş/görünür satırları içerir.</span>
    <button class="btn btn-success" type="button" id="analysis-excel">Excel'e Aktar</button>
    <button class="btn btn-outline" type="button" onclick="window.print()">Yazdır</button>
</div>
<div class="operational-report-search"><label for="analysis-search">Bu sayfadaki sonuçlarda ara</label><input id="analysis-search" type="search" placeholder="Her iki tabloda ara…"></div>

<div class="table-container">
    <div class="table-header">Saat Bazında Analiz · 00:00–23:00</div>
    <table class="operational-report-table analysis-export-table" id="hour-analysis-table"><thead><tr>
        <th>Saat</th><th>Toplam</th><th>Cevaplanan</th><th>Kaçan</th><th>Ort. Bekleme</th><th>Ort. Konuşma</th>
        <?php foreach ($bucketLabels as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
    </tr></thead><tbody>
    <?php foreach ($hourRows as $hour => $row): ?>
        <tr><td><strong><?= sprintf('%02d:00', $hour) ?></strong></td><td><?= (int) $row['total_calls'] ?></td>
            <td><?= (int) $row['answered_calls'] ?></td><td><?= (int) $row['abandoned_calls'] ?></td>
            <td><?= e(supervisor_duration($row['avg_wait_seconds'])) ?></td><td><?= e(supervisor_duration($row['avg_talk_seconds'])) ?></td>
            <?php foreach (array_keys($bucketLabels) as $index): ?><td><?= (int) $row['bucket_' . ($index + 1)] ?></td><?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>

<div class="table-container">
    <div class="table-header">Kuyruk Bazında Analiz</div>
    <table class="operational-report-table analysis-export-table" id="queue-analysis-table"><thead><tr>
        <th>Kuyruk</th><th>Toplam</th><th>Cevaplanan</th><th>Kaçan</th><th>Ort. Bekleme</th><th>Ort. Konuşma</th>
        <?php foreach ($bucketLabels as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
    </tr></thead><tbody>
    <?php foreach ($queueRows as $queueId => $row): ?>
        <tr><td><strong><?= e($queueId) ?></strong><small class="agent-detail-sub"><?= e($queueNames[$queueId] ?? $queueId) ?></small></td>
            <td><?= (int) $row['total_calls'] ?></td><td><?= (int) $row['answered_calls'] ?></td><td><?= (int) $row['abandoned_calls'] ?></td>
            <td><?= e(supervisor_duration($row['avg_wait_seconds'])) ?></td><td><?= e(supervisor_duration($row['avg_talk_seconds'])) ?></td>
            <?php foreach (array_keys($bucketLabels) as $index): ?><td><?= (int) $row['bucket_' . ($index + 1)] ?></td><?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$queueRows): ?><tr><td colspan="<?= 6 + count($bucketLabels) ?>" class="empty">Seçilen filtrelerde çağrı bulunamadı.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<script>
const modeSelect=document.getElementById('analysis-mode');
function toggleThresholds(){
    const detailed=modeSelect.value==='detailed';
    document.querySelectorAll('.simple-threshold').forEach(field=>field.hidden=detailed);
    document.querySelectorAll('.detailed-threshold').forEach(field=>field.hidden=!detailed);
}
modeSelect?.addEventListener('change',toggleThresholds);
toggleThresholds();
document.getElementById('analysis-search')?.addEventListener('input',event=>{
    const query=event.currentTarget.value.toLocaleLowerCase('tr-TR');
    document.querySelectorAll('.analysis-export-table tbody tr').forEach(row=>{
        row.hidden=query!==''&&!row.textContent.toLocaleLowerCase('tr-TR').includes(query);
    });
});
document.getElementById('analysis-excel')?.addEventListener('click',()=>{
    const safe=value=>/^[=+\-@]/.test(value)?"'"+value:value;
    const quote=value=>'"'+safe(value).replace(/"/g,'""')+'"';
    const output=[];
    document.querySelectorAll('.analysis-export-table').forEach(table=>{
        output.push(quote(table.previousElementSibling.textContent.trim()));
        const rows=[table.tHead.rows[0],...Array.from(table.tBodies[0].rows).filter(row=>!row.hidden&&!row.querySelector('.empty'))];
        rows.forEach(row=>output.push(Array.from(row.cells).map(cell=>quote(cell.innerText.trim().replace(/\s+/g,' '))).join(';')));
        output.push('');
    });
    const link=document.createElement('a');
    link.href=URL.createObjectURL(new Blob(['\uFEFF',output.join('\r\n')],{type:'text/csv;charset=utf-8'}));
    link.download='kuyruk_analiz_<?= e($from) ?>_<?= e($to) ?>.csv';
    link.click();
    setTimeout(()=>URL.revokeObjectURL(link.href),0);
});
</script>
<?php supervisor_page_end(); ?>
