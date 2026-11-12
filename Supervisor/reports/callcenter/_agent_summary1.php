<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$from = supervisor_date('from');
$to = supervisor_date('to', $from);
if ($to < $from) {
    $to = $from;
}
$groupBy = ($_GET['group_by'] ?? 'date') === 'agent' ? 'agent' : 'date';

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

$bucketSql = $groupBy === 'date'
    ? "SELECT day AS bucket_start,day+interval '1 day' AS bucket_end,to_char(day,'DD.MM.YYYY') AS bucket_label "
        . "FROM generate_series(:from::date,:to::date,interval '1 day') day"
    : "SELECT :from::date AS bucket_start,:to::date+interval '1 day' AS bucket_end,'Toplam'::text AS bucket_label";

$parsedAgent = "trim(regexp_replace(regexp_replace(COALESCE(q.agent,''),"
    . "'^(PJSIP/|SIP/|Local/|Agent/|Agent[ ]+)','','i'),'@.*$','','g'))";
$sourceToken = "split_part(split_part(COALESCE(c.channel,''),'/',2),'-',1)";
$destinationToken = "split_part(split_part(COALESCE(c.dstchannel,''),'/',2),'-',1)";
$sourceAgent = "(a.extension=c.src OR {$sourceToken} IN (a.extension,a.dept_id||'_'||a.extension))";
$destinationAgent = "(a.extension=c.dst OR {$destinationToken} IN (a.extension,a.dept_id||'_'||a.extension))";
$directionSql = "CASE "
    . "WHEN COALESCE(c.recordingfile,'') LIKE '%yon_gelen%' THEN 'incoming' "
    . "WHEN COALESCE(c.recordingfile,'') LIKE '%yon_giden%' THEN 'outgoing' "
    . "WHEN {$destinationAgent} AND NOT {$sourceAgent} THEN 'incoming' "
    . "WHEN {$sourceAgent} THEN 'outgoing' ELSE 'incoming' END";

