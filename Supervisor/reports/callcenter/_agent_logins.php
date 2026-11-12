<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

$from = supervisor_date('from');
$to = supervisor_date('to', $from);
$status = in_array($_GET['status'] ?? '', ['active', 'completed'], true)
    ? (string) $_GET['status'] : '';
$search = trim((string) ($_GET['search'] ?? ''));
$before = max(0, (int) ($_GET['before'] ?? 0));

$requestedQueues = array_values(array_unique(array_filter(array_map(
    static fn(mixed $value): string => trim((string) $value),
    (array) ($_GET['queues'] ?? [])
))));
$selectedQueues = array_values(array_intersect($queueIds, $requestedQueues));

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

$queueNames = [];
foreach ($queues as $queue) {
    $queueNames[(string) ($queue['id'] ?? '')] = (string) ($queue['name'] ?? $queue['id'] ?? '');
}

$params = ['dept' => $deptId, 'from' => $from, 'to' => $to];
$where = [
    's.dept_id=:dept',
    's.started_at>=:from::date',
    "s.started_at<(:to::date+interval '1 day')",
    supervisor_agent_sql('a'),
];
if ($status === 'active') {
    $where[] = 's.ended_at IS NULL';
} elseif ($status === 'completed') {
    $where[] = 's.ended_at IS NOT NULL';
}
if ($selectedAgents) {
    $placeholders = [];
    foreach ($selectedAgents as $index => $agentId) {
        $name = 'agent_' . $index;
        $placeholders[] = ':' . $name;
        $params[$name] = $agentId;
    }
    $where[] = 's.agent_id IN (' . implode(',', $placeholders) . ')';
}
if ($selectedQueues) {
    $placeholders = [];
    foreach ($selectedQueues as $index => $queueId) {
        $name = 'queue_' . $index;
        $placeholders[] = ':' . $name;
        $params[$name] = $queueId;
    }
    $where[] = 'EXISTS (SELECT 1 FROM callcenter_agent_queue_assignments fq '
        . 'WHERE fq.dept_id=s.dept_id AND fq.agent_id=s.agent_id AND fq.active '
        . 'AND fq.queue_id IN (' . implode(',', $placeholders) . '))';
}
if ($search !== '') {
    $where[] = '(a.display_name ILIKE :search OR a.extension ILIKE :search OR a.username ILIKE :search)';
    $params['search'] = '%' . $search . '%';
}

$sql = "WITH filtered AS (SELECT s.session_id,s.started_at,s.ended_at,s.end_reason,"
    . "a.agent_id,a.display_name,a.extension,a.username,"
    . "COALESCE(q.queue_ids,'') AS queue_ids,"
    . "GREATEST(0,EXTRACT(EPOCH FROM (COALESCE(s.ended_at,now())-s.started_at)))::bigint AS duration_seconds "
    . "FROM callcenter_agent_sessions s "
    . "JOIN callcenter_agents a ON a.dept_id=s.dept_id AND a.agent_id=s.agent_id "
    . "LEFT JOIN LATERAL (SELECT string_agg(DISTINCT aq.queue_id, ', ' ORDER BY aq.queue_id) AS queue_ids "
    . "FROM callcenter_agent_queue_assignments aq WHERE aq.dept_id=s.dept_id "
    . "AND aq.agent_id=s.agent_id AND aq.active AND " . supervisor_queue_sql('aq.queue_id') . ") q ON TRUE "
    . 'WHERE ' . implode(' AND ', $where)
    . "), with_totals AS (SELECT *,count(*) OVER()::integer AS total_count,"
    . "count(*) FILTER (WHERE ended_at IS NOT NULL) OVER()::integer AS completed_count,"
    . "count(*) FILTER (WHERE ended_at IS NULL) OVER()::integer AS active_count,"
    . "COALESCE(sum(duration_seconds) OVER(),0)::bigint AS total_duration "
    . 'FROM filtered) SELECT *,'
    . "to_char(started_at,'DD.MM.YYYY HH24:MI:SS') AS started_text,"
    . "to_char(ended_at,'DD.MM.YYYY HH24:MI:SS') AS ended_text FROM with_totals";
