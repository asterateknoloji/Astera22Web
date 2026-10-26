<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

function supervisor_mask_phone(string $number): string
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
$agentId = max(0, (int) ($_GET['agent_id'] ?? 0));
$direction = in_array($_GET['direction'] ?? '', ['incoming', 'outgoing', 'internal'], true)
    ? (string) $_GET['direction'] : '';
$disposition = in_array($_GET['disposition'] ?? '', ['ANSWERED', 'NO ANSWER', 'BUSY', 'FAILED', 'CONGESTION'], true)
    ? (string) $_GET['disposition'] : '';
$phone = preg_replace('/\D+/', '', (string) ($_GET['phone'] ?? '')) ?? '';
$minTalk = max(0, (int) ($_GET['min_talk'] ?? 0));
$maxTalk = max(0, (int) ($_GET['max_talk'] ?? 0));
$recordedOnly = ($_GET['recorded'] ?? '') === '1';
$before = max(0, (int) ($_GET['before'] ?? 0));
$selectedQueues = array_values(array_intersect(
    $queueIds,
    array_map('strval', (array) ($_GET['queues'] ?? []))
));

$agents = callcenter_rows(
    'SELECT a.agent_id,a.display_name,a.extension FROM callcenter_agents a '
    . 'WHERE a.dept_id=:dept AND a.status=\'active\' AND ' . supervisor_agent_sql('a')
    . ' ORDER BY a.display_name',
    ['dept' => $deptId]
);
$allowedAgentIds = array_map('intval', array_column($agents, 'agent_id'));
if ($agentId && !in_array($agentId, $allowedAgentIds, true)) {
    $agentId = 0;
}

$sourceToken = "split_part(split_part(COALESCE(c.channel,''),'/',2),'-',1)";
$destinationToken = "split_part(split_part(COALESCE(c.dstchannel,''),'/',2),'-',1)";
$sourceAgent = "(a.extension=c.src OR {$sourceToken} IN (a.extension,a.dept_id||'_'||a.extension))";
$destinationAgent = "(a.extension=c.dst OR {$destinationToken} IN (a.extension,a.dept_id||'_'||a.extension))";
$agentMatch = "({$sourceAgent} OR {$destinationAgent})";
$directionSql = "CASE "
    . "WHEN COALESCE(c.recordingfile,'') LIKE '%yon_dahili%' THEN 'internal' "
    . "WHEN COALESCE(c.recordingfile,'') LIKE '%yon_gelen%' THEN 'incoming' "
    . "WHEN COALESCE(c.recordingfile,'') LIKE '%yon_giden%' THEN 'outgoing' "
    . "WHEN q.queuename IS NOT NULL THEN 'incoming' "
    . "WHEN {$destinationAgent} AND NOT {$sourceAgent} THEN 'incoming' "
    . "WHEN {$sourceAgent} THEN 'outgoing' ELSE 'incoming' END";

$where = [
    'c.dept_id=:dept',
    'c.calldate>=:from::date',
    "c.calldate<(:to::date+interval '1 day')",
    supervisor_agent_sql('a'),
];
$params = ['dept' => $deptId, 'from' => $from, 'to' => $to];
if ($agentId) {
    $where[] = 'a.agent_id=:agent';
    $params['agent'] = $agentId;
}
if ($phone !== '') {
    $where[] = '(c.src_normalized LIKE :phone OR c.dst_normalized LIKE :phone)';
    $params['phone'] = '%' . $phone . '%';
}
if ($disposition !== '') {
    $where[] = 'c.disposition=:disposition';
    $params['disposition'] = $disposition;
}
if ($minTalk > 0) {
    $where[] = 'c.billsec>=:min_talk';
    $params['min_talk'] = $minTalk;
}
if ($maxTalk > 0) {
    $where[] = 'c.billsec<=:max_talk';
    $params['max_talk'] = $maxTalk;
}
if ($before > 0) {
    $where[] = 'c.id<:before';
    $params['before'] = $before;
}
if ($selectedQueues) {
    $queuePlaceholders = [];
    foreach ($selectedQueues as $index => $queueId) {
        $name = 'selected_queue_' . $index;
        $queuePlaceholders[] = ':' . $name;
        $params[$name] = $queueId;
    }
    $where[] = 'q.queuename IN (' . implode(',', $queuePlaceholders) . ')';
}