$sql = "WITH allowed_agents AS ("
    . "SELECT a.agent_id,a.dept_id,a.display_name,a.extension FROM callcenter_agents a "
    . "WHERE a.dept_id=:dept AND a.status='active' AND " . supervisor_agent_sql('a') . $agentFilter
    . "), buckets AS ({$bucketSql}), grid AS ("
    . "SELECT a.agent_id,a.display_name,a.extension,b.bucket_start,b.bucket_end,b.bucket_label "
    . "FROM allowed_agents a CROSS JOIN buckets b), session_totals AS ("
    . "SELECT g.agent_id,g.bucket_start,COALESCE(sum(GREATEST(0,EXTRACT(EPOCH FROM ("
    . "LEAST(COALESCE(s.ended_at,now()),g.bucket_end)-GREATEST(s.started_at,g.bucket_start))))),0)::bigint AS login_seconds "
    . "FROM grid g LEFT JOIN callcenter_agent_sessions s ON s.dept_id=:dept AND s.agent_id=g.agent_id "
    . "AND s.started_at<g.bucket_end AND COALESCE(s.ended_at,now())>g.bucket_start "
    . "GROUP BY g.agent_id,g.bucket_start), break_totals AS ("
    . "SELECT g.agent_id,g.bucket_start,COALESCE(sum(GREATEST(0,EXTRACT(EPOCH FROM ("
    . "LEAST(COALESCE(b.ended_at,now()),g.bucket_end)-GREATEST(b.started_at,g.bucket_start))))),0)::bigint AS break_seconds "
    . "FROM grid g LEFT JOIN callcenter_breaks b ON b.dept_id=:dept AND b.agent_id=g.agent_id "
    . "AND b.started_at<g.bucket_end AND COALESCE(b.ended_at,now())>g.bucket_start "
    . "GROUP BY g.agent_id,g.bucket_start), ranked_queue AS ("
    . "SELECT q.id,q.time,q.callid,q.queuename,q.event,q.data2,{$parsedAgent} AS agent_extension,"
    . "row_number() OVER (PARTITION BY q.callid,q.queuename,{$parsedAgent} ORDER BY "
    . "CASE WHEN q.event IN ('COMPLETEAGENT','COMPLETECALLER') THEN 1 ELSE 2 END,q.time DESC,q.id DESC) AS event_rank "
    . "FROM queue_log q WHERE q.time>=:from::date AND q.time<(:to::date+interval '1 day') "
    . "AND q.event IN ('COMPLETEAGENT','COMPLETECALLER','RINGNOANSWER') "
    . "AND q.callid NOT IN ('','NONE','MANAGER','REALTIME') AND " . supervisor_queue_sql('q.queuename')
    . "), queue_totals AS ("
    . "SELECT a.agent_id,g.bucket_start,"
    . "count(*) FILTER (WHERE q.event IN ('COMPLETEAGENT','COMPLETECALLER'))::integer AS answered_queue_calls,"
    . "count(*) FILTER (WHERE q.event='RINGNOANSWER')::integer AS missed_queue_calls,"
    . "COALESCE(sum(CASE WHEN q.event IN ('COMPLETEAGENT','COMPLETECALLER') AND q.data2~'^[0-9]+$' "
    . "THEN q.data2::bigint ELSE 0 END),0)::bigint AS queue_talk_seconds "
    . "FROM ranked_queue q JOIN allowed_agents a ON q.agent_extension IN (a.extension,a.dept_id||'_'||a.extension) "
    . "JOIN grid g ON g.agent_id=a.agent_id AND q.time>=g.bucket_start AND q.time<g.bucket_end "
    . "WHERE q.event_rank=1 GROUP BY a.agent_id,g.bucket_start), matched_cdr AS ("
    . "SELECT c.id,COALESCE(NULLIF(c.linkedid,''),NULLIF(c.uniqueid,''),c.id::text) AS call_key,"
    . "c.calldate,c.billsec,a.agent_id,{$directionSql} AS direction "
    . "FROM cdr c JOIN allowed_agents a ON a.dept_id=c.dept_id AND ({$sourceAgent} OR {$destinationAgent}) "
    . "WHERE c.dept_id=:dept AND c.calldate>=:from::date AND c.calldate<(:to::date+interval '1 day')"
    . "), deduplicated_cdr AS ("
    . "SELECT DISTINCT ON (call_key,agent_id) call_key,agent_id,calldate,direction,GREATEST(COALESCE(billsec,0),0) AS talk_seconds "
    . "FROM matched_cdr ORDER BY call_key,agent_id,GREATEST(COALESCE(billsec,0),0) DESC,id DESC"
    . "), cdr_totals AS ("
    . "SELECT c.agent_id,g.bucket_start,"
    . "count(*) FILTER (WHERE c.direction='incoming')::integer AS incoming_calls,"
    . "COALESCE(sum(c.talk_seconds) FILTER (WHERE c.direction='incoming'),0)::bigint AS incoming_talk_seconds,"
    . "count(*) FILTER (WHERE c.direction='outgoing')::integer AS outgoing_calls,"
    . "COALESCE(sum(c.talk_seconds) FILTER (WHERE c.direction='outgoing'),0)::bigint AS outgoing_talk_seconds "
    . "FROM deduplicated_cdr c JOIN grid g ON g.agent_id=c.agent_id "
    . "AND c.calldate>=g.bucket_start AND c.calldate<g.bucket_end GROUP BY c.agent_id,g.bucket_start"
    . ") SELECT g.bucket_label,g.bucket_start,g.agent_id,g.display_name,g.extension,"
    . "COALESCE(s.login_seconds,0)::bigint AS login_seconds,COALESCE(b.break_seconds,0)::bigint AS break_seconds,"
    . "GREATEST(COALESCE(s.login_seconds,0)-COALESCE(b.break_seconds,0),0)::bigint AS net_seconds,"
    . "COALESCE(q.answered_queue_calls,0)::integer AS answered_queue_calls,"
    . "COALESCE(q.missed_queue_calls,0)::integer AS missed_queue_calls,"
    . "COALESCE(q.queue_talk_seconds,0)::bigint AS queue_talk_seconds,"
    . "COALESCE(c.incoming_calls,0)::integer AS incoming_calls,"
    . "COALESCE(c.incoming_talk_seconds,0)::bigint AS incoming_talk_seconds,"
    . "COALESCE(c.outgoing_calls,0)::integer AS outgoing_calls,"
    . "COALESCE(c.outgoing_talk_seconds,0)::bigint AS outgoing_talk_seconds "
    . "FROM grid g JOIN session_totals s ON s.agent_id=g.agent_id AND s.bucket_start=g.bucket_start "
    . "JOIN break_totals b ON b.agent_id=g.agent_id AND b.bucket_start=g.bucket_start "
    . "LEFT JOIN queue_totals q ON q.agent_id=g.agent_id AND q.bucket_start=g.bucket_start "
    . "LEFT JOIN cdr_totals c ON c.agent_id=g.agent_id AND c.bucket_start=g.bucket_start "
    . "ORDER BY g.bucket_start DESC,g.display_name,g.extension LIMIT 100";

