<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

function queue_detail_mask_phone(string $number): string
{
    $number = trim($number);
    $length = strlen($number);
    if ($length <= 5) {
        return $number;
    }
    return substr($number, 0, 3) . str_repeat('*', max(3, $length - 7)) . substr($number, -4);
}

$from = supervisor_date('from');
$to = supervisor_date('to', $from);
$status = in_array($_GET['status'] ?? '', ['ABANDON', 'COMPLETEAGENT', 'COMPLETECALLER'], true)
    ? (string) $_GET['status'] : '';
$phone = preg_replace('/\D+/', '', (string) ($_GET['phone'] ?? '')) ?? '';
$callId = trim((string) ($_GET['callid'] ?? ''));
$callbackCheck = ($_GET['callback_check'] ?? '') === '1';
$recordedOnly = ($_GET['recorded'] ?? '') === '1';
$cursorTime = trim((string) ($_GET['cursor_time'] ?? ''));
$cursorId = max(0, (int) ($_GET['cursor_id'] ?? 0));
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/', $cursorTime)) {
    $cursorTime = '';
    $cursorId = 0;
}

$requestedQueues = array_values(array_unique(array_filter(array_map(
    static fn(mixed $value): string => trim((string) $value),
    (array) ($_GET['queues'] ?? [])
))));
$selectedQueues = array_values(array_intersect($queueIds, $requestedQueues));

