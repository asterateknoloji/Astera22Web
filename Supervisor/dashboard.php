<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$systemView = (string) ($_GET['subtab'] ?? '') === 'sistem';
if ($systemView) {
    $systemItems = [
        ['reports/callcenter/agent_tanim.php', '♟', 'Agent Tanım', 'Agent hesapları, dahili ve kuyruk atamaları', 'sistem_ayarlar'],
        ['reports/callcenter/kuyruklistesi.php', '≡', 'Kuyruk Listesi', 'PBX kuyruklarını ve üyelerini görüntüleyin', 'sistem_ayarlar'],
        ['reports/callcenter/mola_tanim.php', '◷', 'Mola Tanım', 'Firma mola türlerini ve sürelerini yönetin', 'sistem_ayarlar'],
        ['reports/callcenter/ayarlar.php', '⚙', 'Ayarlar', 'Firmaya özel sistem ayarlarını yönetin', 'sistem_ayarlar'],
        ['reports/callcenter/kullanicilar.php', '♙', 'Kullanıcılar', 'Supervisor kullanıcılarını ve kapsamlarını yönetin', 'sistem_kullanicilar'],
        ['reports/callcenter/agent_yonetim.php', '◎', 'Agent Yönetimi', 'Agent oturumlarını ve hesap durumlarını izleyin', 'sistem_ayarlar'],
        ['reports/callcenter/yetki_gruplari.php', '⌘', 'Yetki Grupları', 'Ekran ve rapor erişimlerini yönetin', 'sistem_yetki'],
        ['reports/callcenter/lograpor.php', '☷', 'Log Raporu', 'Sistem erişim ve işlem kayıtlarını inceleyin', 'log_rapor'],
        ['reports/callcenter/kuyruksla.php', '◴', 'Kuyruk SLA', 'Kuyruk cevaplama hedeflerini tanımlayın', 'sistem_ayarlar'],
        ['reports/callcenter/anket_yonetim.php', '▣', 'Anket Yönetimi', 'Anket, kategori ve soruları yönetin', 'cc_anket'],
        ['reports/callcenter/mailtanimlari.php', '✉', 'Mail Tanımları', 'Kuyruk bildirim alıcılarını yönetin', 'sistem_ayarlar'],
    ];
    supervisor_page_start('Sistem Tanımları', '');
    ?>
    <div class="dashboard-welcome">
        <div><span class="eyebrow">CALLCENTER YÖNETİMİ</span><h2>Sistem Tanımları</h2>
            <p>Firma kapsamındaki Agent, kuyruk, güvenlik, bildirim ve kalite ayarlarını tek noktadan yönetin.</p></div>
    </div>
    <section class="section-panel system-definition-panel">
        <div class="section-header"><div class="section-title">Yönetim Ekranları</div></div>
        <div class="report-grid system-definition-grid">
        <?php foreach ($systemItems as [$path, $icon, $label, $description, $permission]):
            if (!callcenter_supervisor_has_permission($supervisor, $permission)) continue;
        ?>
            <a class="report-item system-definition-item" href="<?= e($path) ?>">
                <span class="report-icon"><?= e($icon) ?></span>
                <span class="report-label"><?= e($label) ?></span>
                <small><?= e($description) ?></small>
            </a>
        <?php endforeach; ?>
        </div>
    </section>
    <?php
    supervisor_page_end();
    exit;
}

