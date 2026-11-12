<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$from = supervisor_date('from');
$to = supervisor_date('to', $from);
$fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
$toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
if (!$fromDate || $fromDate->format('Y-m-d') !== $from) {
    $from = date('Y-m-d');
}
if (!$toDate || $toDate->format('Y-m-d') !== $to) {
    $to = $from;
}
if ($to < $from) {
    $to = $from;
}

$agents = callcenter_rows(
    "SELECT a.agent_id,a.display_name,a.extension,a.username,a.status FROM callcenter_agents a "
    . "WHERE a.dept_id=:dept AND " . supervisor_agent_sql('a')
    . " ORDER BY (a.status='active') DESC,a.display_name,a.extension",
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

$sourceToken = "split_part(split_part(COALESCE(c.channel,''),'/',2),'-',1)";
$destinationToken = "split_part(split_part(COALESCE(c.dstchannel,''),'/',2),'-',1)";
$sourceMatch = "(a.extension=c.src OR {$sourceToken} IN (a.extension,a.dept_id||'_'||a.extension))";
$destinationMatch = "(a.extension=c.dst OR {$destinationToken} IN (a.extension,a.dept_id||'_'||a.extension))";

$sql = "WITH selected_agents AS ("
    . "SELECT a.agent_id,a.dept_id,a.display_name,a.extension,a.username "
    . "FROM callcenter_agents a WHERE a.dept_id=:dept AND "
    . supervisor_agent_sql('a') . $agentFilter
    . "), session_scoped AS ("
    . "SELECT s.agent_id,GREATEST(s.started_at,:from::date) AS activity_start,"
    . "LEAST(COALESCE(s.ended_at,now()),:to::date+interval '1 day') AS activity_end "
    . "FROM callcenter_agent_sessions s JOIN selected_agents a "
    . "ON a.dept_id=s.dept_id AND a.agent_id=s.agent_id "
    . "WHERE s.dept_id=:dept AND s.started_at<(:to::date+interval '1 day') "
    . "AND COALESCE(s.ended_at,now())>:from::date"
    . "), session_totals AS ("
    . "SELECT agent_id,COALESCE(sum(EXTRACT(EPOCH FROM (activity_end-activity_start))),0)::bigint AS login_seconds,"
    . "min(activity_start) AS first_activity,max(activity_end) AS last_activity "
    . "FROM session_scoped WHERE activity_end>activity_start GROUP BY agent_id"
    . "), break_scoped AS ("
    . "SELECT b.agent_id,"
    . "GREATEST(b.started_at,:from::date,COALESCE(s.started_at,b.started_at)) AS activity_start,"
    . "LEAST(COALESCE(b.ended_at,now()),:to::date+interval '1 day',"
    . "COALESCE(s.ended_at,COALESCE(b.ended_at,now()))) AS activity_end "
    . "FROM callcenter_breaks b JOIN selected_agents a "
    . "ON a.dept_id=b.dept_id AND a.agent_id=b.agent_id "
    . "LEFT JOIN callcenter_agent_sessions s ON s.dept_id=b.dept_id "
    . "AND s.agent_id=b.agent_id AND s.session_id=b.session_id "
    . "WHERE b.dept_id=:dept AND b.started_at<(:to::date+interval '1 day') "
    . "AND COALESCE(b.ended_at,now())>:from::date"
    . "), break_totals AS ("
    . "SELECT agent_id,count(*)::integer AS break_count,"
    . "COALESCE(sum(EXTRACT(EPOCH FROM (activity_end-activity_start))),0)::bigint AS break_seconds,"
    . "min(activity_start) AS first_activity,max(activity_end) AS last_activity "
    . "FROM break_scoped WHERE activity_end>activity_start GROUP BY agent_id"
    . "), call_matches AS ("
    . "SELECT c.id AS cdr_id,a.agent_id,"
    . "COALESCE(NULLIF(c.linkedid,''),NULLIF(c.uniqueid,''),c.id::text) AS call_key,"
    . "c.calldate,GREATEST(COALESCE(c.billsec,0),0)::bigint AS billsec "
    . "FROM cdr c JOIN selected_agents a ON a.dept_id=c.dept_id AND "
    . "({$sourceMatch} OR {$destinationMatch}) "
    . "WHERE c.dept_id=:dept AND c.calldate>=:from::date "
    . "AND c.calldate<(:to::date+interval '1 day') AND c.disposition='ANSWERED' AND c.billsec>0"
    . "), deduplicated_calls AS ("
    . "SELECT DISTINCT ON (agent_id,call_key) agent_id,call_key,calldate,billsec "
    . "FROM call_matches ORDER BY agent_id,call_key,billsec DESC,cdr_id DESC"
    . "), call_totals AS ("
    . "SELECT agent_id,COALESCE(sum(billsec),0)::bigint AS talk_seconds,"
    . "min(calldate) AS first_activity,max(calldate+(billsec*interval '1 second')) AS last_activity "
    . "FROM deduplicated_calls GROUP BY agent_id"
    . "), report_rows AS ("
    . "SELECT a.agent_id,a.display_name,a.extension,a.username,"
    . "COALESCE(s.login_seconds,0)::bigint AS login_seconds,"
    . "COALESCE(b.break_seconds,0)::bigint AS break_seconds,"
    . "COALESCE(c.talk_seconds,0)::bigint AS talk_seconds,"
    . "GREATEST(COALESCE(s.login_seconds,0)-COALESCE(b.break_seconds,0),0)::bigint AS net_seconds,"
    . "COALESCE(b.break_count,0)::integer AS break_count,"
    . "LEAST(s.first_activity,b.first_activity,c.first_activity) AS first_activity,"
    . "GREATEST(s.last_activity,b.last_activity,c.last_activity) AS last_activity "
    . "FROM selected_agents a LEFT JOIN session_totals s ON s.agent_id=a.agent_id "
    . "LEFT JOIN break_totals b ON b.agent_id=a.agent_id "
    . "LEFT JOIN call_totals c ON c.agent_id=a.agent_id "
    . "WHERE s.agent_id IS NOT NULL OR b.agent_id IS NOT NULL OR c.agent_id IS NOT NULL"
    . "), with_totals AS (SELECT r.*,count(*) OVER()::integer AS report_agent_count,"
    . "COALESCE(sum(login_seconds) OVER(),0)::bigint AS report_login_seconds,"
    . "COALESCE(sum(break_seconds) OVER(),0)::bigint AS report_break_seconds,"
    . "COALESCE(sum(talk_seconds) OVER(),0)::bigint AS report_talk_seconds,"
    . "COALESCE(sum(net_seconds) OVER(),0)::bigint AS report_net_seconds,"
    . "COALESCE(sum(break_count) OVER(),0)::bigint AS report_break_count FROM report_rows r) "
    . "SELECT *,to_char(first_activity,'DD.MM.YYYY HH24:MI:SS') AS first_text,"
    . "to_char(last_activity,'DD.MM.YYYY HH24:MI:SS') AS last_text "
    . "FROM with_totals ORDER BY last_activity DESC NULLS LAST,agent_id LIMIT 100";
$rows = callcenter_rows($sql, $params);

$agentCount = (int) ($rows[0]['report_agent_count'] ?? 0);
$loginSeconds = (int) ($rows[0]['report_login_seconds'] ?? 0);
$breakSeconds = (int) ($rows[0]['report_break_seconds'] ?? 0);
$talkSeconds = (int) ($rows[0]['report_talk_seconds'] ?? 0);
$netSeconds = (int) ($rows[0]['report_net_seconds'] ?? 0);
$breakCount = (int) ($rows[0]['report_break_count'] ?? 0);

supervisor_page_start('Mola Özet Toplam');
?>
<form class="filter-bar operational-report-filter" method="get">
    <label class="filter-group"><span class="filter-label">Başlangıç</span><input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="filter-group"><span class="filter-label">Bitiş</span><input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="filter-group operational-multi"><span class="filter-label">Agentlar · Çoklu seçim</span><select name="agents[]" multiple size="4">
        <?php foreach ($agents as $agent): ?><option value="<?= (int) $agent['agent_id'] ?>"<?= in_array((int) $agent['agent_id'], $selectedAgents, true) ? ' selected' : '' ?>><?= e((string) $agent['display_name'] . ' · ' . (string) $agent['extension'] . ((string) $agent['status'] === 'active' ? '' : ' · Pasif')) ?></option><?php endforeach; ?>
    </select></label>
    <button class="btn btn-primary" type="submit">Raporla</button>
    <a class="btn btn-outline" href="molaozettoplam.php">Temizle</a>
</form>

<section class="stats-grid operational-report-stats">
    <article class="stat-card"><div class="stat-label">Agent</div><div class="stat-value"><?= $agentCount ?></div></article>
    <article class="stat-card"><div class="stat-label">Mola Adedi</div><div class="stat-value"><?= $breakCount ?></div></article>
    <article class="stat-card"><div class="stat-label">Toplam Login</div><div class="stat-value operational-duration"><?= e(supervisor_duration($loginSeconds)) ?></div></article>
    <article class="stat-card"><div class="stat-label">Toplam Mola</div><div class="stat-value operational-duration"><?= e(supervisor_duration($breakSeconds)) ?></div></article>
    <article class="stat-card"><div class="stat-label">Toplam Görüşme</div><div class="stat-value operational-duration"><?= e(supervisor_duration($talkSeconds)) ?></div></article>
    <article class="stat-card"><div class="stat-label">Net Çalışma</div><div class="stat-value operational-duration"><?= e(supervisor_duration($netSeconds)) ?></div></article>
</section>

<div class="operational-report-actions">
    <span>Net çalışma = login − mola. Excel ve yazdırma ekrandaki en fazla 100 agentı kapsar.</span>
    <button class="btn btn-success" id="total-export" type="button">Excel (.xls)</button>
    <button class="btn btn-outline" id="total-print" type="button">Yazdır</button>
</div>
<div class="operational-report-search"><label for="total-search">Bu sayfadaki sonuçlarda ara</label><input id="total-search" type="search" placeholder="Tüm sütunlarda ara…"></div>

<div class="table-container operational-print-area">
    <div class="table-header">Agent Toplamları · En fazla 100 satır</div>
    <table class="operational-report-table" id="total-table"><thead><tr>
        <th class="operational-sortable" data-sort="0" tabindex="0">Agent Dahili <span>↕</span></th>
        <th class="operational-sortable" data-sort="1" tabindex="0">Agent Adı <span>↕</span></th>
        <th class="operational-sortable" data-sort="2" tabindex="0">Login Süresi <span>↕</span></th>
        <th class="operational-sortable" data-sort="3" tabindex="0">Mola Süresi <span>↕</span></th>
        <th class="operational-sortable" data-sort="4" tabindex="0">Görüşme Süresi <span>↕</span></th>
        <th class="operational-sortable" data-sort="5" tabindex="0">Net Çalışma <span>↕</span></th>
        <th class="operational-sortable" data-sort="6" tabindex="0">Mola Adedi <span>↕</span></th>
        <th class="operational-sortable" data-sort="7" tabindex="0">İlk Aktivite <span>↕</span></th>
        <th class="operational-sortable" data-sort="8" tabindex="0">Son Aktivite <span>↕</span></th>
    </tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td data-sort-value="<?= e((string) $row['extension']) ?>"><strong><?= e((string) $row['extension']) ?></strong></td>
            <td data-sort-value="<?= e((string) $row['display_name']) ?>"><?= e((string) $row['display_name']) ?><small class="agent-detail-sub"><?= e((string) $row['username']) ?></small></td>
            <td data-sort-value="<?= (int) $row['login_seconds'] ?>"><?= e(supervisor_duration($row['login_seconds'])) ?></td>
            <td data-sort-value="<?= (int) $row['break_seconds'] ?>"><?= e(supervisor_duration($row['break_seconds'])) ?></td>
            <td data-sort-value="<?= (int) $row['talk_seconds'] ?>"><?= e(supervisor_duration($row['talk_seconds'])) ?></td>
            <td data-sort-value="<?= (int) $row['net_seconds'] ?>"><?= e(supervisor_duration($row['net_seconds'])) ?></td>
            <td data-sort-value="<?= (int) $row['break_count'] ?>"><?= (int) $row['break_count'] ?></td>
            <td class="audit-time" data-sort-value="<?= e((string) ($row['first_activity'] ?? '')) ?>"><?= e((string) ($row['first_text'] ?: '—')) ?></td>
            <td class="audit-time" data-sort-value="<?= e((string) ($row['last_activity'] ?? '')) ?>"><?= e((string) ($row['last_text'] ?: '—')) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">Seçilen filtrelerde agent aktivitesi bulunamadı.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<script>
(()=>{
    const table=document.getElementById('total-table');
    const collator=new Intl.Collator('tr-TR',{numeric:true,sensitivity:'base'});
    const directions={};
    const sortBy=header=>{
        const index=Number(header.dataset.sort),direction=directions[index]==='asc'?'desc':'asc';
        directions[index]=direction;
        const rows=[...table.tBodies[0].rows].filter(row=>row.cells.length>1);
        rows.sort((left,right)=>{
            const a=left.cells[index].dataset.sortValue??left.cells[index].textContent.trim();
            const b=right.cells[index].dataset.sortValue??right.cells[index].textContent.trim();
            return collator.compare(a,b)*(direction==='asc'?1:-1);
        });
        rows.forEach(row=>table.tBodies[0].appendChild(row));
        table.querySelectorAll('.operational-sortable span').forEach(icon=>icon.textContent='↕');
        header.querySelector('span').textContent=direction==='asc'?'↑':'↓';
    };
    table.querySelectorAll('.operational-sortable').forEach(header=>{
        header.addEventListener('click',()=>sortBy(header));
        header.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();sortBy(header);}});
    });
    document.getElementById('total-search')?.addEventListener('input',event=>{
        const query=event.currentTarget.value.toLocaleLowerCase('tr-TR');
        [...table.tBodies[0].rows].forEach(row=>{row.hidden=query!==''&&!row.textContent.toLocaleLowerCase('tr-TR').includes(query);});
    });
    document.getElementById('total-print')?.addEventListener('click',()=>window.print());
    document.getElementById('total-export')?.addEventListener('click',()=>{
        const escapeHtml=value=>{const node=document.createElement('div');node.textContent=value;return node.innerHTML;};
        const excelSafe=value=>/^[=+\-@]/.test(value.trim())?"'"+value:value;
        const headers=[...table.tHead.rows[0].cells].map(cell=>`<th>${escapeHtml(cell.textContent.replace(/[↕↑↓]/g,'').trim())}</th>`).join('');
        const rows=[...table.tBodies[0].rows].filter(row=>!row.hidden&&row.cells.length>1).map(row=>'<tr>'+[...row.cells].map(cell=>`<td>${escapeHtml(excelSafe(cell.textContent.trim()))}</td>`).join('')+'</tr>').join('');
        const html=`<!doctype html><html><head><meta charset="utf-8"><style>table{border-collapse:collapse}th,td{border:1px solid #999;padding:6px}th{background:#ddd}</style></head><body><h2>Mola Özet Toplam Raporu</h2><p><?= e(date('d.m.Y', strtotime($from))) ?> - <?= e(date('d.m.Y', strtotime($to))) ?> · Ekrandaki satırlar</p><table><thead><tr>${headers}</tr></thead><tbody>${rows}</tbody></table></body></html>`;
        const url=URL.createObjectURL(new Blob(['\ufeff',html],{type:'application/vnd.ms-excel;charset=utf-8'}));
        const link=document.createElement('a');
        link.href=url;
        link.download=<?= json_encode('mola_ozet_toplam_' . date('Y-m-d') . '.xls', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        document.body.appendChild(link);link.click();link.remove();URL.revokeObjectURL(url);
    });
})();
</script>
<?php supervisor_page_end(); ?>
