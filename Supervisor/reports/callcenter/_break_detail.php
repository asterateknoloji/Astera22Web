<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$from = supervisor_date('from');
$to = supervisor_date('to', $from);
$status = in_array($_GET['status'] ?? '', ['active', 'completed'], true)
    ? (string) $_GET['status'] : '';
$search = trim((string) ($_GET['search'] ?? ''));
$before = max(0, (int) ($_GET['before'] ?? 0));

$agents = callcenter_rows(
    "SELECT a.agent_id,a.display_name,a.extension,a.username FROM callcenter_agents a "
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
    'b.started_at>=:from::date',
    "b.started_at<(:to::date+interval '1 day')",
    supervisor_agent_sql('a'),
];
if ($status === 'active') {
    $where[] = 'b.ended_at IS NULL';
} elseif ($status === 'completed') {
    $where[] = 'b.ended_at IS NOT NULL';
}
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
if ($search !== '') {
    $where[] = '(a.display_name ILIKE :search OR a.extension ILIKE :search OR a.username ILIKE :search)';
    $params['search'] = '%' . $search . '%';
}

$sql = "WITH filtered AS (SELECT b.break_id,b.started_at,b.ended_at,"
    . "a.agent_id,a.display_name,a.extension,a.username,t.break_type_id,t.code,t.name AS break_name,"
    . "GREATEST(0,EXTRACT(EPOCH FROM (COALESCE(b.ended_at,now())-b.started_at)))::bigint AS duration_seconds "
    . "FROM callcenter_breaks b "
    . "JOIN callcenter_agents a ON a.dept_id=b.dept_id AND a.agent_id=b.agent_id "
    . "JOIN callcenter_break_types t ON t.dept_id=b.dept_id AND t.break_type_id=b.break_type_id "
    . 'WHERE ' . implode(' AND ', $where)
    . "), ranked AS (SELECT *,dense_rank() OVER (ORDER BY agent_id) AS agent_rank,"
    . "dense_rank() OVER (ORDER BY break_type_id) AS type_rank FROM filtered), "
    . "with_totals AS (SELECT *,count(*) OVER()::integer AS total_count,"
    . "count(*) FILTER (WHERE ended_at IS NULL) OVER()::integer AS active_count,"
    . "COALESCE(sum(duration_seconds) OVER(),0)::bigint AS total_duration,"
    . "COALESCE(avg(duration_seconds) OVER(),0)::bigint AS average_duration,"
    . "max(agent_rank) OVER()::integer AS unique_agent_count,"
    . "max(type_rank) OVER()::integer AS unique_type_count FROM ranked) "
    . "SELECT *,to_char(started_at,'DD.MM.YYYY HH24:MI:SS') AS started_text,"
    . "to_char(ended_at,'DD.MM.YYYY HH24:MI:SS') AS ended_text FROM with_totals";
if ($before > 0) {
    $sql .= ' WHERE break_id<:before';
    $params['before'] = $before;
}
$sql .= ' ORDER BY break_id DESC LIMIT 101';

$rows = callcenter_rows($sql, $params);
$hasNext = count($rows) > 100;
if ($hasNext) {
    array_pop($rows);
}

$uniqueAgents = (int) ($rows[0]['unique_agent_count'] ?? 0);
$uniqueTypes = (int) ($rows[0]['unique_type_count'] ?? 0);
$total = (int) ($rows[0]['total_count'] ?? 0);
$active = (int) ($rows[0]['active_count'] ?? 0);
$totalDuration = (int) ($rows[0]['total_duration'] ?? 0);
$averageDuration = (int) ($rows[0]['average_duration'] ?? 0);