$rows = callcenter_rows($sql, $params);

$totalLogin = array_sum(array_map('intval', array_column($rows, 'login_seconds')));
$totalBreak = array_sum(array_map('intval', array_column($rows, 'break_seconds')));
$totalIncoming = array_sum(array_map('intval', array_column($rows, 'incoming_calls')));
$totalOutgoing = array_sum(array_map('intval', array_column($rows, 'outgoing_calls')));
$totalTalk = array_sum(array_map('intval', array_column($rows, 'incoming_talk_seconds')))
    + array_sum(array_map('intval', array_column($rows, 'outgoing_talk_seconds')));
$totalCalls = $totalIncoming + $totalOutgoing;

supervisor_page_start('Agent Özet');
?>
<form class="filter-bar operational-report-filter" method="get">
    <label class="filter-group"><span class="filter-label">Başlangıç</span><input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="filter-group"><span class="filter-label">Bitiş</span><input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="filter-group"><span class="filter-label">Gruplama</span><select name="group_by">
        <option value="date"<?= $groupBy === 'date' ? ' selected' : '' ?>>Tarih + Agent</option>
        <option value="agent"<?= $groupBy === 'agent' ? ' selected' : '' ?>>Agent</option>
    </select></label>
    <label class="filter-group operational-multi"><span class="filter-label">Agentlar · Çoklu seçim</span><select name="agents[]" multiple size="4">
        <?php foreach ($agents as $agent): ?><option value="<?= (int) $agent['agent_id'] ?>"<?= in_array((int) $agent['agent_id'], $selectedAgents, true) ? ' selected' : '' ?>><?= e((string) $agent['display_name'] . ' · ' . (string) $agent['extension']) ?></option><?php endforeach; ?>
    </select></label>
    <button class="btn btn-primary" type="submit">Raporla</button>
    <a class="btn btn-outline" href="agent_ozet1.php">Temizle</a>
</form>

<section class="stats-grid operational-report-stats">
    <article class="stat-card"><div class="stat-label">Gösterilen Grup</div><div class="stat-value"><?= count($rows) ?></div></article>
    <article class="stat-card"><div class="stat-label">Login Süresi</div><div class="stat-value operational-duration"><?= e(supervisor_duration($totalLogin)) ?></div></article>
    <article class="stat-card"><div class="stat-label">Mola Süresi</div><div class="stat-value operational-duration"><?= e(supervisor_duration($totalBreak)) ?></div></article>
    <article class="stat-card"><div class="stat-label">Gelen / Giden</div><div class="stat-value"><?= $totalIncoming ?> / <?= $totalOutgoing ?></div></article>
    <article class="stat-card"><div class="stat-label">Toplam Görüşme</div><div class="stat-value operational-duration"><?= e(supervisor_duration($totalTalk)) ?></div></article>
    <article class="stat-card"><div class="stat-label">Ortalama Görüşme</div><div class="stat-value operational-duration"><?= e(supervisor_duration($totalCalls ? round($totalTalk / $totalCalls) : 0)) ?></div></article>
</section>

<div class="operational-report-actions">
    <span>Özet cardinality düşük olduğundan sonuçlar güvenli sabit 100 grupla sınırlıdır. Excel ve yazdırma yalnız görünen sayfa satırlarını kapsar.</span>
    <button class="btn btn-success" id="summary1-export" type="button">Excel (.xls)</button>
    <button class="btn btn-outline" id="summary1-print" type="button">Yazdır</button>
</div>
<div class="operational-report-search"><label for="summary1-search">Bu sayfadaki sonuçlarda ara</label><input id="summary1-search" type="search" placeholder="Tüm sütunlarda ara…"></div>

