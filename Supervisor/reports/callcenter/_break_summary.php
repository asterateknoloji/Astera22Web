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

$breakTypes = callcenter_rows(
    'SELECT t.break_type_id,t.code,t.name,t.active FROM callcenter_break_types t '
    . 'WHERE t.dept_id=:dept ORDER BY t.active DESC,t.name,t.code',
    ['dept' => $deptId]
);
$allowedBreakTypeIds = array_map('intval', array_column($breakTypes, 'break_type_id'));
$requestedBreakTypes = array_values(array_unique(array_filter(array_map(
    'intval',
    (array) ($_GET['break_types'] ?? [])
))));
$selectedBreakTypes = array_values(array_intersect($allowedBreakTypeIds, $requestedBreakTypes));

$params = ['dept' => $deptId, 'from' => $from, 'to' => $to];
$where = [
    'b.dept_id=:dept',
    "b.started_at<(:to::date+interval '1 day')",
    'COALESCE(b.ended_at,now())>:from::date',
    supervisor_agent_sql('a'),
];
if ($selectedAgents) {
    $placeholders = [];
    foreach ($selectedAgents as $index => $agentId) {
        $name = 'agent_' . $index;
        $placeholders[] = ':' . $name;
        $params[$name] = $agentId;
    }
    $where[] = 'b.agent_id IN (' . implode(',', $placeholders) . ')';
}
if ($selectedBreakTypes) {
    $placeholders = [];
    foreach ($selectedBreakTypes as $index => $breakTypeId) {
        $name = 'break_type_' . $index;
        $placeholders[] = ':' . $name;
        $params[$name] = $breakTypeId;
    }
    $where[] = 'b.break_type_id IN (' . implode(',', $placeholders) . ')';
}

$sql = "WITH scoped AS ("
    . "SELECT a.agent_id,a.display_name,a.extension,a.username,"
    . "t.break_type_id,t.code,t.name AS break_name,"
    . "GREATEST(b.started_at,:from::date) AS activity_start,"
    . "LEAST(COALESCE(b.ended_at,now()),:to::date+interval '1 day') AS activity_end "
    . "FROM callcenter_breaks b "
    . "JOIN callcenter_agents a ON a.dept_id=b.dept_id AND a.agent_id=b.agent_id "
    . "JOIN callcenter_break_types t ON t.dept_id=b.dept_id AND t.break_type_id=b.break_type_id "
    . 'WHERE ' . implode(' AND ', $where)
    . "), grouped AS ("
    . "SELECT agent_id,display_name,extension,username,break_type_id,code,break_name,"
    . "count(*)::integer AS break_count,"
    . "COALESCE(sum(EXTRACT(EPOCH FROM (activity_end-activity_start))),0)::bigint AS total_seconds,"
    . "COALESCE(avg(EXTRACT(EPOCH FROM (activity_end-activity_start))),0)::bigint AS average_seconds,"
    . "COALESCE(min(EXTRACT(EPOCH FROM (activity_end-activity_start))),0)::bigint AS min_seconds,"
    . "COALESCE(max(EXTRACT(EPOCH FROM (activity_end-activity_start))),0)::bigint AS max_seconds,"
    . "min(activity_start) AS first_activity,max(activity_end) AS last_activity "
    . "FROM scoped WHERE activity_end>activity_start "
    . "GROUP BY agent_id,display_name,extension,username,break_type_id,code,break_name"
    . "), totals AS (SELECT count(*)::integer AS group_count,count(DISTINCT agent_id)::integer AS agent_count,"
    . "count(DISTINCT break_type_id)::integer AS type_count,COALESCE(sum(break_count),0)::bigint AS break_count,"
    . "COALESCE(sum(total_seconds),0)::bigint AS total_seconds,"
    . "COALESCE(sum(total_seconds)/NULLIF(sum(break_count),0),0)::bigint AS average_seconds FROM grouped) "
    . "SELECT g.*,to_char(g.first_activity,'DD.MM.YYYY HH24:MI:SS') AS first_text,"
    . "to_char(g.last_activity,'DD.MM.YYYY HH24:MI:SS') AS last_text,"
    . "t.group_count AS report_group_count,t.agent_count AS report_agent_count,"
    . "t.type_count AS report_type_count,t.break_count AS report_break_count,"
    . "t.total_seconds AS report_total_seconds,t.average_seconds AS report_average_seconds "
    . "FROM grouped g CROSS JOIN totals t "
    . "ORDER BY g.last_activity DESC,g.agent_id,g.break_type_id LIMIT 100";