supervisor_page_start('Mola Detay');
?>
<form class="filter-bar operational-report-filter break-detail-filter" method="get">
    <label class="filter-group"><span class="filter-label">Başlangıç</span><input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="filter-group"><span class="filter-label">Bitiş</span><input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="filter-group"><span class="filter-label">Durum</span><select name="status">
        <option value="">Tümü</option>
        <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Devam Ediyor</option>
        <option value="completed"<?= $status === 'completed' ? ' selected' : '' ?>>Tamamlandı</option>
    </select></label>
    <label class="filter-group operational-multi"><span class="filter-label">Mola Türleri · Çoklu seçim</span><select name="break_types[]" multiple size="4">
        <?php foreach ($breakTypes as $type): ?><option value="<?= (int) $type['break_type_id'] ?>"<?= in_array((int) $type['break_type_id'], $selectedBreakTypes, true) ? ' selected' : '' ?>><?= e((string) $type['code'] . ' · ' . (string) $type['name'] . (supervisor_bool($type['active']) ? '' : ' · Pasif')) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group operational-multi"><span class="filter-label">Agentlar · Çoklu seçim</span><select name="agents[]" multiple size="4">
        <?php foreach ($agents as $agent): ?><option value="<?= (int) $agent['agent_id'] ?>"<?= in_array((int) $agent['agent_id'], $selectedAgents, true) ? ' selected' : '' ?>><?= e((string) $agent['display_name'] . ' · ' . (string) $agent['extension']) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group operational-search-filter"><span class="filter-label">Agent ara</span><input name="search" value="<?= e($search) ?>" placeholder="Ad, dahili veya kullanıcı"></label>
    <button class="btn btn-primary" type="submit">Raporla</button>
    <a class="btn btn-outline" href="moladetay.php">Temizle</a>
</form>

<section class="stats-grid operational-report-stats break-detail-stats">
    <article class="stat-card"><div class="stat-label">Benzersiz Agent</div><div class="stat-value"><?= $uniqueAgents ?></div></article>
    <article class="stat-card"><div class="stat-label">Mola Türü</div><div class="stat-value"><?= $uniqueTypes ?></div></article>
    <article class="stat-card"><div class="stat-label">Toplam Kayıt</div><div class="stat-value"><?= $total ?></div></article>
    <article class="stat-card"><div class="stat-label">Aktif Mola</div><div class="stat-value"><?= $active ?></div></article>
    <article class="stat-card"><div class="stat-label">Toplam Süre</div><div class="stat-value operational-duration"><?= e(supervisor_duration($totalDuration)) ?></div></article>
    <article class="stat-card"><div class="stat-label">Ortalama Süre</div><div class="stat-value operational-duration"><?= e(supervisor_duration($averageDuration)) ?></div></article>
</section>

<div class="operational-report-actions">
    <span>Excel ve yazdırma yalnız bu sayfada yüklenen en fazla 100 kaydı kapsar.</span>
    <button class="btn btn-success" id="break-export" type="button">Excel (.xls)</button>
    <button class="btn btn-outline" id="break-print" type="button">Yazdır</button>
</div>
<div class="operational-report-search"><label for="break-table-search">Bu sayfadaki sonuçlarda ara</label><input id="break-table-search" type="search" placeholder="Tüm sütunlarda ara…"></div>

<div class="table-container operational-print-area">
    <div class="table-header">Mola Kayıtları · En fazla 100 kayıt</div>
    <table class="operational-report-table break-detail-table" id="break-detail-table"><thead><tr>
        <th class="operational-sortable" data-sort="0" tabindex="0">Break ID <span>↕</span></th>
        <th class="operational-sortable" data-sort="1" tabindex="0">Agent Dahili <span>↕</span></th>
        <th class="operational-sortable" data-sort="2" tabindex="0">Agent Adı <span>↕</span></th>
        <th class="operational-sortable" data-sort="3" tabindex="0">Mola Kodu / Adı <span>↕</span></th>
        <th class="operational-sortable" data-sort="4" tabindex="0">Başlangıç <span>↕</span></th>
        <th class="operational-sortable" data-sort="5" tabindex="0">Bitiş <span>↕</span></th>
        <th class="operational-sortable" data-sort="6" tabindex="0">Süre <span>↕</span></th>
        <th class="operational-sortable" data-sort="7" tabindex="0">Durum <span>↕</span></th>
    </tr></thead><tbody>
    <?php foreach ($rows as $row): $isActive = $row['ended_at'] === null; ?>
        <tr class="<?= $isActive ? 'operational-active-row' : '' ?>">
            <td data-sort-value="<?= (int) $row['break_id'] ?>"><code><?= (int) $row['break_id'] ?></code></td>
            <td data-sort-value="<?= e((string) $row['extension']) ?>"><strong><?= e((string) $row['extension']) ?></strong></td>
            <td data-sort-value="<?= e((string) $row['display_name']) ?>"><?= e((string) $row['display_name']) ?><small class="agent-detail-sub"><?= e((string) $row['username']) ?></small></td>
            <td data-sort-value="<?= e((string) $row['code'] . ' ' . (string) $row['break_name']) ?>"><strong><?= e((string) $row['code']) ?></strong><small class="agent-detail-sub"><?= e((string) $row['break_name']) ?></small></td>
            <td class="audit-time" data-sort-value="<?= e((string) $row['started_at']) ?>"><?= e((string) $row['started_text']) ?></td>
            <td class="audit-time" data-sort-value="<?= e((string) ($row['ended_at'] ?? '')) ?>"><?= $row['ended_at'] !== null ? e((string) $row['ended_text']) : '—' ?></td>
            <td data-sort-value="<?= (int) $row['duration_seconds'] ?>"><?= e(supervisor_duration($row['duration_seconds'])) ?></td>
            <td data-sort-value="<?= $isActive ? '1' : '0' ?>"><span class="badge <?= $isActive ? 'badge-warning' : 'badge-success' ?>"><?= $isActive ? 'Devam Ediyor' : 'Tamamlandı' ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">Seçilen filtrelerde mola kaydı bulunamadı.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<?php if ($hasNext && $rows):
    $nextQuery = $_GET;
    $nextQuery['from'] = $from;
    $nextQuery['to'] = $to;
    $nextQuery['before'] = (int) end($rows)['break_id'];