<div class="table-container operational-print-area">
    <div class="table-header">Agent Özet Metrikleri · En fazla 100 grup</div>
    <table class="operational-report-table" id="summary1-table"><thead><tr>
        <th>Grup</th><th>Agent</th><th>Dahili</th><th>Login / Mesai</th><th>Mola</th><th>Net Çalışma</th>
        <th>Cevaplanan Kuyruk</th><th>Kaçan Kuyruk</th><th>Kuyruk Görüşme</th><th>Gelen Çağrı</th><th>Gelen Görüşme</th>
        <th>Giden Çağrı</th><th>Giden Görüşme</th><th>Toplam Çağrı</th><th>Ortalama Görüşme</th>
    </tr></thead><tbody>
    <?php foreach ($rows as $row):
        $callCount = (int) $row['incoming_calls'] + (int) $row['outgoing_calls'];
        $talkSeconds = (int) $row['incoming_talk_seconds'] + (int) $row['outgoing_talk_seconds'];
    ?>
        <tr>
            <td><?= e((string) $row['bucket_label']) ?></td>
            <td><strong><?= e((string) $row['display_name']) ?></strong></td>
            <td><?= e((string) $row['extension']) ?></td>
            <td><?= e(supervisor_duration($row['login_seconds'])) ?></td>
            <td><?= e(supervisor_duration($row['break_seconds'])) ?></td>
            <td><?= e(supervisor_duration($row['net_seconds'])) ?></td>
            <td><?= (int) $row['answered_queue_calls'] ?></td>
            <td><?= (int) $row['missed_queue_calls'] ?></td>
            <td><?= e(supervisor_duration($row['queue_talk_seconds'])) ?></td>
            <td><?= (int) $row['incoming_calls'] ?></td>
            <td><?= e(supervisor_duration($row['incoming_talk_seconds'])) ?></td>
            <td><?= (int) $row['outgoing_calls'] ?></td>
            <td><?= e(supervisor_duration($row['outgoing_talk_seconds'])) ?></td>
            <td><?= $callCount ?></td>
            <td><?= e(supervisor_duration($callCount ? round($talkSeconds / $callCount) : 0)) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="15" class="empty">Seçilen filtrelerde özet bulunamadı.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<script>
(()=>{
    const table=document.getElementById('summary1-table');
    document.getElementById('summary1-search')?.addEventListener('input',event=>{
        const query=event.currentTarget.value.toLocaleLowerCase('tr-TR');
        [...table.tBodies[0].rows].forEach(row=>{row.hidden=query!==''&&!row.textContent.toLocaleLowerCase('tr-TR').includes(query);});
    });
    document.getElementById('summary1-print')?.addEventListener('click',()=>window.print());
    document.getElementById('summary1-export')?.addEventListener('click',()=>{
        const escapeHtml=value=>{const node=document.createElement('div');node.textContent=value;return node.innerHTML;};
        const excelSafe=value=>/^[=+\-@]/.test(value.trim())?"'"+value:value;
        const headers=[...table.tHead.rows[0].cells].map(cell=>`<th>${escapeHtml(cell.textContent.trim())}</th>`).join('');
        const rows=[...table.tBodies[0].rows].filter(row=>!row.hidden&&row.cells.length>1).map(row=>'<tr>'+[...row.cells].map(cell=>`<td>${escapeHtml(excelSafe(cell.textContent.trim()))}</td>`).join('')+'</tr>').join('');
        const html=`<!doctype html><html><head><meta charset="utf-8"></head><body><h2>Agent Özet</h2><p><?= e(date('d.m.Y', strtotime($from))) ?> - <?= e(date('d.m.Y', strtotime($to))) ?> · Görünen satırlar</p><table border="1"><thead><tr>${headers}</tr></thead><tbody>${rows}</tbody></table></body></html>`;
        const url=URL.createObjectURL(new Blob(['\ufeff',html],{type:'application/vnd.ms-excel;charset=utf-8'}));
        const link=document.createElement('a');link.href=url;link.download=<?= json_encode('agent_ozet1_' . date('Y-m-d') . '.xls', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        document.body.appendChild(link);link.click();link.remove();URL.revokeObjectURL(url);
    });
})();
</script>
<?php supervisor_page_end(); ?>