$postFilters = [];
if ($direction !== '') {
    $postFilters[] = 'direction=:direction';
    $params['direction'] = $direction;
}
if ($recordedOnly) {
    $postFilters[] = 'recording_id IS NOT NULL';
}
$postWhere = $postFilters ? 'WHERE ' . implode(' AND ', $postFilters) : '';
$sql = "WITH matched AS ("
    . "SELECT c.id AS cdr_id,COALESCE(NULLIF(c.linkedid,''),NULLIF(c.uniqueid,''),c.id::text) AS call_key,"
    . "c.calldate,a.agent_id,a.display_name AS agent,a.extension,c.src,c.dst,c.disposition,c.duration,c.billsec,"
    . "q.queuename AS queue_name,{$directionSql} AS direction,r.id AS recording_id,"
    . "ev.evaluation_count,ev.last_score,ev.last_max_score "
    . "FROM cdr c JOIN callcenter_agents a ON a.dept_id=c.dept_id AND {$agentMatch} "
    . "LEFT JOIN LATERAL (SELECT ql.queuename FROM queue_log ql "
    . "WHERE ql.callid IN (c.linkedid,c.uniqueid) AND ql.event='ENTERQUEUE' AND "
    . supervisor_queue_sql('ql.queuename') . ' ORDER BY ql.time LIMIT 1) q ON TRUE '
    . "LEFT JOIN LATERAL (SELECT cr.id FROM call_recordings cr WHERE cr.dept_id=c.dept_id AND ("
    . "cr.object_key=c.recordingfile OR (cr.linkedid<>'' AND cr.linkedid=c.linkedid) OR "
    . "(cr.uniqueid<>'' AND cr.uniqueid=c.uniqueid)) ORDER BY "
    . "(cr.object_key=c.recordingfile) DESC,cr.recorded_at DESC NULLS LAST,cr.id DESC LIMIT 1) r ON TRUE "
    . "LEFT JOIN LATERAL (SELECT count(*)::integer AS evaluation_count,"
    . "(array_agg(se.total_score ORDER BY se.evaluated_at DESC,se.evaluation_id DESC))[1] AS last_score,"
    . "(array_agg(se.max_score ORDER BY se.evaluated_at DESC,se.evaluation_id DESC))[1] AS last_max_score "
    . "FROM callcenter_survey_evaluations se WHERE se.dept_id=c.dept_id AND se.agent_id=a.agent_id "
    . "AND se.call_linkedid=COALESCE(NULLIF(c.linkedid,''),NULLIF(c.uniqueid,''),c.id::text) AND se.status<>'void') ev ON TRUE "
    . 'WHERE ' . implode(' AND ', $where)
    . "), deduplicated AS (SELECT DISTINCT ON (call_key,agent_id) * FROM matched "
    . "ORDER BY call_key,agent_id,(recording_id IS NOT NULL) DESC,billsec DESC,cdr_id DESC), "
    . "filtered AS (SELECT * FROM deduplicated {$postWhere}) "
    . "SELECT *,to_char(calldate,'DD.MM.YYYY HH24:MI:SS') AS call_time,"
    . "count(*) OVER()::integer AS filtered_total,"
    . "count(*) FILTER (WHERE disposition='ANSWERED') OVER()::integer AS answered_total,"
    . "count(*) FILTER (WHERE direction='incoming') OVER()::integer AS incoming_total,"
    . "count(*) FILTER (WHERE direction='outgoing') OVER()::integer AS outgoing_total "
    . 'FROM filtered ORDER BY cdr_id DESC LIMIT 101';
$rows = callcenter_rows($sql, $params);
$hasNext = count($rows) > 100;
if ($hasNext) {
    array_pop($rows);
}

$total = (int) ($rows[0]['filtered_total'] ?? 0);
$answered = (int) ($rows[0]['answered_total'] ?? 0);
$incoming = (int) ($rows[0]['incoming_total'] ?? 0);
$outgoing = (int) ($rows[0]['outgoing_total'] ?? 0);
$canPlay = callcenter_supervisor_has_permission($supervisor, 'recording_play');
$canDownload = callcenter_supervisor_has_permission($supervisor, 'recording_download');
$canSeePhone = callcenter_supervisor_has_permission($supervisor, 'phone_unmasked');
$canSurvey = callcenter_supervisor_has_permission($supervisor, 'cc_anket');
$reportQuery = $_GET;
unset($reportQuery['before']);
$reportUrl = 'agentdetay.php' . ($reportQuery ? '?' . http_build_query($reportQuery) : '');
$directionLabels = ['incoming' => 'Gelen', 'outgoing' => 'Giden', 'internal' => 'Dahili'];
$statusLabels = ['ANSWERED' => 'Cevaplandı', 'NO ANSWER' => 'Cevapsız', 'BUSY' => 'Meşgul', 'FAILED' => 'Başarısız', 'CONGESTION' => 'Yoğunluk'];