$canChoose = $isAdmin && !empty($supervisor['can_choose_tenant']);
$allowedDepartments = callcenter_supervisor_allowed_departments($supervisor);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dept']) && csrf_ok() && $canChoose) {
    $candidate = trim((string) $_POST['dept']);
    if (callcenter_supervisor_can_access_department($supervisor, $candidate)) {
        $_SESSION['callcenter_supervisor_dept'] = $candidate;
    }
    header('Location: dashboard.php');
    exit;
}
try {
    $dashboardIdentity = $supervisor;
    $dashboardIdentity['owner_dept_id'] = $ownerDeptId;
    $dashboardIdentity['dept_id'] = $deptId;
    $dashboardSnapshot = callcenter_supervisor_snapshot($dashboardIdentity);
} catch (Throwable $exception) {
    error_log('Supervisor dashboard snapshot failed: ' . $exception->getMessage());
    $dashboardSnapshot = [
        'agents' => [], 'queues' => [], 'queue_stats' => [], 'recent_events' => [],
        'active_agent_count' => 0, 'available_agent_count' => 0, 'active_break_count' => 0,
    ];
}
$activeAgents = (int) ($dashboardSnapshot['active_agent_count'] ?? 0);
$activeBreaks = (int) ($dashboardSnapshot['active_break_count'] ?? 0);
$offeredToday = array_sum(array_column($dashboardSnapshot['queue_stats'], 'offered'));
$answeredToday = array_sum(array_column($dashboardSnapshot['queue_stats'], 'answered'));
$answerRate = $offeredToday > 0 ? round($answeredToday * 100 / $offeredToday, 1) : 0;

supervisor_page_start('Genel Bakış', '');
?>
<div class="dashboard-welcome">
    <div>
        <span class="eyebrow">OPERASYON MERKEZİ</span>
        <h2>Merhaba, <?= e((string) $supervisor['display_name']) ?></h2>
        <p>Çağrı merkezi performansını, Agent durumlarını ve sistem tanımlarını tek noktadan yönetin.</p>
    </div>
    <?php if ($canChoose && count($allowedDepartments) > 1): ?>
        <form class="tenant-switch" method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label><span>İzlenen firma</span><select name="dept"><?php foreach ($allowedDepartments as $department): ?><option value="<?= e((string) $department['id']) ?>" <?= (string) $department['id'] === $deptId ? 'selected' : '' ?>><?= e((string) ($department['name'] ?? $department['id'])) ?></option><?php endforeach; ?></select></label>
            <button class="btn btn-primary">Değiştir</button>
        </form>
    <?php endif; ?>
</div>
<section class="overview-grid" aria-label="Güncel durum">
    <article class="overview-card"><span>Aktif Agent</span><strong id="overview-active-agents"><?= $activeAgents ?></strong><small><i class="status-dot ok"></i> Giriş yapmış Agent</small></article>
    <article class="overview-card"><span>Cevaplama Oranı</span><strong>%<?= e((string) $answerRate) ?></strong><small><?= $answeredToday ?> / <?= $offeredToday ?> çağrı cevaplandı</small></article>
    <article class="overview-card"><span>Aktif Mola</span><strong id="overview-active-breaks"><?= $activeBreaks ?></strong><small><i id="overview-break-dot" class="status-dot <?= $activeBreaks ? 'warn' : 'ok' ?>"></i> Anlık Agent durumu</small></article>
    <article class="overview-card accent"><span>Kuyruk Sayısı</span><strong><?= count($dashboardSnapshot['queues']) ?></strong><small>Canlı PBX yapılandırması</small></article>
