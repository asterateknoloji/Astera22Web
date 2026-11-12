<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$route = basename($_SERVER['SCRIPT_FILENAME'] ?? '', '.php');
$from = supervisor_date('from');
$to = supervisor_date('to', $from);
$params = ['dept' => $deptId, 'from' => $from, 'to' => $to];
$titles = [
    'dashboard1' => 'Callcenter Dashboard', 'kuyruk_detay' => 'Kuyruk Detay',
    'agentdetay' => 'Agent Detay', 'agent_logins' => 'Login / Logout',
    'moladetay' => 'Mola Detay', 'agent_ozet1' => 'Agent Özet',
    'agent_ozet2' => 'Agent Özet 2', 'molaozet' => 'Mola Özet',
    'molaozettoplam' => 'Mola Özet Toplam', 'kuyrukozet' => 'Kuyruk Özet',
    'kuyruk_analiz' => 'Kuyruk Analiz',
];
$title = $titles[$route] ?? 'Callcenter Raporu';
$rows = [];
$agentRows = [];
$liveSnapshot = null;

if ($route === 'dashboard1' || $route === 'kuyrukozet' || $route === 'kuyruk_analiz') {
    $rows = callcenter_rows(
        "SELECT queuename AS kuyruk, count(*) FILTER (WHERE event='ENTERQUEUE')::integer AS sunulan, "
        . "count(*) FILTER (WHERE event='CONNECT')::integer AS cevaplanan, "
        . "count(*) FILTER (WHERE event IN ('ABANDON','EXITWITHTIMEOUT'))::integer AS kacan, "
        . "COALESCE(round(avg(NULLIF(data1,'')::numeric) FILTER (WHERE event='CONNECT')),0)::integer AS ort_bekleme "
        . "FROM queue_log WHERE time >= :from::date AND time < (:to::date + interval '1 day') AND "
        . supervisor_queue_sql() . ' GROUP BY queuename ORDER BY queuename',
        ['from' => $from, 'to' => $to]
    );
    if ($route === 'dashboard1') {
        $snapshotIdentity = $supervisor;
        $snapshotIdentity['owner_dept_id'] = $ownerDeptId;
        $snapshotIdentity['dept_id'] = $deptId;
        $liveSnapshot = callcenter_supervisor_snapshot($snapshotIdentity);
        $agentRows = callcenter_rows(
            "SELECT a.display_name AS agent, a.extension AS dahili, count(*)::integer AS cevaplanan, "
            . "COALESCE(SUM(NULLIF(q.data2,'')::integer),0)::integer AS gorusme_saniye "
            . "FROM queue_log q JOIN callcenter_agents a ON a.dept_id=:dept "
            . "AND regexp_replace(q.agent,'^(PJSIP/|SIP/|Local/)|@.*$','','g')=a.extension "
            . "WHERE q.time>=:from::date AND q.time<(:to::date+interval '1 day') AND q.event IN ('COMPLETEAGENT','COMPLETECALLER') "
            . 'AND ' . supervisor_queue_sql('q.queuename') . ' AND ' . supervisor_agent_sql('a')
            . ' GROUP BY a.agent_id,a.display_name,a.extension ORDER BY cevaplanan DESC,a.display_name',
            $params
        );
    }
} elseif ($route === 'kuyruk_detay') {
    $rows = callcenter_rows(
        "SELECT to_char(time,'YYYY-MM-DD HH24:MI:SS') AS zaman, callid, queuename AS kuyruk, "
        . "agent AS temsilci, event AS olay, data1 AS veri_1, data2 AS veri_2, data3 AS veri_3 "
        . "FROM queue_log WHERE time >= :from::date AND time < (:to::date + interval '1 day') AND "
        . supervisor_queue_sql() . ' ORDER BY time DESC, id DESC LIMIT 1000',
        ['from' => $from, 'to' => $to]
    );
} elseif ($route === 'agent_logins') {
    $rows = callcenter_rows(
        "SELECT a.display_name AS agent, a.extension AS dahili, to_char(s.started_at,'YYYY-MM-DD HH24:MI:SS') AS giris, "
        . "to_char(s.ended_at,'YYYY-MM-DD HH24:MI:SS') AS cikis, s.end_reason AS cikis_nedeni, "
        . "EXTRACT(EPOCH FROM (COALESCE(s.ended_at,now())-s.started_at))::integer AS sure_saniye "
        . 'FROM callcenter_agent_sessions s JOIN callcenter_agents a ON a.dept_id=s.dept_id AND a.agent_id=s.agent_id '
        . "WHERE s.dept_id=:dept AND s.started_at >= :from::date AND s.started_at < (:to::date+interval '1 day') AND "
        . supervisor_agent_sql('a') . ' ORDER BY s.started_at DESC LIMIT 1000',
        $params
    );
} elseif ($route === 'agentdetay') {
    $rows = callcenter_rows(
        "SELECT to_char(c.calldate,'YYYY-MM-DD HH24:MI:SS') AS zaman, a.display_name AS agent, "
        . "a.extension AS dahili, c.src AS arayan, c.dst AS aranan, c.disposition AS durum, "
        . "c.duration AS sure_saniye, c.billsec AS gorusme_saniye "
        . 'FROM cdr c JOIN callcenter_agents a ON a.dept_id=c.dept_id AND (a.extension=c.src OR a.extension=c.dst) '
        . "WHERE c.dept_id=:dept AND c.calldate>=:from::date AND c.calldate<(:to::date+interval '1 day') AND "
        . supervisor_agent_sql('a') . ' ORDER BY c.calldate DESC LIMIT 1000',
        $params
    );
} elseif (in_array($route, ['moladetay', 'molaozet', 'molaozettoplam'], true)) {
    $select = $route === 'moladetay'
        ? "a.display_name AS agent, t.name AS mola, to_char(b.started_at,'YYYY-MM-DD HH24:MI:SS') AS baslangic, "
            . "to_char(b.ended_at,'YYYY-MM-DD HH24:MI:SS') AS bitis, EXTRACT(EPOCH FROM (COALESCE(b.ended_at,now())-b.started_at))::integer AS sure_saniye"
        : ($route === 'molaozet'
            ? 'a.display_name AS agent, t.name AS mola, count(*)::integer AS adet, SUM(EXTRACT(EPOCH FROM (COALESCE(b.ended_at,now())-b.started_at)))::integer AS toplam_saniye'
            : 't.name AS mola, count(*)::integer AS adet, SUM(EXTRACT(EPOCH FROM (COALESCE(b.ended_at,now())-b.started_at)))::integer AS toplam_saniye');
    $group = $route === 'moladetay' ? '' : ($route === 'molaozet' ? ' GROUP BY a.display_name,t.name' : ' GROUP BY t.name');
    $rows = callcenter_rows(
        "SELECT {$select} FROM callcenter_breaks b JOIN callcenter_agents a ON a.dept_id=b.dept_id AND a.agent_id=b.agent_id "
        . 'JOIN callcenter_break_types t ON t.dept_id=b.dept_id AND t.break_type_id=b.break_type_id '
        . "WHERE b.dept_id=:dept AND b.started_at >= :from::date AND b.started_at < (:to::date+interval '1 day') AND "
        . supervisor_agent_sql('a') . $group . ($group ? ' ORDER BY 1,2' : ' ORDER BY b.started_at DESC LIMIT 1000'),
        $params
    );
} else {
    $rows = callcenter_rows(
        "SELECT a.display_name AS agent, a.extension AS dahili, "
        . 'COALESCE(s.oturum,0)::integer AS oturum, COALESCE(s.sure,0)::integer AS oturum_saniye, '
        . 'COALESCE(b.sure,0)::integer AS mola_saniye, COALESCE(c.adet,0)::integer AS cevaplanan_cagri '
        . 'FROM callcenter_agents a '
        . "LEFT JOIN LATERAL (SELECT count(*) AS oturum,SUM(EXTRACT(EPOCH FROM (LEAST(COALESCE(ended_at,now()),:to::date+interval '1 day')-GREATEST(started_at,:from::date)))) AS sure "
        . "FROM callcenter_agent_sessions WHERE dept_id=a.dept_id AND agent_id=a.agent_id AND started_at<(:to::date+interval '1 day') AND COALESCE(ended_at,now())>=:from::date) s ON TRUE "
        . "LEFT JOIN LATERAL (SELECT SUM(EXTRACT(EPOCH FROM (COALESCE(ended_at,now())-started_at))) AS sure FROM callcenter_breaks "
        . "WHERE dept_id=a.dept_id AND agent_id=a.agent_id AND started_at>=:from::date AND started_at<(:to::date+interval '1 day')) b ON TRUE "
        . "LEFT JOIN LATERAL (SELECT count(*) AS adet FROM cdr WHERE dept_id=a.dept_id AND (src=a.extension OR dst=a.extension) "
        . "AND disposition='ANSWERED' AND calldate>=:from::date AND calldate<(:to::date+interval '1 day')) c ON TRUE "
        . 'WHERE a.dept_id=:dept AND ' . supervisor_agent_sql('a')
        . ' ORDER BY a.display_name',
        $params
    );
}