$agents = callcenter_rows(
    'SELECT a.agent_id,a.display_name,a.extension FROM callcenter_agents a '
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
$terminalWhere = [
    "q.time>=:from::date",
    "q.time<(:to::date+interval '1 day')",
    "q.event IN ('ABANDON','COMPLETEAGENT','COMPLETECALLER')",
    "q.callid NOT IN ('','NONE','MANAGER','REALTIME')",
    supervisor_queue_sql('q.queuename'),
];
if ($selectedQueues) {
    $queueParams = [];
    foreach ($selectedQueues as $index => $queue) {
        $name = 'queue_' . $index;
        $queueParams[] = ':' . $name;
        $params[$name] = $queue;
    }
    $terminalWhere[] = 'q.queuename IN (' . implode(',', $queueParams) . ')';
}

$filteredWhere = [];
if ($status !== '') {
    $filteredWhere[] = 'e.status=:status';
    $params['status'] = $status;
}
if ($phone !== '') {
    $filteredWhere[] = 'e.customer_normalized LIKE :phone';
    $params['phone'] = '%' . $phone . '%';
}
if ($callId !== '') {
    $filteredWhere[] = 'e.callid LIKE :callid';
    $params['callid'] = '%' . $callId . '%';
}
if ($selectedAgents) {
    $agentParams = [];
    foreach ($selectedAgents as $index => $agentId) {
        $name = 'agent_' . $index;
        $agentParams[] = ':' . $name;
        $params[$name] = $agentId;
    }
    $filteredWhere[] = 'e.agent_id IN (' . implode(',', $agentParams) . ')';
}
if ($recordedOnly) {
    $filteredWhere[] = 'e.recording_id IS NOT NULL';
}
$filteredSql = $filteredWhere ? 'WHERE ' . implode(' AND ', $filteredWhere) : '';

$parsedAgent = "trim(regexp_replace(regexp_replace(COALESCE(NULLIF(cn.agent,'NONE'),"
    . "NULLIF(t.agent,'NONE'),''),'^(PJSIP/|SIP/|Local/|Agent[ ]+)','','i'),'@.*$','','g'))";
$sourceToken = "split_part(split_part(COALESCE(cd.channel,''),'/',2),'-',1)";
$recordingJoin = "LEFT JOIN LATERAL (SELECT cr.id FROM cdr cd "
    . "JOIN call_recordings cr ON cr.dept_id=cd.dept_id AND ("
    . "cr.object_key=cd.recordingfile OR (cr.linkedid<>'' AND cr.linkedid=cd.linkedid) "
    . "OR (cr.uniqueid<>'' AND cr.uniqueid=cd.uniqueid)) "
    . "WHERE cd.dept_id=:dept AND d.callid IN (cd.linkedid,cd.uniqueid) "
    . "AND a.agent_id IS NOT NULL AND (cd.src=a.extension OR {$sourceToken} IN (a.extension,a.dept_id||'_'||a.extension) "
    . "OR split_part(split_part(COALESCE(cd.dstchannel,''),'/',2),'-',1) IN (a.extension,a.dept_id||'_'||a.extension)) "
    . "ORDER BY (cr.object_key=cd.recordingfile) DESC,cr.recorded_at DESC NULLS LAST,cr.id DESC LIMIT 1) rec ON TRUE";

$callbackJoin = '';
$callbackSelect = 'NULL::varchar AS callback_disposition,NULL::timestamp AS callback_time,'
    . 'NULL::varchar AS callback_agent_name,NULL::varchar AS callback_extension,';
if ($callbackCheck) {
    $callbackJoin = "LEFT JOIN LATERAL (SELECT cd.disposition,cd.calldate,ca.display_name AS agent_name,"
        . "ca.extension FROM cdr cd "
        . "JOIN allowed_agents ca ON (cd.src=ca.extension OR "
        . "split_part(split_part(COALESCE(cd.channel,''),'/',2),'-',1) IN (ca.extension,ca.dept_id||'_'||ca.extension)) "
        . "WHERE d.status='ABANDON' AND cd.dept_id=:dept AND d.customer_normalized<>'' "
        . "AND cd.dst_normalized=d.customer_normalized AND cd.calldate>d.call_date "
        . "ORDER BY cd.calldate,cd.id LIMIT 1) cb ON TRUE";
    $callbackSelect = 'cb.disposition AS callback_disposition,cb.calldate AS callback_time,'
        . 'cb.agent_name AS callback_agent_name,cb.extension AS callback_extension,';
}

$sql = "WITH allowed_agents AS ("
    . "SELECT a.agent_id,a.dept_id,a.display_name,a.extension FROM callcenter_agents a "
    . "WHERE a.dept_id=:dept AND a.status='active' AND " . supervisor_agent_sql('a')
    . "), ranked_terminal AS (SELECT q.*,row_number() OVER (PARTITION BY q.callid ORDER BY "
    . "CASE q.event WHEN 'ABANDON' THEN 1 WHEN 'COMPLETEAGENT' THEN 2 ELSE 3 END,q.time DESC,q.id DESC) AS priority_rank "
    . 'FROM queue_log q WHERE ' . implode(' AND ', $terminalWhere)
    . "), calls AS (SELECT callid,max(id) AS max_log_id,max(id) FILTER (WHERE priority_rank=1) AS terminal_id "
    . 'FROM ranked_terminal GROUP BY callid), details AS ('
    . "SELECT c.callid,c.max_log_id,t.queuename,t.event AS status,COALESCE(en.time,t.time) AS call_date,"
    . "COALESCE(en.data2,'') AS customer_phone,regexp_replace(COALESCE(en.data2,''),'[^0-9]','','g') AS customer_normalized,"
    . "CASE WHEN en.data3~'^[0-9]+$' THEN en.data3::integer END AS entry_position,"
    . "CASE WHEN t.event='ABANDON' AND t.data1~'^[0-9]+$' THEN t.data1::integer END AS exit_position,"
    . "CASE WHEN t.event='ABANDON' AND t.data3~'^[0-9]+$' THEN t.data3::integer "
    . "WHEN t.event<>'ABANDON' AND t.data1~'^[0-9]+$' THEN t.data1::integer ELSE 0 END AS wait_seconds,"
    . "CASE WHEN t.event<>'ABANDON' AND t.data2~'^[0-9]+$' THEN t.data2::integer ELSE 0 END AS talk_seconds,"
    . "{$parsedAgent} AS agent_extension "
    . "FROM calls c JOIN queue_log t ON t.id=c.terminal_id "
    . "LEFT JOIN LATERAL (SELECT ql.time,ql.data2,ql.data3 FROM queue_log ql "
    . "WHERE ql.callid=c.callid AND ql.queuename=t.queuename AND ql.event='ENTERQUEUE' AND "
    . supervisor_queue_sql('ql.queuename') . ' ORDER BY ql.time,ql.id LIMIT 1) en ON TRUE '
    . "LEFT JOIN LATERAL (SELECT ql.agent FROM queue_log ql WHERE ql.callid=c.callid "
    . "AND ql.queuename=t.queuename AND ql.event='CONNECT' AND " . supervisor_queue_sql('ql.queuename')
    . " ORDER BY ql.time DESC,ql.id DESC LIMIT 1) cn ON TRUE), base AS ("
    . "SELECT d.*,a.agent_id,a.display_name AS agent_name,a.extension,rec.id AS recording_id,"
    . $callbackSelect
    . "to_char(d.call_date,'DD.MM.YYYY HH24:MI:SS') AS call_time "
    . "FROM details d LEFT JOIN allowed_agents a ON d.agent_extension IN (a.extension,a.dept_id||'_'||a.extension) "
    . $recordingJoin . ' ' . $callbackJoin
    . " WHERE d.status='ABANDON' OR a.agent_id IS NOT NULL), filtered AS (SELECT e.* FROM base e {$filteredSql}), "
    . "with_totals AS (SELECT *,count(*) OVER()::integer AS total_count,"
    . "count(*) FILTER (WHERE status='ABANDON') OVER()::integer AS abandoned_count,"
    . "count(*) FILTER (WHERE status<>'ABANDON') OVER()::integer AS answered_count,"
    . "count(*) FILTER (WHERE status='ABANDON' AND callback_time IS NOT NULL) OVER()::integer AS called_back_count,"
    . "count(*) FILTER (WHERE status='ABANDON' AND callback_time IS NULL) OVER()::integer AS not_called_back_count "
    . "FROM filtered) SELECT * FROM with_totals";
if ($cursorTime !== '' && $cursorId > 0) {
    $sql .= ' WHERE (call_date,max_log_id)<(:cursor_time::timestamp,:cursor_id)';
    $params['cursor_time'] = $cursorTime;
    $params['cursor_id'] = $cursorId;
}
$sql .= ' ORDER BY call_date DESC,max_log_id DESC LIMIT 101';

$rows = callcenter_rows($sql, $params);
$hasNext = count($rows) > 100;
if ($hasNext) {
    array_pop($rows);
}

$total = (int) ($rows[0]['total_count'] ?? 0);
$answered = (int) ($rows[0]['answered_count'] ?? 0);
$abandoned = (int) ($rows[0]['abandoned_count'] ?? 0);
$calledBack = (int) ($rows[0]['called_back_count'] ?? 0);
$notCalledBack = (int) ($rows[0]['not_called_back_count'] ?? 0);
$canPlay = callcenter_supervisor_has_permission($supervisor, 'recording_play');
$canDownload = callcenter_supervisor_has_permission($supervisor, 'recording_download');
$canSeePhone = callcenter_supervisor_has_permission($supervisor, 'phone_unmasked');
$statusLabels = [
    'ABANDON' => 'Kaçan',
    'COMPLETEAGENT' => 'Agent Kapattı',
    'COMPLETECALLER' => 'Arayan Kapattı',
];
$dispositionLabels = [
    'ANSWERED' => 'Cevaplandı',
    'NO ANSWER' => 'Cevapsız',
    'BUSY' => 'Meşgul',
    'FAILED' => 'Başarısız',
    'CONGESTION' => 'Yoğunluk',
];

supervisor_page_start('Kuyruk Detay');
?>
<form class="filter-bar queue-detail-filter" method="get">
    <label class="filter-group"><span class="filter-label">Başlangıç</span><input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="filter-group"><span class="filter-label">Bitiş</span><input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="filter-group"><span class="filter-label">Durum</span><select name="status">
        <option value="">Tümü</option>
        <?php foreach ($statusLabels as $value => $label): ?><option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group queue-filter"><span class="filter-label">Kuyruklar · Çoklu seçim</span><select name="queues[]" multiple size="4">
        <?php foreach ($queues as $queue): $queueValue = (string) ($queue['id'] ?? ''); ?>
            <option value="<?= e($queueValue) ?>"<?= in_array($queueValue, $selectedQueues, true) ? ' selected' : '' ?>><?= e($queueValue . ' · ' . (string) ($queue['name'] ?? $queueValue)) ?></option>
        <?php endforeach; ?>
    </select></label>
    <label class="filter-group agent-filter"><span class="filter-label">Agentlar · Çoklu seçim</span><select name="agents[]" multiple size="4">
        <?php foreach ($agents as $agent): ?><option value="<?= (int) $agent['agent_id'] ?>"<?= in_array((int) $agent['agent_id'], $selectedAgents, true) ? ' selected' : '' ?>><?= e((string) $agent['display_name'] . ' · ' . (string) $agent['extension']) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group"><span class="filter-label">Müşteri Numarası</span><input name="phone" inputmode="numeric" value="<?= e($phone) ?>" placeholder="Normalize numara"></label>
    <label class="filter-group"><span class="filter-label">Call ID</span><input name="callid" value="<?= e($callId) ?>" placeholder="Call ID ara"></label>
    <label class="choice-row queue-detail-choice"><input type="checkbox" name="callback_check" value="1"<?= $callbackCheck ? ' checked' : '' ?>><span><strong>Geri aramayı kontrol et</strong><small>Yalnız işaretliyken hesaplanır</small></span></label>
    <label class="choice-row queue-detail-choice"><input type="checkbox" name="recorded" value="1"<?= $recordedOnly ? ' checked' : '' ?>><span><strong>Yalnız ses kayıtlı</strong></span></label>
    <button class="btn btn-primary" type="submit">Raporla</button>
    <a class="btn btn-outline" href="kuyruk_detay.php">Temizle</a>
</form>

<section class="stats-grid queue-detail-stats">
    <article class="stat-card"><div class="stat-label">Toplam Benzersiz</div><div class="stat-value"><?= $total ?></div></article>
    <article class="stat-card"><div class="stat-label">Cevaplanan</div><div class="stat-value"><?= $answered ?></div></article>
    <article class="stat-card"><div class="stat-label">Kaçan</div><div class="stat-value"><?= $abandoned ?></div></article>
    <?php if ($callbackCheck): ?>
        <article class="stat-card"><div class="stat-label">Geri Arandı</div><div class="stat-value"><?= $calledBack ?></div></article>
        <article class="stat-card"><div class="stat-label">Aranmadı</div><div class="stat-value"><?= $notCalledBack ?></div></article>
    <?php endif; ?>
</section>

<div class="operational-report-actions">
    <span>Excel ve yazdırma yalnız bu sayfada görüntülenen en fazla 100 kaydı kapsar.</span>
    <button class="btn btn-success" id="queue-detail-export" type="button">Excel (.xls)</button>
    <button class="btn btn-outline" id="queue-detail-print" type="button">Yazdır</button>
</div>
<div class="queue-detail-search operational-report-search"><label for="queue-table-search">Bu sayfadaki sonuçlarda ara</label><input id="queue-table-search" type="search" placeholder="Tüm sütunlarda ara…"></div>

<div class="table-container operational-print-area">
    <div class="table-header">Çağrı Bazında Kuyruk Detayı · En fazla 100 kayıt</div>
    <table class="queue-detail-table" id="queue-detail-table"><thead><tr>
        <th>Kuyruk Kodu</th><th>Kuyruk Adı</th><th>Durum</th><th>Agent</th><th>Tarih</th><th>Müşteri No</th>
        <th>Bekleme</th><th>Görüşme</th><th>Giriş Sıra</th><th>Çıkış Sıra</th><th>Geri Dönüş</th>
        <th>Sonuç</th><th>Dönüş Zamanı</th><th>Ses Kaydı</th><th>Call ID</th>
    </tr></thead><tbody>
    <?php foreach ($rows as $row):
        $isAbandoned = (string) $row['status'] === 'ABANDON';
        $displayPhone = (string) ($row['customer_phone'] ?: 'Bilinmiyor');
        if (!$canSeePhone && $displayPhone !== 'Bilinmiyor') {
            $displayPhone = queue_detail_mask_phone($displayPhone);
        }
        $callbackText = $isAbandoned
            ? ($callbackCheck
                ? ($row['callback_time']
                    ? 'Agent Aradı · ' . (string) $row['callback_agent_name'] . ' · ' . (string) $row['callback_extension']
                    : 'Aranmadı')
                : 'Kontrol Kapalı')
            : 'Gerekmez';
        $resultText = $isAbandoned
            ? ($callbackCheck && $row['callback_time']
                ? ($dispositionLabels[(string) $row['callback_disposition']] ?? (string) $row['callback_disposition'])
                : '—')
            : $statusLabels[(string) $row['status']];
    ?>
        <tr class="<?= $isAbandoned ? 'queue-abandoned-row' : '' ?>">
            <td><strong><?= e((string) $row['queuename']) ?></strong></td>
            <td><?= e($queueNames[(string) $row['queuename']] ?? (string) $row['queuename']) ?></td>
            <td><span class="badge <?= $isAbandoned ? 'badge-danger' : 'badge-success' ?>"><?= e($statusLabels[(string) $row['status']] ?? (string) $row['status']) ?></span></td>
            <td><?php if ($row['agent_id']): ?><strong><?= e((string) $row['agent_name']) ?></strong><small class="agent-detail-sub"><?= e((string) $row['extension']) ?></small><?php else: ?>—<?php endif; ?></td>
            <td class="audit-time"><?= e((string) $row['call_time']) ?></td>
            <td><?= e($displayPhone) ?></td>
            <td><?= e(supervisor_duration($row['wait_seconds'])) ?></td>
            <td><?= e(supervisor_duration($row['talk_seconds'])) ?></td>
            <td><?= $row['entry_position'] !== null ? (int) $row['entry_position'] : '—' ?></td>
            <td><?= $row['exit_position'] !== null ? (int) $row['exit_position'] : '—' ?></td>
            <td><?= e($callbackText) ?></td><td><?= e($resultText) ?></td>
            <td class="audit-time"><?= $row['callback_time'] ? e(date('d.m.Y H:i:s', strtotime((string) $row['callback_time']))) : '—' ?></td>
            <td class="recording-cell">
                <?php if ($row['recording_id']): ?>
                    <?php if ($canPlay): ?><audio controls preload="none" src="../../recording.php?id=<?= (int) $row['recording_id'] ?>"></audio><?php endif; ?>
                    <?php if ($canDownload): ?><a class="btn btn-outline recording-download" href="../../recording.php?id=<?= (int) $row['recording_id'] ?>&amp;download=1">İndir</a><?php endif; ?>
                    <?php if (!$canPlay && !$canDownload): ?><span class="muted">Kayıt erişim yetkisi gerekli</span><?php endif; ?>
                <?php else: ?><span class="muted">Kayıt yok</span><?php endif; ?>
            </td>
            <td><code class="queue-callid"><?= e((string) $row['callid']) ?></code></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="15" class="empty">Seçilen filtrelerde çağrı bulunamadı.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<?php if ($hasNext && $rows):
    $lastRow = end($rows);
    $nextQuery = $_GET;
    $nextQuery['from'] = $from;
    $nextQuery['to'] = $to;
    $nextQuery['cursor_time'] = (string) $lastRow['call_date'];
    $nextQuery['cursor_id'] = (int) $lastRow['max_log_id'];
?>
<div class="audit-pagination operational-no-print"><a class="btn btn-outline" href="?<?= e(http_build_query($nextQuery)) ?>">Daha Eski Çağrılar →</a></div>
<?php endif; ?>
<script>
document.getElementById('queue-table-search')?.addEventListener('input',event=>{
    const query=event.currentTarget.value.toLocaleLowerCase('tr-TR');
    document.querySelectorAll('#queue-detail-table tbody tr').forEach(row=>{
        row.hidden=query!==''&&!row.textContent.toLocaleLowerCase('tr-TR').includes(query);
    });
});
document.getElementById('queue-detail-print')?.addEventListener('click',()=>window.print());
document.getElementById('queue-detail-export')?.addEventListener('click',()=>{
    const table=document.getElementById('queue-detail-table');
    const escapeHtml=value=>{const node=document.createElement('div');node.textContent=value;return node.innerHTML;};
    const excelSafe=value=>/^[=+\-@]/.test(value.trim())?"'"+value:value;
    const headers=[...table.tHead.rows[0].cells].map(cell=>`<th>${escapeHtml(cell.textContent.trim())}</th>`).join('');
    const rows=[...table.tBodies[0].rows].filter(row=>!row.hidden&&row.cells.length>1&&!row.querySelector('.empty')).map(row=>
        '<tr>'+[...row.cells].map(cell=>`<td>${escapeHtml(excelSafe(cell.textContent.trim()))}</td>`).join('')+'</tr>'
    ).join('');
    const html=`<!doctype html><html><head><meta charset="utf-8"></head><body><h2>Kuyruk Detay Raporu</h2><p><?= e(date('d.m.Y', strtotime($from))) ?> - <?= e(date('d.m.Y', strtotime($to))) ?> · Bu sayfadaki kayıtlar</p><table border="1"><thead><tr>${headers}</tr></thead><tbody>${rows}</tbody></table></body></html>`;
    const url=URL.createObjectURL(new Blob(['\ufeff',html],{type:'application/vnd.ms-excel;charset=utf-8'}));
    const link=document.createElement('a');link.href=url;link.download=<?= json_encode('kuyruk_detay_' . date('Y-m-d') . '.xls', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    document.body.appendChild(link);link.click();link.remove();URL.revokeObjectURL(url);
});
</script>
<?php supervisor_page_end(); ?>