</section>
<p class="live-update-note">Canlı durum · <span id="overview-updated-at">şimdi</span></p>
<section class="dashboard-detail-grid">
    <article class="table-container live-agent-panel">
        <div class="table-header">Aktif Agentlar</div>
        <table><thead><tr><th>Agent</th><th>Dahili</th><th>Durum</th><th>PJSIP</th><th>Mola</th><th>Kuyruklar</th></tr></thead>
            <tbody id="overview-agent-body">
            <?php foreach ($dashboardSnapshot['agents'] as $agent): if (empty($agent['is_logged_in'])) continue; ?>
                <tr><td><?= e((string) $agent['display_name']) ?></td><td><?= e((string) $agent['extension']) ?></td>
                    <td><span class="badge <?= !empty($agent['active_break']) ? 'badge-warning' : 'badge-success' ?>"><?= !empty($agent['active_break']) ? 'Molada' : 'Hazır' ?></span></td>
                    <td><?= e((string) ($agent['live']['label'] ?? 'Bilinmiyor')) ?></td>
                    <td><?= e((string) ($agent['active_break'] ?: '—')) ?></td><td><?= e((string) ($agent['queue_names'] ?: '—')) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$activeAgents): ?><tr><td colspan="6" class="empty">Giriş yapmış Agent bulunmuyor.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </article>
    <article class="table-container">
        <div class="table-header">Bugünkü Kuyruk Performansı</div>
        <table>
            <thead><tr><th>Kuyruk</th><th>Çağrı Sayısı</th><th>Cevaplanan</th><th>Kaçan</th></tr></thead>
            <tbody id="overview-queue-body">
            <?php foreach ($dashboardSnapshot['queue_stats'] as $queue): ?>
                <tr><td><?= e((string) $queue['queuename']) ?></td><td><?= (int) $queue['offered'] ?></td><td><?= (int) $queue['answered'] ?></td><td><?= (int) $queue['missed'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$dashboardSnapshot['queue_stats']): ?><tr><td colspan="4" class="empty">Bugün kuyruk hareketi bulunmuyor.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </article>
    <article class="table-container">
        <div class="table-header">Son Kuyruk Olayları</div>
        <table>
            <thead><tr><th>Zaman</th><th>Kuyruk</th><th>Agent</th><th>Olay</th></tr></thead>
            <tbody id="overview-event-body">
            <?php foreach (array_slice($dashboardSnapshot['recent_events'], 0, 10) as $event): ?>
                <tr><td><?= e((string) $event['time']) ?></td><td><?= e((string) $event['queuename']) ?></td><td><?= e((string) ($event['agent'] ?: '—')) ?></td><td><span class="badge"><?= e((string) $event['event']) ?></span></td></tr>
            <?php endforeach; ?>
            <?php if (!$dashboardSnapshot['recent_events']): ?><tr><td colspan="4" class="empty">Henüz kuyruk olayı bulunmuyor.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </article>
</section>
<script>
(()=>{
    const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const render=data=>{
        document.getElementById('overview-active-agents').textContent=data.active_agent_count??0;
        document.getElementById('overview-active-breaks').textContent=data.active_break_count??0;
        document.getElementById('overview-break-dot').classList.toggle('warn',(data.active_break_count??0)>0);
        document.getElementById('overview-updated-at').textContent=new Date(data.generated_at??Date.now()).toLocaleTimeString('tr-TR');
        const agents=(data.agents??[]).filter(agent=>agent.is_logged_in);
        document.getElementById('overview-agent-body').innerHTML=agents.length?agents.map(agent=>`<tr><td>${esc(agent.display_name)}</td><td>${esc(agent.extension)}</td><td><span class="badge ${agent.active_break?'badge-warning':'badge-success'}">${agent.active_break?'Molada':'Hazır'}</span></td><td>${esc(agent.live?.label??'Bilinmiyor')}</td><td>${esc(agent.active_break||'—')}</td><td>${esc(agent.queue_names||'—')}</td></tr>`).join(''):'<tr><td colspan="6" class="empty">Giriş yapmış Agent bulunmuyor.</td></tr>';
        const queues=data.queue_stats??[];
        document.getElementById('overview-queue-body').innerHTML=queues.length?queues.map(queue=>`<tr><td>${esc(queue.queuename)}</td><td>${Number(queue.offered||0)}</td><td>${Number(queue.answered||0)}</td><td>${Number(queue.missed||0)}</td></tr>`).join(''):'<tr><td colspan="4" class="empty">Bugün kuyruk hareketi bulunmuyor.</td></tr>';
        const events=(data.recent_events??[]).slice(0,10);
        document.getElementById('overview-event-body').innerHTML=events.length?events.map(event=>`<tr><td>${esc(event.time)}</td><td>${esc(event.queuename)}</td><td>${esc(event.agent||'—')}</td><td><span class="badge">${esc(event.event)}</span></td></tr>`).join(''):'<tr><td colspan="4" class="empty">Henüz kuyruk olayı bulunmuyor.</td></tr>';
    };
    const poll=async()=>{
        if(!document.hidden){
            try{const response=await fetch('api.php',{headers:{Accept:'application/json'},cache:'no-store'});const payload=await response.json();if(response.ok&&payload.ok)render(payload.data);}catch(error){}
        }
        window.setTimeout(poll,5000);
    };
    window.setTimeout(poll,5000);
})();
</script>
<?php supervisor_page_end(); ?>