$rows = callcenter_rows($sql, $params);

$groupCount = (int) ($rows[0]['report_group_count'] ?? 0);
$agentCount = (int) ($rows[0]['report_agent_count'] ?? 0);
$typeCount = (int) ($rows[0]['report_type_count'] ?? 0);
$breakCount = (int) ($rows[0]['report_break_count'] ?? 0);
$totalSeconds = (int) ($rows[0]['report_total_seconds'] ?? 0);
$averageSeconds = (int) ($rows[0]['report_average_seconds'] ?? 0);

supervisor_page_start('Mola Özet');
?>
<form class="filter-bar operational-report-filter" method="get">
    <label class="filter-group"><span class="filter-label">Başlangıç</span><input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="filter-group"><span class="filter-label">Bitiş</span><input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="filter-group operational-multi"><span class="filter-label">Mola Türleri · Çoklu seçim</span><select name="break_types[]" multiple size="4">
        <?php foreach ($breakTypes as $type): ?><option value="<?= (int) $type['break_type_id'] ?>"<?= in_array((int) $type['break_type_id'], $selectedBreakTypes, true) ? ' selected' : '' ?>><?= e((string) $type['code'] . ' · ' . (string) $type['name'] . (supervisor_bool($type['active']) ? '' : ' · Pasif')) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group operational-multi"><span class="filter-label">Agentlar · Çoklu seçim</span><select name="agents[]" multiple size="4">
        <?php foreach ($agents as $agent): ?><option value="<?= (int) $agent['agent_id'] ?>"<?= in_array((int) $agent['agent_id'], $selectedAgents, true) ? ' selected' : '' ?>><?= e((string) $agent['display_name'] . ' · ' . (string) $agent['extension'] . ((string) $agent['status'] === 'active' ? '' : ' · Pasif')) ?></option><?php endforeach; ?>
    </select></label>
    <button class="btn btn-primary" type="submit">Raporla</button>
    <a class="btn btn-outline" href="molaozet.php">Temizle</a>
</form>

<section class="stats-grid operational-report-stats">
    <article class="stat-card"><div class="stat-label">Agent</div><div class="stat-value"><?= $agentCount ?></div></article>
    <article class="stat-card"><div class="stat-label">Mola Türü</div><div class="stat-value"><?= $typeCount ?></div></article>
    <article class="stat-card"><div class="stat-label">Özet Satırı</div><div class="stat-value"><?= $groupCount ?></div></article>
    <article class="stat-card"><div class="stat-label">Mola Adedi</div><div class="stat-value"><?= $breakCount ?></div></article>
    <article class="stat-card"><div class="stat-label">Toplam Mola</div><div class="stat-value operational-duration"><?= e(supervisor_duration($totalSeconds)) ?></div></article>
    <article class="stat-card"><div class="stat-label">Ortalama Mola</div><div class="stat-value operational-duration"><?= e(supervisor_duration($averageSeconds)) ?></div></article>
</section>

<div class="operational-report-actions">
    <span>Excel ve yazdırma yalnız ekrandaki en fazla 100 özet satırını kapsar.</span>
    <button class="btn btn-success" id="summary-export" type="button">Excel (.xls)</button>
    <button class="btn btn-outline" id="summary-print" type="button">Yazdır</button>
</div>
<div class="operational-report-search"><label for="summary-search">Bu sayfadaki sonuçlarda ara</label><input id="summary-search" type="search" placeholder="Tüm sütunlarda ara…"></div>

