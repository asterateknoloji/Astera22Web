<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$from = supervisor_date('from');
$to = supervisor_date('to', $from);
if ($to < $from) {
    $to = $from;
}
$direction = in_array($_GET['direction'] ?? '', ['incoming', 'outgoing'], true)
    ? (string) $_GET['direction'] : '';
$status = in_array($_GET['status'] ?? '', ['CEVAPLANDI', 'KACAN', 'CEVAPSIZ'], true)
    ? (string) $_GET['status'] : '';

$requestedQueues = array_values(array_unique(array_filter(array_map(
    static fn(mixed $value): string => trim((string) $value),
    (array) ($_GET['queues'] ?? [])
))));
$selectedQueues = array_values(array_intersect($queueIds, $requestedQueues));

$agents = callcenter_rows(
    "SELECT a.agent_id,a.display_name,a.extension FROM callcenter_agents a "
    . "WHERE a.dept_id=:dept AND a.status='active' AND " . supervisor_agent_sql('a')
    . ' ORDER BY a.display_name,a.extension',
    ['dept' => $deptId]
);
$allowedAgentIds = array_map('intval', array_column($agents, 'agent_id'));
$requestedAgents = array_values(array_unique(array_filter(array_map(
    'intval',
    (array) ($_GET['agents'] ?? [])
))));
$selectedAgents = array_values(array_intersect($allowedAgentIds, $requestedAgents));

$params = ['dept' => $deptId, 'from' => $from, 'to' => $to];
$agentFilter = '';
if ($selectedAgents) {
    $placeholders = [];
    foreach ($selectedAgents as $index => $agentId) {
        $name = 'agent_' . $index;
        $placeholders[] = ':' . $name;
        $params[$name] = $agentId;
    }
    $agentFilter = ' AND a.agent_id IN (' . implode(',', $placeholders) . ')';
}

$queueFilter = '';
if ($selectedQueues) {
    $placeholders = [];
    foreach ($selectedQueues as $index => $queueId) {
        $name = 'queue_' . $index;
        $placeholders[] = ':' . $name;
        $params[$name] = $queueId;
    }
    $queueFilter = ' AND q.queuename IN (' . implode(',', $placeholders) . ')';
}

$parsedAgent = "trim(regexp_replace(regexp_replace(COALESCE(q.agent,''),"
    . "'^(PJSIP/|SIP/|Local/|Agent/|Agent[ ]+)','','i'),'@.*$','','g'))";
$sourceToken = "split_part(split_part(COALESCE(c.channel,''),'/',2),'-',1)";
$sourceAgent = "(a.extension=c.src OR {$sourceToken} IN (a.extension,a.dept_id||'_'||a.extension))";

$parts = [];
if ($direction !== 'outgoing') {
    $parts[] = "SELECT a.agent_id,a.display_name,a.extension,q.queuename AS queue_id,'GELEN'::text AS direction,"
        . "CASE WHEN q.event IN ('COMPLETEAGENT','COMPLETECALLER') THEN 'CEVAPLANDI' ELSE 'KACAN' END AS status,"
        . "count(*)::integer AS call_count,"
        . "COALESCE(sum(CASE WHEN q.event IN ('COMPLETEAGENT','COMPLETECALLER') AND q.data2~'^[0-9]+$' "
        . "THEN q.data2::bigint ELSE 0 END),0)::bigint AS talk_seconds "
        . "FROM ranked_incoming q JOIN allowed_agents a "
        . "ON q.agent_extension IN (a.extension,a.dept_id||'_'||a.extension) WHERE q.event_rank=1 "
        . "GROUP BY a.agent_id,a.display_name,a.extension,q.queuename,"
        . "CASE WHEN q.event IN ('COMPLETEAGENT','COMPLETECALLER') THEN 'CEVAPLANDI' ELSE 'KACAN' END";
}
if ($direction !== 'incoming' && !$selectedQueues) {
    $parts[] = "SELECT a.agent_id,a.display_name,a.extension,'—'::text AS queue_id,'GIDEN'::text AS direction,"
        . "CASE WHEN c.disposition='ANSWERED' THEN 'CEVAPLANDI' ELSE 'CEVAPSIZ' END AS status,"
        . "count(*)::integer AS call_count,COALESCE(sum(c.talk_seconds),0)::bigint AS talk_seconds "
        . "FROM deduplicated_outgoing c JOIN allowed_agents a ON a.agent_id=c.agent_id "
        . "GROUP BY a.agent_id,a.display_name,a.extension,"
        . "CASE WHEN c.disposition='ANSWERED' THEN 'CEVAPLANDI' ELSE 'CEVAPSIZ' END";
}

$statusWhere = '';
if ($status !== '') {
    $statusWhere = ' WHERE status=:status';
    $params['status'] = $status;
}