if ($before > 0) {
    $sql .= ' WHERE session_id<:before';
    $params['before'] = $before;
}
$sql .= ' ORDER BY session_id DESC LIMIT 101';

$rows = callcenter_rows($sql, $params);
$hasNext = count($rows) > 100;
if ($hasNext) {
    array_pop($rows);
}

$total = (int) ($rows[0]['total_count'] ?? 0);
$completed = (int) ($rows[0]['completed_count'] ?? 0);
$active = (int) ($rows[0]['active_count'] ?? 0);
$totalDuration = (int) ($rows[0]['total_duration'] ?? 0);
$reasonLabels = [
    'logout' => 'Çıkış',
    'timeout' => 'Zaman aşımı',
    'admin' => 'Yönetici',
    'relogin' => 'Yeniden giriş',
];

supervisor_page_start('Agent Login / Logout');
?>
<form class="filter-bar operational-report-filter" method="get">
    <label class="filter-group"><span class="filter-label">Başlangıç</span><input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="filter-group"><span class="filter-label">Bitiş</span><input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="filter-group"><span class="filter-label">Durum</span><select name="status">
        <option value="">Tümü</option>
        <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Aktif Oturum</option>
        <option value="completed"<?= $status === 'completed' ? ' selected' : '' ?>>Tamamlanmış Oturum</option>
    </select></label>
    <label class="filter-group operational-multi"><span class="filter-label">Kuyruklar · Çoklu seçim</span><select name="queues[]" multiple size="4">
        <?php foreach ($queues as $queue): $queueId = (string) ($queue['id'] ?? ''); ?>
            <option value="<?= e($queueId) ?>"<?= in_array($queueId, $selectedQueues, true) ? ' selected' : '' ?>><?= e($queueId . ' · ' . (string) ($queue['name'] ?? $queueId)) ?></option>
        <?php endforeach; ?>
    </select></label>
    <label class="filter-group operational-multi"><span class="filter-label">Agentlar · Çoklu seçim</span><select name="agents[]" multiple size="4">
        <?php foreach ($agents as $agent): ?><option value="<?= (int) $agent['agent_id'] ?>"<?= in_array((int) $agent['agent_id'], $selectedAgents, true) ? ' selected' : '' ?>><?= e((string) $agent['display_name'] . ' · ' . (string) $agent['extension']) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group operational-search-filter"><span class="filter-label">Agent ara</span><input name="search" value="<?= e($search) ?>" placeholder="Ad, dahili veya kullanıcı"></label>
    <button class="btn btn-primary" type="submit">Raporla</button>
    <a class="btn btn-outline" href="agent_logins.php">Temizle</a>
</form>

<section class="stats-grid operational-report-stats">
    <article class="stat-card"><div class="stat-label">Toplam Oturum</div><div class="stat-value"><?= $total ?></div></article>
    <article class="stat-card"><div class="stat-label">Tamamlanan</div><div class="stat-value"><?= $completed ?></div></article>
    <article class="stat-card"><div class="stat-label">Aktif</div><div class="stat-value"><?= $active ?></div></article>
    <article class="stat-card"><div class="stat-label">Toplam Oturum Süresi</div><div class="stat-value operational-duration"><?= e(supervisor_duration($totalDuration)) ?></div></article>
</section>

<div class="operational-report-actions">
    <span>Excel ve yazdırma yalnız bu sayfada görüntülenen en fazla 100 kaydı kapsar.</span>
    <button class="btn btn-success" id="login-export" type="button">Excel (.xls)</button>
    <button class="btn btn-outline" id="login-print" type="button">Yazdır</button>
</div>
<div class="operational-report-search"><label for="login-table-search">Bu sayfadaki sonuçlarda ara</label><input id="login-table-search" type="search" placeholder="Tüm sütunlarda ara…"></div>