supervisor_page_start('Agent Detay');
?>
<form class="filter-bar agent-detail-filter" method="get">
    <label class="filter-group"><span class="filter-label">Başlangıç</span><input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="filter-group"><span class="filter-label">Bitiş</span><input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="filter-group"><span class="filter-label">Agent</span><select name="agent_id"><option value="0">Tüm Agentlar</option>
        <?php foreach ($agents as $agent): ?><option value="<?= (int) $agent['agent_id'] ?>"<?= $agentId === (int) $agent['agent_id'] ? ' selected' : '' ?>><?= e((string) $agent['display_name'] . ' · ' . (string) $agent['extension']) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group queue-filter"><span class="filter-label">Kuyruklar · Çoklu seçim</span><select name="queues[]" multiple size="4">
        <?php foreach ($queues as $queue): ?><option value="<?= e((string) $queue['id']) ?>"<?= in_array((string) $queue['id'], $selectedQueues, true) ? ' selected' : '' ?>><?= e((string) ($queue['name'] ?? $queue['id'])) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group"><span class="filter-label">Yön</span><select name="direction"><option value="">Tümü</option>
        <?php foreach ($directionLabels as $value => $label): ?><option value="<?= $value ?>"<?= $direction === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group"><span class="filter-label">Durum</span><select name="disposition"><option value="">Tümü</option>
        <?php foreach ($statusLabels as $value => $label): ?><option value="<?= e($value) ?>"<?= $disposition === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group"><span class="filter-label">Telefon</span><input name="phone" inputmode="numeric" value="<?= e($phone) ?>" placeholder="Arayan veya aranan"></label>
    <label class="filter-group"><span class="filter-label">En az görüşme (sn)</span><input type="number" min="0" name="min_talk" value="<?= $minTalk ?: '' ?>"></label>
    <label class="filter-group"><span class="filter-label">En çok görüşme (sn)</span><input type="number" min="0" name="max_talk" value="<?= $maxTalk ?: '' ?>"></label>
    <label class="choice-row recorded-filter"><input type="checkbox" name="recorded" value="1"<?= $recordedOnly ? ' checked' : '' ?>><span><strong>Yalnız ses kayıtlı</strong></span></label>
    <button class="btn btn-primary" type="submit">Raporla</button>
    <a class="btn btn-outline" href="agentdetay.php">Temizle</a>
</form>

<section class="stats-grid">
    <article class="stat-card"><div class="stat-label">Toplam Çağrı</div><div class="stat-value"><?= $total ?></div></article>
    <article class="stat-card"><div class="stat-label">Cevaplanan</div><div class="stat-value"><?= $answered ?></div></article>
    <article class="stat-card"><div class="stat-label">Gelen</div><div class="stat-value"><?= $incoming ?></div></article>
    <article class="stat-card"><div class="stat-label">Giden</div><div class="stat-value"><?= $outgoing ?></div></article>
</section>

<div class="operational-report-actions">
    <span>Excel ve yazdırma yalnız bu sayfada görüntülenen en fazla 100 kaydı kapsar.</span>
    <button class="btn btn-success" id="agent-detail-export" type="button">Excel (.xls)</button>
    <button class="btn btn-outline" id="agent-detail-print" type="button">Yazdır</button>