$sql = "WITH allowed_agents AS ("
    . "SELECT a.agent_id,a.dept_id,a.display_name,a.extension FROM callcenter_agents a "
    . "WHERE a.dept_id=:dept AND a.status='active' AND " . supervisor_agent_sql('a') . $agentFilter
    . "), ranked_incoming AS ("
    . "SELECT q.id,q.time,q.callid,q.queuename,q.event,q.data2,{$parsedAgent} AS agent_extension,"
    . "row_number() OVER (PARTITION BY q.callid,q.queuename,{$parsedAgent} ORDER BY "
    . "CASE WHEN q.event IN ('COMPLETEAGENT','COMPLETECALLER') THEN 1 ELSE 2 END,q.time DESC,q.id DESC) AS event_rank "
    . "FROM queue_log q WHERE q.time>=:from::date AND q.time<(:to::date+interval '1 day') "
    . "AND q.event IN ('COMPLETEAGENT','COMPLETECALLER','RINGNOANSWER') "
    . "AND q.callid NOT IN ('','NONE','MANAGER','REALTIME') AND " . supervisor_queue_sql('q.queuename') . $queueFilter
    . "), matched_outgoing AS ("
    . "SELECT c.id,COALESCE(NULLIF(c.linkedid,''),NULLIF(c.uniqueid,''),c.id::text) AS call_key,"
    . "c.calldate,c.disposition,GREATEST(COALESCE(c.billsec,0),0) AS talk_seconds,a.agent_id "
    . "FROM cdr c JOIN allowed_agents a ON a.dept_id=c.dept_id AND {$sourceAgent} "
    . "WHERE c.dept_id=:dept AND c.calldate>=:from::date AND c.calldate<(:to::date+interval '1 day')"
    . "), deduplicated_outgoing AS ("
    . "SELECT DISTINCT ON (call_key,agent_id) call_key,agent_id,disposition,talk_seconds "
    . "FROM matched_outgoing ORDER BY call_key,agent_id,talk_seconds DESC,id DESC"
    . "), grouped AS (" . implode(' UNION ALL ', $parts) . "), filtered AS ("
    . "SELECT *,round(talk_seconds::numeric/NULLIF(call_count,0))::bigint AS average_talk_seconds "
    . "FROM grouped{$statusWhere}) SELECT * FROM filtered "
    . "ORDER BY display_name,extension,direction,queue_id,status LIMIT 100";

$rows = $parts ? callcenter_rows($sql, $params) : [];

$totalCalls = array_sum(array_map('intval', array_column($rows, 'call_count')));
$answered = 0;
$missed = 0;
$incoming = 0;
$outgoing = 0;
$talkSeconds = 0;
foreach ($rows as $row) {
    $count = (int) $row['call_count'];
    $talkSeconds += (int) $row['talk_seconds'];
    if ($row['status'] === 'CEVAPLANDI') {
        $answered += $count;
    } else {
        $missed += $count;
    }
    if ($row['direction'] === 'GELEN') {
        $incoming += $count;
    } else {
        $outgoing += $count;
    }
}

$queueNames = [];
foreach ($queues as $queue) {
    $queueNames[(string) ($queue['id'] ?? '')] = (string) ($queue['name'] ?? $queue['id'] ?? '');
}
$statusLabels = ['CEVAPLANDI' => 'Cevaplandı', 'KACAN' => 'Kaçan', 'CEVAPSIZ' => 'Cevapsız'];

supervisor_page_start('Agent Özet 2');
?>
<form class="filter-bar operational-report-filter" method="get">
    <label class="filter-group"><span class="filter-label">Başlangıç</span><input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="filter-group"><span class="filter-label">Bitiş</span><input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="filter-group"><span class="filter-label">Yön</span><select name="direction">
        <option value="">Tümü</option>
        <option value="incoming"<?= $direction === 'incoming' ? ' selected' : '' ?>>Gelen</option>
        <option value="outgoing"<?= $direction === 'outgoing' ? ' selected' : '' ?>>Giden</option>
    </select></label>
    <label class="filter-group"><span class="filter-label">Durum</span><select name="status">
        <option value="">Tümü</option>
        <?php foreach ($statusLabels as $value => $label): ?><option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group operational-multi"><span class="filter-label">Kuyruklar · Çoklu seçim</span><select name="queues[]" multiple size="4">
        <?php foreach ($queues as $queue): $queueId = (string) ($queue['id'] ?? ''); ?>
            <option value="<?= e($queueId) ?>"<?= in_array($queueId, $selectedQueues, true) ? ' selected' : '' ?>><?= e($queueId . ' · ' . (string) ($queue['name'] ?? $queueId)) ?></option>
        <?php endforeach; ?>
    </select></label>
    <label class="filter-group operational-multi"><span class="filter-label">Agentlar · Çoklu seçim</span><select name="agents[]" multiple size="4">
        <?php foreach ($agents as $agent): ?><option value="<?= (int) $agent['agent_id'] ?>"<?= in_array((int) $agent['agent_id'], $selectedAgents, true) ? ' selected' : '' ?>><?= e((string) $agent['display_name'] . ' · ' . (string) $agent['extension']) ?></option><?php endforeach; ?>
    </select></label>
    <button class="btn btn-primary" type="submit">Raporla</button>
    <a class="btn btn-outline" href="agent_ozet2.php">Temizle</a>
</form>