<div class="table-container operational-print-area">
    <div class="table-header">Oturum Detayları · En fazla 100 kayıt</div>
    <table class="operational-report-table login-report-table" id="login-report-table"><thead><tr>
        <th>Session ID</th><th>Agent Dahili</th><th>Agent Adı</th><th>Atanmış / Seçili Kuyruklar</th>
        <th>Durum</th><th>Login Tarihi</th><th>Logout Tarihi</th><th>Süre</th><th>Çıkış Nedeni</th>
    </tr></thead><tbody>
    <?php foreach ($rows as $row):
        $isActive = $row['ended_at'] === null;
        $queueLabels = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $row['queue_ids']))) as $queueId) {
            $queueLabels[] = isset($queueNames[$queueId]) && $queueNames[$queueId] !== $queueId
                ? $queueId . ' · ' . $queueNames[$queueId] : $queueId;
        }
    ?>
        <tr class="<?= $isActive ? 'operational-active-row' : '' ?>">
            <td><code><?= (int) $row['session_id'] ?></code></td>
            <td><strong><?= e((string) $row['extension']) ?></strong></td>
            <td><?= e((string) $row['display_name']) ?><small class="agent-detail-sub"><?= e((string) $row['username']) ?></small></td>
            <td><?= e($queueLabels ? implode(', ', $queueLabels) : '—') ?></td>
            <td><span class="badge <?= $isActive ? 'badge-warning' : 'badge-success' ?>"><?= $isActive ? 'Aktif' : 'Tamamlandı' ?></span></td>
            <td class="audit-time"><?= e((string) $row['started_text']) ?></td>
            <td class="audit-time"><?= $row['ended_at'] !== null ? e((string) $row['ended_text']) : '—' ?></td>
            <td><?= e(supervisor_duration($row['duration_seconds'])) ?></td>
            <td><?= e($isActive ? '—' : ($reasonLabels[(string) $row['end_reason']] ?? ((string) $row['end_reason'] ?: 'Belirtilmedi'))) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="empty">Seçilen filtrelerde oturum bulunamadı.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<?php if ($hasNext && $rows):
    $nextQuery = $_GET;
    $nextQuery['from'] = $from;
    $nextQuery['to'] = $to;
    $nextQuery['before'] = (int) end($rows)['session_id'];
?>
<div class="audit-pagination operational-no-print"><a class="btn btn-outline" href="?<?= e(http_build_query($nextQuery)) ?>">Daha Eski Oturumlar →</a></div>
<?php endif; ?>
<script>
document.getElementById('login-table-search')?.addEventListener('input',event=>{
    const query=event.currentTarget.value.toLocaleLowerCase('tr-TR');
    document.querySelectorAll('#login-report-table tbody tr').forEach(row=>{
        row.hidden=query!==''&&!row.textContent.toLocaleLowerCase('tr-TR').includes(query);
    });
});
document.getElementById('login-print')?.addEventListener('click',()=>window.print());
document.getElementById('login-export')?.addEventListener('click',()=>{
    const table=document.getElementById('login-report-table');
    const escapeHtml=value=>{const node=document.createElement('div');node.textContent=value;return node.innerHTML;};
    const excelSafe=value=>/^[=+\-@]/.test(value.trim())?"'"+value:value;
    const headers=[...table.tHead.rows[0].cells].map(cell=>`<th>${escapeHtml(cell.textContent.trim())}</th>`).join('');
    const rows=[...table.tBodies[0].rows].filter(row=>!row.hidden&&row.cells.length>1&&!row.querySelector('.empty')).map(row=>
        '<tr>'+[...row.cells].map(cell=>`<td>${escapeHtml(excelSafe(cell.textContent.trim()))}</td>`).join('')+'</tr>'
    ).join('');
    const html=`<!doctype html><html><head><meta charset="utf-8"></head><body><h2>Agent Login / Logout Raporu</h2><p><?= e(date('d.m.Y', strtotime($from))) ?> - <?= e(date('d.m.Y', strtotime($to))) ?> · Bu sayfadaki kayıtlar</p><table border="1"><thead><tr>${headers}</tr></thead><tbody>${rows}</tbody></table></body></html>`;
    const url=URL.createObjectURL(new Blob(['\ufeff',html],{type:'application/vnd.ms-excel;charset=utf-8'}));
    const link=document.createElement('a');link.href=url;link.download=<?= json_encode('agent_login_logout_' . date('Y-m-d') . '.xls', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    document.body.appendChild(link);link.click();link.remove();URL.revokeObjectURL(url);
});
</script>
<?php supervisor_page_end(); ?>