supervisor_page_start($title);
supervisor_filter_bar($from, $to);
if ($route === 'dashboard1') {
    $offered = array_sum(array_column($rows, 'sunulan'));
    $answered = array_sum(array_column($rows, 'cevaplanan'));
    $missed = array_sum(array_column($rows, 'kacan'));
    echo '<div class="stats-grid"><div class="stat-card"><div class="stat-label">Çağrı Sayısı</div><div class="stat-value">' . $offered . '</div></div>';
    echo '<div class="stat-card"><div class="stat-label">Cevaplanan</div><div class="stat-value">' . $answered . '</div></div>';
    echo '<div class="stat-card"><div class="stat-label">Kaçan</div><div class="stat-value">' . $missed . '</div></div>';
    echo '<div class="stat-card"><div class="stat-label">Cevaplama Oranı</div><div class="stat-value">' . ($offered ? round($answered * 100 / $offered, 1) : 0) . '%</div></div>';
    echo '<div class="stat-card"><div class="stat-label">Aktif Agent</div><div class="stat-value" id="cc-live-active">'
        . (int) ($liveSnapshot['active_agent_count'] ?? 0) . '</div></div>';
    echo '<div class="stat-card"><div class="stat-label">Hazır Agent</div><div class="stat-value" id="cc-live-available">'
        . (int) ($liveSnapshot['available_agent_count'] ?? 0) . '</div></div>';
    echo '<div class="stat-card"><div class="stat-label">Molada</div><div class="stat-value" id="cc-live-break">'
        . (int) ($liveSnapshot['active_break_count'] ?? 0) . '</div></div></div>';
    echo '<p class="live-update-note">5 saniyede bir canlı yenilenir · <span id="cc-live-updated">şimdi</span></p>';
}
echo '<div class="table-container"><div class="table-header">' . e($title) . '</div><table><thead><tr>';
$columns = $rows ? array_keys($rows[0]) : [];
foreach ($columns as $column) {
    echo '<th>' . e(ucwords(str_replace('_', ' ', $column))) . '</th>';
}
echo '</tr></thead><tbody>';
foreach ($rows as $row) {
    echo '<tr>';
    foreach ($columns as $column) {
        $value = $row[$column] ?? '';
        if (str_ends_with($column, '_saniye')) {
            $value = supervisor_duration($value);
        }
        echo '<td>' . e((string) $value) . '</td>';
    }
    echo '</tr>';
}
if (!$rows) {
    echo '<tr><td class="empty" colspan="' . max(1, count($columns)) . '">Seçilen aralıkta kayıt bulunamadı.</td></tr>';
}
echo '</tbody></table></div>';
if ($route === 'dashboard1') {
    echo '<div class="table-container"><div class="table-header">Canlı Agent Durumu</div><table><thead><tr>'
        . '<th>Agent</th><th>Dahili</th><th>Oturum</th><th>PJSIP</th><th>Mola</th><th>Kuyruklar</th>'
        . '</tr></thead><tbody id="cc-live-agent-body">';
    foreach ((array) ($liveSnapshot['agents'] ?? []) as $liveAgent) {
        $loggedIn = !empty($liveAgent['is_logged_in']);
        $onBreak = $loggedIn && !empty($liveAgent['active_break']);
        echo '<tr><td>' . e((string) $liveAgent['display_name']) . '</td><td>' . e((string) $liveAgent['extension'])
            . '</td><td><span class="badge ' . ($loggedIn ? ($onBreak ? 'badge-warning' : 'badge-success') : 'badge-danger') . '">'
            . ($loggedIn ? ($onBreak ? 'Molada' : 'Hazır') : 'Çevrimdışı') . '</span></td><td>'
            . e((string) ($liveAgent['live']['label'] ?? 'Bilinmiyor')) . '</td><td>'
            . e((string) ($liveAgent['active_break'] ?: '—')) . '</td><td>'
            . e((string) ($liveAgent['queue_names'] ?: '—')) . '</td></tr>';
    }
    if (empty($liveSnapshot['agents'])) {
        echo '<tr><td colspan="6" class="empty">Kapsamınızdaki Agent bulunamadı.</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<div class="table-container"><div class="table-header">Agent Özeti</div><table><thead><tr><th>Agent</th><th>Dahili</th><th>Cevaplanan</th><th>Görüşme Süresi</th></tr></thead><tbody>';
    foreach ($agentRows as $agentRow) {
        echo '<tr><td>' . e((string) $agentRow['agent']) . '</td><td>' . e((string) $agentRow['dahili']) . '</td><td>' . (int) $agentRow['cevaplanan'] . '</td><td>' . e(supervisor_duration($agentRow['gorusme_saniye'])) . '</td></tr>';
    }
    if (!$agentRows) echo '<tr><td colspan="4" class="empty">Agent özeti bulunamadı.</td></tr>';
    echo '</tbody></table></div>';
    echo <<<'HTML'
<script>
(()=>{
    const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const render=data=>{
        document.getElementById('cc-live-active').textContent=data.active_agent_count??0;
        document.getElementById('cc-live-available').textContent=data.available_agent_count??0;
        document.getElementById('cc-live-break').textContent=data.active_break_count??0;
        document.getElementById('cc-live-updated').textContent=new Date(data.generated_at??Date.now()).toLocaleTimeString('tr-TR');
        const agents=data.agents??[];
        document.getElementById('cc-live-agent-body').innerHTML=agents.length?agents.map(agent=>{
            const label=!agent.is_logged_in?'Çevrimdışı':(agent.active_break?'Molada':'Hazır');
            const badge=!agent.is_logged_in?'badge-danger':(agent.active_break?'badge-warning':'badge-success');
            return `<tr><td>${esc(agent.display_name)}</td><td>${esc(agent.extension)}</td><td><span class="badge ${badge}">${label}</span></td><td>${esc(agent.live?.label??'Bilinmiyor')}</td><td>${esc(agent.active_break||'—')}</td><td>${esc(agent.queue_names||'—')}</td></tr>`;
        }).join(''):'<tr><td colspan="6" class="empty">Kapsamınızdaki Agent bulunamadı.</td></tr>';
    };
    const poll=async()=>{
        if(!document.hidden){
            try{const response=await fetch('../../api.php',{headers:{Accept:'application/json'},cache:'no-store'});const payload=await response.json();if(response.ok&&payload.ok)render(payload.data);}catch(error){}
        }
        window.setTimeout(poll,5000);
    };
    window.setTimeout(poll,5000);
})();
</script>
HTML;
}
supervisor_page_end();