?>
<div class="audit-pagination operational-no-print"><a class="btn btn-outline" href="?<?= e(http_build_query($nextQuery)) ?>">Daha Eski Molalar →</a></div>
<?php endif; ?>
<script>
(()=>{
    const table=document.getElementById('break-detail-table');
    const collator=new Intl.Collator('tr-TR',{numeric:true,sensitivity:'base'});
    const directions={};
    const sortBy=header=>{
        const index=Number(header.dataset.sort);
        const direction=directions[index]==='asc'?'desc':'asc';
        directions[index]=direction;
        const body=table.tBodies[0];
        const rows=[...body.rows].filter(row=>row.cells.length>1);
        rows.sort((left,right)=>{
            const a=left.cells[index].dataset.sortValue??left.cells[index].textContent.trim();
            const b=right.cells[index].dataset.sortValue??right.cells[index].textContent.trim();
            return collator.compare(a,b)*(direction==='asc'?1:-1);
        });
        rows.forEach(row=>body.appendChild(row));
        table.querySelectorAll('.operational-sortable span').forEach(icon=>icon.textContent='↕');
        header.querySelector('span').textContent=direction==='asc'?'↑':'↓';
    };
    table.querySelectorAll('.operational-sortable').forEach(header=>{
        header.addEventListener('click',()=>sortBy(header));
        header.addEventListener('keydown',event=>{
            if(event.key==='Enter'||event.key===' '){event.preventDefault();sortBy(header);}
        });
    });
    document.getElementById('break-table-search')?.addEventListener('input',event=>{
        const query=event.currentTarget.value.toLocaleLowerCase('tr-TR');
        [...table.tBodies[0].rows].forEach(row=>{
            row.hidden=query!==''&&!row.textContent.toLocaleLowerCase('tr-TR').includes(query);
        });
    });
    document.getElementById('break-print')?.addEventListener('click',()=>window.print());
    document.getElementById('break-export')?.addEventListener('click',()=>{
        const escapeHtml=value=>{
            const node=document.createElement('div');
            node.textContent=value;
            return node.innerHTML;
        };
        const excelSafe=value=>/^[=+\-@]/.test(value.trim())?"'"+value:value;
        const headers=[...table.tHead.rows[0].cells].map(cell=>`<th>${escapeHtml(cell.textContent.replace(/[↕↑↓]/g,'').trim())}</th>`).join('');
        const rows=[...table.tBodies[0].rows].filter(row=>!row.hidden&&row.cells.length>1).map(row=>
            '<tr>'+[...row.cells].map(cell=>`<td>${escapeHtml(excelSafe(cell.textContent.trim()))}</td>`).join('')+'</tr>'
        ).join('');
        const html=`<!doctype html><html><head><meta charset="utf-8"><style>table{border-collapse:collapse}th,td{border:1px solid #999;padding:6px}th{background:#ddd}</style></head><body><h2>Mola Detay Raporu</h2><p><?= e(date('d.m.Y', strtotime($from))) ?> - <?= e(date('d.m.Y', strtotime($to))) ?> · Bu sayfadaki kayıtlar</p><table><thead><tr>${headers}</tr></thead><tbody>${rows}</tbody></table></body></html>`;
        const url=URL.createObjectURL(new Blob(['\ufeff',html],{type:'application/vnd.ms-excel;charset=utf-8'}));
        const link=document.createElement('a');
        link.href=url;
        link.download=<?= json_encode('mola_detay_' . date('Y-m-d') . '.xls', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    });
})();
</script>
<?php supervisor_page_end(); ?>