</div>
<div class="table-container operational-print-area">
    <div class="table-header">Agent Çağrı Detayları</div>
    <table class="agent-detail-table"><thead><tr><th>Tarih / Saat</th><th>Agent</th><th>Kuyruk</th><th>Yön</th><th>Arayan</th><th>Aranan</th><th>Durum</th><th>Süre</th><th>Görüşme</th><th>Ses Kaydı</th><th>Anket</th></tr></thead><tbody>
    <?php foreach ($rows as $row):
        $src = $canSeePhone ? (string) $row['src'] : supervisor_mask_phone((string) $row['src']);
        $dst = $canSeePhone ? (string) $row['dst'] : supervisor_mask_phone((string) $row['dst']);
    ?>
        <tr>
            <td class="audit-time"><?= e((string) $row['call_time']) ?></td>
            <td><strong><?= e((string) $row['agent']) ?></strong><small class="agent-detail-sub"><?= e((string) $row['extension']) ?></small></td>
            <td><?= e((string) ($row['queue_name'] ?: '—')) ?></td>
            <td><span class="badge direction-<?= e((string) $row['direction']) ?>"><?= e($directionLabels[(string) $row['direction']] ?? (string) $row['direction']) ?></span></td>
            <td><?= e($src) ?></td><td><?= e($dst) ?></td>
            <td><span class="badge disposition-<?= e(strtolower(str_replace(' ', '-', (string) $row['disposition']))) ?>"><?= e($statusLabels[(string) $row['disposition']] ?? (string) $row['disposition']) ?></span></td>
            <td><?= e(supervisor_duration($row['duration'])) ?></td><td><?= e(supervisor_duration($row['billsec'])) ?></td>
            <td class="recording-cell">
                <?php if ($row['recording_id'] && $canPlay): ?>
                    <audio controls preload="none" src="../../recording.php?id=<?= (int) $row['recording_id'] ?>"></audio>
                    <?php if ($canDownload): ?><a class="btn btn-outline recording-download" href="../../recording.php?id=<?= (int) $row['recording_id'] ?>&amp;download=1">İndir</a><?php endif; ?>
                <?php elseif ($row['recording_id']): ?><span class="muted">Dinleme yetkisi gerekli</span>
                <?php else: ?><span class="muted">Kayıt yok</span><?php endif; ?>
            </td>
            <td class="survey-call-action">
                <?php if ($canSurvey && (string) $row['disposition'] === 'ANSWERED'): ?>
                    <a class="btn btn-outline" href="anket.php?<?= e(http_build_query(['cdr' => (int) $row['cdr_id'], 'agent' => (int) $row['agent_id'], 'return' => $reportUrl])) ?>">
                        <?= (int) $row['evaluation_count'] > 0 ? 'Değerlendirildi · Düzenle' : 'Anket Değerlendir' ?>
                    </a>
                    <?php if ((int) $row['evaluation_count'] > 0): ?><small class="agent-detail-sub"><?= e(number_format((float) $row['last_score'], 2, ',', '.')) ?> / <?= e(number_format((float) $row['last_max_score'], 2, ',', '.')) ?></small><?php endif; ?>
                <?php elseif (!$canSurvey): ?><span class="muted">Anket yetkisi gerekli</span>
                <?php else: ?><span class="muted">Cevaplanan çağrı gerekli</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="11" class="empty">Seçilen filtrelerde çağrı bulunamadı.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<?php if ($hasNext && $rows):
    $nextQuery = $_GET;
    $nextQuery['from'] = $from;
    $nextQuery['to'] = $to;
    $nextQuery['before'] = (int) end($rows)['cdr_id'];
?>
<div class="audit-pagination operational-no-print"><a class="btn btn-outline" href="?<?= e(http_build_query($nextQuery)) ?>">Daha Eski Çağrılar →</a></div>
<?php endif; ?>
<script>
(()=>{
    const table=document.querySelector('.agent-detail-table');
    document.getElementById('agent-detail-print')?.addEventListener('click',()=>window.print());
    document.getElementById('agent-detail-export')?.addEventListener('click',()=>{
        const escapeHtml=value=>{const node=document.createElement('div');node.textContent=value;return node.innerHTML;};
        const excelSafe=value=>/^[=+\-@]/.test(value.trim())?"'"+value:value;
        const headers=[...table.tHead.rows[0].cells].map(cell=>`<th>${escapeHtml(cell.textContent.trim())}</th>`).join('');
        const rows=[...table.tBodies[0].rows].filter(row=>row.cells.length>1&&!row.querySelector('.empty')).map(row=>
            '<tr>'+[...row.cells].map(cell=>`<td>${escapeHtml(excelSafe(cell.textContent.trim()))}</td>`).join('')+'</tr>'
        ).join('');
        const html=`<!doctype html><html><head><meta charset="utf-8"></head><body><h2>Agent Detay Raporu</h2><p><?= e(date('d.m.Y', strtotime($from))) ?> - <?= e(date('d.m.Y', strtotime($to))) ?> · Bu sayfadaki kayıtlar</p><table border="1"><thead><tr>${headers}</tr></thead><tbody>${rows}</tbody></table></body></html>`;
        const url=URL.createObjectURL(new Blob(['\ufeff',html],{type:'application/vnd.ms-excel;charset=utf-8'}));
        const link=document.createElement('a');link.href=url;link.download=<?= json_encode('agent_detay_' . date('Y-m-d') . '.xls', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        document.body.appendChild(link);link.click();link.remove();URL.revokeObjectURL(url);
    });
})();
</script>
<?php supervisor_page_end(); ?>
