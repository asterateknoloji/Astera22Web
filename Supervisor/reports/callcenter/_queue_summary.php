<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

function queue_summary_date(string $name, string $fallback): string
{
    $value = trim((string) ($_GET[$name] ?? $fallback));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
}

$today = date('Y-m-d');
$from = queue_summary_date('from', $today);
$to = queue_summary_date('to', $from);
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

$slaSeconds = (int) ($_GET['sla'] ?? 20);
$slaSeconds = max(1, min(3600, $slaSeconds));
$hideEmpty = $_GET ? supervisor_bool($_GET['hide_empty'] ?? '0') : true;
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

$params = ['from' => $from, 'to' => $to, 'sla' => $slaSeconds];
$where = [
    'q.time>=:from::date',
    "q.time<(:to::date+interval '1 day')",
    "q.callid NOT IN ('','NONE','MANAGER','REALTIME')",
    "q.event IN ('ABANDON','COMPLETEAGENT','COMPLETECALLER','RINGNOANSWER')",
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

$numeric = static fn(string $field): string =>
    "CASE WHEN {$field}~'^[0-9]+([.][0-9]+)?$' THEN {$field}::numeric ELSE 0 END";
$completeWait = $numeric('t.data1');
$abandonWait = $numeric('t.data3');
$talk = $numeric('t.data2');

$sql = "WITH scoped AS ("
    . 'SELECT q.id,q.time,q.callid,q.queuename,q.event,q.data1,q.data2,q.data3 '
    . 'FROM queue_log q WHERE ' . implode(' AND ', $where)
    . "), terminal AS (SELECT DISTINCT ON (queuename,callid) id,time,callid,queuename,event,data1,data2,data3 "
    . "FROM scoped WHERE event IN ('ABANDON','COMPLETEAGENT','COMPLETECALLER') "
    . "ORDER BY queuename,callid,time DESC,id DESC), calls AS (SELECT t.*,CASE "
    . "WHEN t.event='ABANDON' THEN {$abandonWait} ELSE {$completeWait} END AS wait_seconds,"
    . "CASE WHEN t.event IN ('COMPLETEAGENT','COMPLETECALLER') THEN {$talk} ELSE 0 END AS talk_seconds "
    . 'FROM terminal t), call_stats AS (SELECT queuename,count(*)::bigint AS total_calls,'
    . "count(*) FILTER (WHERE event IN ('COMPLETEAGENT','COMPLETECALLER'))::bigint AS answered_calls,"
    . "count(*) FILTER (WHERE event='ABANDON')::bigint AS abandoned_calls,"
    . "count(*) FILTER (WHERE event='COMPLETEAGENT')::bigint AS agent_ended,"
    . "count(*) FILTER (WHERE event='COMPLETECALLER')::bigint AS caller_ended,"
    . 'COALESCE(round(avg(wait_seconds)),0)::bigint AS avg_wait_seconds,'
    . 'COALESCE(max(wait_seconds),0)::bigint AS max_wait_seconds,'
    . 'COALESCE(sum(talk_seconds),0)::bigint AS total_talk_seconds,'
    . "COALESCE(round(avg(talk_seconds) FILTER (WHERE event IN ('COMPLETEAGENT','COMPLETECALLER'))),0)::bigint AS avg_talk_seconds,"
    . "COALESCE(round(avg(wait_seconds+talk_seconds) FILTER (WHERE event IN ('COMPLETEAGENT','COMPLETECALLER'))),0)::bigint AS avg_handle_seconds,"
    . "count(*) FILTER (WHERE event IN ('COMPLETEAGENT','COMPLETECALLER') AND wait_seconds<=:sla)::bigint AS sla_answered "
    . 'FROM calls GROUP BY queuename), ring_stats AS (SELECT queuename,count(*)::bigint AS ring_no_answer_attempts '
    . "FROM scoped WHERE event='RINGNOANSWER' GROUP BY queuename) "
    . 'SELECT COALESCE(c.queuename,r.queuename) AS queuename,'
    . 'COALESCE(c.total_calls,0)::bigint AS total_calls,COALESCE(c.answered_calls,0)::bigint AS answered_calls,'
    . 'COALESCE(c.abandoned_calls,0)::bigint AS abandoned_calls,COALESCE(r.ring_no_answer_attempts,0)::bigint AS ring_no_answer_attempts,'
    . 'COALESCE(c.agent_ended,0)::bigint AS agent_ended,COALESCE(c.caller_ended,0)::bigint AS caller_ended,'
    . 'COALESCE(c.avg_wait_seconds,0)::bigint AS avg_wait_seconds,COALESCE(c.max_wait_seconds,0)::bigint AS max_wait_seconds,'
    . 'COALESCE(c.total_talk_seconds,0)::bigint AS total_talk_seconds,COALESCE(c.avg_talk_seconds,0)::bigint AS avg_talk_seconds,'
    . 'COALESCE(c.avg_handle_seconds,0)::bigint AS avg_handle_seconds,COALESCE(c.sla_answered,0)::bigint AS sla_answered '
    . 'FROM call_stats c FULL JOIN ring_stats r USING (queuename) ORDER BY queuename';

$queryRows = callcenter_rows($sql, $params);
$rowsByQueue = [];
foreach ($queryRows as $row) {
    $rowsByQueue[(string) $row['queuename']] = $row;
}

$visibleQueueIds = $selectedQueues ?: array_keys($queueNames);
foreach (array_keys($rowsByQueue) as $queueId) {
    if (!in_array($queueId, $visibleQueueIds, true)) {
        $visibleQueueIds[] = $queueId;
    }
}
sort($visibleQueueIds, SORT_NATURAL);

$rows = [];
foreach ($visibleQueueIds as $queueId) {
    $row = $rowsByQueue[$queueId] ?? [
        'queuename' => $queueId, 'total_calls' => 0, 'answered_calls' => 0, 'abandoned_calls' => 0,
        'ring_no_answer_attempts' => 0, 'agent_ended' => 0, 'caller_ended' => 0,
        'avg_wait_seconds' => 0, 'max_wait_seconds' => 0, 'total_talk_seconds' => 0,
        'avg_talk_seconds' => 0, 'avg_handle_seconds' => 0, 'sla_answered' => 0,
    ];
    if ($hideEmpty && (int) $row['total_calls'] === 0 && (int) $row['ring_no_answer_attempts'] === 0) {
        continue;
    }
    $row['queue_name'] = $queueNames[$queueId] ?? $queueId;
    $row['sla_rate'] = (int) $row['answered_calls'] > 0
        ? round((int) $row['sla_answered'] * 100 / (int) $row['answered_calls'], 1) : 0.0;
    $rows[] = $row;
}

$totalCalls = array_sum(array_map('intval', array_column($rows, 'total_calls')));
$answeredCalls = array_sum(array_map('intval', array_column($rows, 'answered_calls')));
$abandonedCalls = array_sum(array_map('intval', array_column($rows, 'abandoned_calls')));
$ringAttempts = array_sum(array_map('intval', array_column($rows, 'ring_no_answer_attempts')));
$slaAnswered = array_sum(array_map('intval', array_column($rows, 'sla_answered')));
$slaRate = $answeredCalls > 0 ? round($slaAnswered * 100 / $answeredCalls, 1) : 0.0;

supervisor_page_start('Kuyruk Özet');
?>
<form class="filter-bar operational-report-filter" method="get">
    <label class="filter-group"><span class="filter-label">Başlangıç</span><input type="date" name="from" value="<?= e($from) ?>" required></label>
    <label class="filter-group"><span class="filter-label">Bitiş</span><input type="date" name="to" value="<?= e($to) ?>" required></label>
    <label class="filter-group operational-multi"><span class="filter-label">Kuyruklar · Çoklu seçim</span><select name="queues[]" multiple size="4">
        <?php foreach ($queues as $queue): $queueId = (string) ($queue['id'] ?? ''); ?>
            <option value="<?= e($queueId) ?>"<?= in_array($queueId, $selectedQueues, true) ? ' selected' : '' ?>><?= e($queueId . ' · ' . (string) ($queue['name'] ?? $queueId)) ?></option>
        <?php endforeach; ?>
    </select></label>
    <label class="filter-group"><span class="filter-label">SLA Eşiği (sn)</span><input type="number" name="sla" min="1" max="3600" value="<?= $slaSeconds ?>" required></label>
    <input type="hidden" name="hide_empty" value="0">
    <label class="choice-row"><input type="checkbox" name="hide_empty" value="1"<?= $hideEmpty ? ' checked' : '' ?>><span><strong>Boş kuyrukları gizle</strong></span></label>
    <button class="btn btn-primary" type="submit">Raporla</button>
    <a class="btn btn-outline" href="kuyrukozet.php">Temizle</a>
</form>

<section class="stats-grid operational-report-stats">
    <article class="stat-card"><div class="stat-label">Toplam Benzersiz Çağrı</div><div class="stat-value"><?= $totalCalls ?></div></article>
    <article class="stat-card"><div class="stat-label">Cevaplanan</div><div class="stat-value"><?= $answeredCalls ?></div></article>
    <article class="stat-card"><div class="stat-label">Kaçan</div><div class="stat-value"><?= $abandonedCalls ?></div></article>
    <article class="stat-card"><div class="stat-label">Ring-No-Answer Denemesi</div><div class="stat-value"><?= $ringAttempts ?></div></article>
    <article class="stat-card"><div class="stat-label">SLA İçinde / Oran</div><div class="stat-value operational-duration"><?= $slaAnswered ?> · <?= e(number_format($slaRate, 1, ',', '.')) ?>%</div></article>
</section>

<div class="operational-report-actions">
    <span>Excel çıktısı ve yazdırma yalnız bu sayfadaki filtrelenmiş/görünür satırları içerir.</span>
    <button class="btn btn-success" type="button" id="summary-excel">Excel'e Aktar</button>
    <button class="btn btn-outline" type="button" onclick="window.print()">Yazdır</button>
</div>
<div class="operational-report-search"><label for="summary-search">Bu sayfadaki sonuçlarda ara</label><input id="summary-search" type="search" placeholder="Kuyruk veya metrik ara…"></div>

<div class="table-container">
    <div class="table-header">Kuyruk Özeti · SLA: <?= $slaSeconds ?> saniye</div>
    <table class="operational-report-table" id="summary-table"><thead><tr>
        <th>Kuyruk</th><th>Toplam Benzersiz</th><th>Cevaplanan</th><th>Kaçan</th><th>RNA Denemesi</th>
        <th>Agent Kapattı</th><th>Arayan Kapattı</th><th>Ort. Bekleme</th><th>Maks. Bekleme</th>
        <th>Toplam Konuşma</th><th>Ort. Konuşma</th><th>Ort. İşlem</th><th>SLA İçinde</th><th>SLA Oranı</th>
    </tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><strong><?= e((string) $row['queuename']) ?></strong><small class="agent-detail-sub"><?= e((string) $row['queue_name']) ?></small></td>
            <td><?= (int) $row['total_calls'] ?></td><td><?= (int) $row['answered_calls'] ?></td><td><?= (int) $row['abandoned_calls'] ?></td>
            <td><?= (int) $row['ring_no_answer_attempts'] ?></td><td><?= (int) $row['agent_ended'] ?></td><td><?= (int) $row['caller_ended'] ?></td>
            <td><?= e(supervisor_duration($row['avg_wait_seconds'])) ?></td><td><?= e(supervisor_duration($row['max_wait_seconds'])) ?></td>
            <td><?= e(supervisor_duration($row['total_talk_seconds'])) ?></td><td><?= e(supervisor_duration($row['avg_talk_seconds'])) ?></td>
            <td><?= e(supervisor_duration($row['avg_handle_seconds'])) ?></td><td><?= (int) $row['sla_answered'] ?></td>
            <td><?= e(number_format((float) $row['sla_rate'], 1, ',', '.')) ?>%</td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="14" class="empty">Seçilen filtrelerde kuyruk verisi bulunamadı.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<script>
const summarySearch=document.getElementById('summary-search');
summarySearch?.addEventListener('input',event=>{
    const query=event.currentTarget.value.toLocaleLowerCase('tr-TR');
    document.querySelectorAll('#summary-table tbody tr').forEach(row=>{
        row.hidden=query!==''&&!row.textContent.toLocaleLowerCase('tr-TR').includes(query);
    });
});
document.getElementById('summary-excel')?.addEventListener('click',()=>{
    const table=document.getElementById('summary-table');
    const rows=[table.tHead.rows[0],...Array.from(table.tBodies[0].rows).filter(row=>!row.hidden&&!row.querySelector('.empty'))];
    const safe=value=>/^[=+\-@]/.test(value)?"'"+value:value;
    const csv=rows.map(row=>Array.from(row.cells).map(cell=>'"'+safe(cell.innerText.trim().replace(/\s+/g,' ')).replace(/"/g,'""')+'"').join(';')).join('\r\n');
    const link=document.createElement('a');
    link.href=URL.createObjectURL(new Blob(['\uFEFF',csv],{type:'text/csv;charset=utf-8'}));
    link.download='kuyruk_ozet_<?= e($from) ?>_<?= e($to) ?>.csv';
    link.click();
    setTimeout(()=>URL.revokeObjectURL(link.href),0);
});
</script>
<?php supervisor_page_end(); ?>