<div class="table-container operational-print-area">
    <div class="table-header">Agent + Mola Türü Özeti · En fazla 100 satır</div>
    <table class="operational-report-table" id="summary-table"><thead><tr>
        <th class="operational-sortable" data-sort="0" tabindex="0">Agent Dahili <span>↕</span></th>
        <th class="operational-sortable" data-sort="1" tabindex="0">Agent Adı <span>↕</span></th>
        <th class="operational-sortable" data-sort="2" tabindex="0">Mola Türü <span>↕</span></th>
        <th class="operational-sortable" data-sort="3" tabindex="0">Adet <span>↕</span></th>
        <th class="operational-sortable" data-sort="4" tabindex="0">Toplam <span>↕</span></th>
        <th class="operational-sortable" data-sort="5" tabindex="0">Ortalama <span>↕</span></th>
        <th class="operational-sortable" data-sort="6" tabindex="0">En Kısa <span>↕</span></th>
        <th class="operational-sortable" data-sort="7" tabindex="0">En Uzun <span>↕</span></th>
        <th class="operational-sortable" data-sort="8" tabindex="0">İlk Aktivite <span>↕</span></th>
        <th class="operational-sortable" data-sort="9" tabindex="0">Son Aktivite <span>↕</span></th>
    </tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td data-sort-value="<?= e((string) $row['extension']) ?>"><strong><?= e((string) $row['extension']) ?></strong></td>
            <td data-sort-value="<?= e((string) $row['display_name']) ?>"><?= e((string) $row['display_name']) ?><small class="agent-detail-sub"><?= e((string) $row['username']) ?></small></td>
            <td data-sort-value="<?= e((string) $row['code'] . ' ' . (string) $row['break_name']) ?>"><strong><?= e((string) $row['code']) ?></strong><small class="agent-detail-sub"><?= e((string) $row['break_name']) ?></small></td>
            <td data-sort-value="<?= (int) $row['break_count'] ?>"><?= (int) $row['break_count'] ?></td>
            <td data-sort-value="<?= (int) $row['total_seconds'] ?>"><?= e(supervisor_duration($row['total_seconds'])) ?></td>
            <td data-sort-value="<?= (int) $row['average_seconds'] ?>"><?= e(supervisor_duration($row['average_seconds'])) ?></td>
            <td data-sort-value="<?= (int) $row['min_seconds'] ?>"><?= e(supervisor_duration($row['min_seconds'])) ?></td>
            <td data-sort-value="<?= (int) $row['max_seconds'] ?>"><?= e(supervisor_duration($row['max_seconds'])) ?></td>
            <td class="audit-time" data-sort-value="<?= e((string) $row['first_activity']) ?>"><?= e((string) $row['first_text']) ?></td>
            <td class="audit-time" data-sort-value="<?= e((string) $row['last_activity']) ?>"><?= e((string) $row['last_text']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="empty">Seçilen filtrelerde mola özeti bulunamadı.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<script>
(()=>{
    const table=document.getElementById('summary-table');
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
    document.getElementById('summary-search')?.addEventListener('input',event=>{
        const query=event.currentTarget.value.toLocaleLowerCase('tr-TR');
        [...table.tBodies[0].rows].forEach(row=>{row.hidden=query!==''&&!row.textContent.toLocaleLowerCase('tr-TR').includes(query);});
    });
    document.getElementById('summary-print')?.addEventListener('click',()=>window.print());
    document.getElementById('summary-export')?.addEventListener('click',()=>{
        const escapeHtml=value=>{const node=document.createElement('div');node.textContent=value;return node.innerHTML;};
        const excelSafe=value=>/^[=+\-@]/.test(value.trim())?"'"+value:value;
        const headers=[...table.tHead.rows[0].cells].map(cell=>`<th>${escapeHtml(cell.textContent.replace(/[↕↑↓]/g,'').trim())}</th>`).join('');
        const rows=[...table.tBodies[0].rows].filter(row=>!row.hidden&&row.cells.length>1).map(row=>'<tr>'+[...row.cells].map(cell=>`<td>${escapeHtml(excelSafe(cell.textContent.trim()))}</td>`).join('')+'</tr>').join('');
        const html=`<!doctype html><html><head><meta charset="utf-8"><style>table{border-collapse:collapse}th,td{border:1px solid #999;padding:6px}th{background:#ddd}</style></head><body><h2>Mola Özet Raporu</h2><p><?= e(date('d.m.Y', strtotime($from))) ?> - <?= e(date('d.m.Y', strtotime($to))) ?> · Ekrandaki satırlar</p><table><thead><tr>${headers}</tr></thead><tbody>${rows}</tbody></table></body></html>`;
        const url=URL.createObjectURL(new Blob(['\ufeff',html],{type:'application/vnd.ms-excel;charset=utf-8'}));
        const link=document.createElement('a');
        link.href=url;
        link.download=<?= json_encode('mola_ozet_' . date('Y-m-d') . '.xls', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        document.body.appendChild(link);link.click();link.remove();URL.revokeObjectURL(url);
    });
})();
</script>
<?php supervisor_page_end(); ?>