<section class="stats-grid operational-report-stats">
    <article class="stat-card"><div class="stat-label">Gösterilen Grup</div><div class="stat-value"><?= count($rows) ?></div></article>
    <article class="stat-card"><div class="stat-label">Toplam Çağrı</div><div class="stat-value"><?= $totalCalls ?></div></article>
    <article class="stat-card"><div class="stat-label">Cevaplanan</div><div class="stat-value"><?= $answered ?></div></article>
    <article class="stat-card"><div class="stat-label">Kaçan / Cevapsız</div><div class="stat-value"><?= $missed ?></div></article>
    <article class="stat-card"><div class="stat-label">Gelen / Giden</div><div class="stat-value"><?= $incoming ?> / <?= $outgoing ?></div></article>
    <article class="stat-card"><div class="stat-label">Toplam Görüşme</div><div class="stat-value operational-duration"><?= e(supervisor_duration($talkSeconds)) ?></div></article>
</section>

<div class="operational-report-actions">
    <span>Özet cardinality düşük olduğundan sonuçlar güvenli sabit 100 grupla sınırlıdır. Kuyruk seçimi giden çağrıları dışlar. Excel ve yazdırma yalnız görünen satırları kapsar.</span>
    <button class="btn btn-success" id="summary2-export" type="button">Excel (.xls)</button>
    <button class="btn btn-outline" id="summary2-print" type="button">Yazdır</button>
</div>
<div class="operational-report-search"><label for="summary2-search">Bu sayfadaki sonuçlarda ara</label><input id="summary2-search" type="search" placeholder="Tüm sütunlarda ara…"></div>

<div class="table-container operational-print-area">
    <div class="table-header">Agent + Kuyruk + Yön + Durum Özeti · En fazla 100 grup</div>
    <table class="operational-report-table" id="summary2-table"><thead><tr>
        <th>Agent</th><th>Dahili</th><th>Kuyruk Kodu</th><th>Kuyruk Adı</th><th>Yön</th><th>Durum</th>
        <th>Çağrı Adedi</th><th>Toplam Görüşme</th><th>Ortalama Görüşme</th>
    </tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><strong><?= e((string) $row['display_name']) ?></strong></td>
            <td><?= e((string) $row['extension']) ?></td>
            <td><?= e((string) $row['queue_id']) ?></td>
            <td><?= e($queueNames[(string) $row['queue_id']] ?? (string) $row['queue_id']) ?></td>
            <td><span class="badge <?= $row['direction'] === 'GELEN' ? 'badge-success' : 'badge-warning' ?>"><?= e((string) $row['direction']) ?></span></td>
            <td><span class="badge <?= $row['status'] === 'CEVAPLANDI' ? 'badge-success' : 'badge-danger' ?>"><?= e($statusLabels[(string) $row['status']] ?? (string) $row['status']) ?></span></td>
            <td><?= (int) $row['call_count'] ?></td>
            <td><?= e(supervisor_duration($row['talk_seconds'])) ?></td>
            <td><?= e(supervisor_duration($row['average_talk_seconds'])) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">Seçilen filtrelerde özet bulunamadı.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<script>
(()=>{
    const table=document.getElementById('summary2-table');
    document.getElementById('summary2-search')?.addEventListener('input',event=>{
        const query=event.currentTarget.value.toLocaleLowerCase('tr-TR');
        [...table.tBodies[0].rows].forEach(row=>{row.hidden=query!==''&&!row.textContent.toLocaleLowerCase('tr-TR').includes(query);});
    });
    document.getElementById('summary2-print')?.addEventListener('click',()=>window.print());
    document.getElementById('summary2-export')?.addEventListener('click',()=>{
        const escapeHtml=value=>{const node=document.createElement('div');node.textContent=value;return node.innerHTML;};
        const excelSafe=value=>/^[=+\-@]/.test(value.trim())?"'"+value:value;
        const headers=[...table.tHead.rows[0].cells].map(cell=>`<th>${escapeHtml(cell.textContent.trim())}</th>`).join('');
        const rows=[...table.tBodies[0].rows].filter(row=>!row.hidden&&row.cells.length>1).map(row=>'<tr>'+[...row.cells].map(cell=>`<td>${escapeHtml(excelSafe(cell.textContent.trim()))}</td>`).join('')+'</tr>').join('');
        const html=`<!doctype html><html><head><meta charset="utf-8"></head><body><h2>Agent Özet 2</h2><p><?= e(date('d.m.Y', strtotime($from))) ?> - <?= e(date('d.m.Y', strtotime($to))) ?> · Görünen satırlar</p><table border="1"><thead><tr>${headers}</tr></thead><tbody>${rows}</tbody></table></body></html>`;
        const url=URL.createObjectURL(new Blob(['\ufeff',html],{type:'application/vnd.ms-excel;charset=utf-8'}));
        const link=document.createElement('a');link.href=url;link.download=<?= json_encode('agent_ozet2_' . date('Y-m-d') . '.xls', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        document.body.appendChild(link);link.click();link.remove();URL.revokeObjectURL(url);
    });
})();
</script>
<?php supervisor_page_end(); ?>
