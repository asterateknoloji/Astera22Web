<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$identity = $supervisor;
$identity['owner_dept_id'] = $ownerDeptId;
$identity['dept_id'] = $deptId;
$snapshot = callcenter_supervisor_snapshot($identity, true);

function fop_badge_class(string $state): string
{
    return match ($state) {
        'available' => 'badge-success',
        'talking', 'busy' => 'badge-danger',
        'ringing' => 'badge-info',
        'hold', 'break' => 'badge-warning',
        default => 'badge-neutral',
    };
}

function fop_extension_row(array $extension): string
{
    $state = (string) ($extension['state'] ?? 'unknown');
    $break = (string) ($extension['active_break'] ?? '');
    $agentName = (string) ($extension['agent_name'] ?? '');
    ob_start();
    ?>
    <tr class="fop-extension-row" data-extension="<?= e((string) ($extension['extension'] ?? '')) ?>"
        data-search="<?= e(strtolower(trim((string) ($extension['extension'] ?? '') . ' ' . (string) ($extension['name'] ?? '') . ' ' . $agentName))) ?>"
        data-state="<?= e($state) ?>">
        <td><strong><?= e((string) ($extension['extension'] ?? '')) ?></strong></td>
        <td><?= e((string) (($extension['name'] ?? '') ?: '—')) ?><?php if ($agentName !== ''): ?><small class="agent-detail-sub"><?= e($agentName) ?></small><?php endif; ?></td>
        <td><span class="badge <?= fop_badge_class($state) ?>"><?= e((string) ($extension['label'] ?? 'Bilinmiyor')) ?></span></td>
        <td><?= e((string) (($extension['peer'] ?? '') ?: '—')) ?></td>
        <td class="fop-duration" data-duration="<?= max(0, (int) ($extension['call_age'] ?? 0)) ?>"><?= e(supervisor_duration($extension['call_age'] ?? 0)) ?></td>
        <td><?php if ($break !== ''): ?><span class="badge badge-warning"><?= e($break) ?></span><?php elseif (!empty($extension['is_logged_in'])): ?><span class="badge badge-success">Hazır</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
    </tr>
    <?php
    return (string) ob_get_clean();
}

$onlineExtensionCount = count(array_filter(
    (array) ($snapshot['extensions'] ?? []),
    static fn(array $extension): bool => !in_array((string) ($extension['state'] ?? ''), ['offline', 'unknown'], true)
));
$activeCallCount = count(array_filter(
    (array) ($snapshot['extensions'] ?? []),
    static fn(array $extension): bool => in_array((string) ($extension['state'] ?? ''), ['talking', 'ringing'], true)
));
$waitingCount = count((array) ($snapshot['waiting_calls'] ?? []));

supervisor_page_start('Canlı İzleme', 'dashboard.php?tab=callcenter&subtab=raporlar');
?>
<div class="fop-toolbar">
    <div>
        <strong>Canlı Agent, Dahili ve Kuyruk İzleme</strong>
        <small>Agent durumları, görüşmeler ve bekleyen çağrılar tek ekranda</small>
    </div>
    <span class="live-update-note"><i class="fop-live-dot"></i> 8 saniyede bir güncellenir · <span id="live-updated-at">şimdi</span></span>
</div>

<?php if (empty($snapshot['live_available'])): ?><div class="alert alert-warning">Santral canlı verisinin bir bölümü alınamadı. PostgreSQL oturum ve mola bilgileri gösterilmeye devam ediyor.</div><?php endif; ?>

<section class="stats-grid fop-stats">
    <article class="stat-card"><div class="stat-label">Aktif Agent</div><div class="stat-value" id="live-active-count"><?= (int) ($snapshot['active_agent_count'] ?? 0) ?></div><small id="live-available-text"><?= (int) ($snapshot['available_agent_count'] ?? 0) ?> hazır</small></article>
    <article class="stat-card"><div class="stat-label">Online Dahili</div><div class="stat-value" id="live-extension-count"><?= $onlineExtensionCount ?></div><small><?= count((array) ($snapshot['extensions'] ?? [])) ?> tanımlı</small></article>
    <article class="stat-card"><div class="stat-label">Aktif Görüşme / Çalma</div><div class="stat-value" id="live-call-count"><?= $activeCallCount ?></div><small>Canlı hat durumu</small></article>
    <article class="stat-card"><div class="stat-label">Bekleyen Çağrı</div><div class="stat-value" id="live-waiting-count"><?= $waitingCount ?></div><small><?= count((array) ($snapshot['queue_live'] ?? [])) ?> kuyruk</small></article>
    <article class="stat-card"><div class="stat-label">Aktif Molada</div><div class="stat-value" id="live-break-count"><?= (int) ($snapshot['active_break_count'] ?? 0) ?></div><small>Oturumu açık Agentlar</small></article>
</section>

<section class="fop-monitor-guide">
    <div><strong>Dinleme</strong><small>Listen / Spy</small><span>Taraflar supervisorü duymaz.</span></div>
    <div><strong>Fısıldama</strong><small>Whisper</small><span>Yalnızca temsilci supervisorü duyar.</span></div>
    <div><strong>Araya girme</strong><small>Barge / Barge-in</small><span>Müşteri ve temsilci supervisorü duyar.</span></div>
</section>

<section class="fop-monitor-console">
    <div class="fop-monitor-fields">
        <label><span>Çalacak supervisor dahili</span><input id="fop-monitor-observer" inputmode="numeric" autocomplete="off" placeholder="Örn. 123"></label>
        <label><span>İzlenecek görüşmedeki dahili</span>
            <select id="fop-monitor-target">
                <option value="">Görüşmedeki dahiliyi seçin</option>
                <?php foreach ((array) ($snapshot['extensions'] ?? []) as $extension): ?>
                    <?php if ((string) ($extension['state'] ?? '') === 'talking'): ?>
                        <option value="<?= e((string) ($extension['extension'] ?? '')) ?>">
                            <?= e(trim((string) ($extension['extension'] ?? '') . ' ' . (string) ($extension['name'] ?? ''))) ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="fop-monitor-console-actions">
        <button class="btn btn-outline" type="button" data-monitor-mode="listen" onclick="window.fopStartMonitor(this)">Dinle</button>
        <button class="btn btn-outline" type="button" data-monitor-mode="whisper" onclick="window.fopStartMonitor(this)">Fısılda</button>
        <button class="btn btn-outline" type="button" data-monitor-mode="barge" onclick="window.fopStartMonitor(this)">Araya gir</button>
    </div>
    <small id="fop-monitor-status">İşlem sırasında supervisor telefonu çalar; cevaplandığında seçilen mod başlar.</small>
</section>

<div class="fop-filter-bar">
    <label><span>Dahili veya isim ara</span><input id="fop-search" type="search" placeholder="Örn. 123 veya Ahmet"></label>
    <label class="choice-row"><input id="fop-calling-only" type="checkbox"><span>Yalnız görüşen / çalan</span></label>
    <label class="choice-row"><input id="fop-show-offline" type="checkbox"><span>Çevrimdışı dahilileri göster</span></label>
    <button class="btn btn-outline" id="fop-refresh" type="button">Şimdi Yenile</button>
</div>

<div class="fop-live-layout">
    <section class="table-container fop-extension-panel">
        <div class="table-header"><span>Dahili Durumları</span><small id="fop-extension-visible"></small></div>
        <div class="fop-table-scroll">
            <table class="fop-extension-table">
                <thead><tr><th>Dahili</th><th>Açıklama / Agent</th><th>Hat Durumu</th><th>Görüşülen Numara</th><th>Süre</th><th>Agent Durumu</th></tr></thead>
                <tbody id="fop-extension-body">
                <?php foreach ((array) ($snapshot['extensions'] ?? []) as $extension): ?><?= fop_extension_row($extension) ?><?php endforeach; ?>
                <?php if (empty($snapshot['extensions'])): ?><tr><td colspan="6" class="empty">Firma için tanımlı dahili bulunamadı.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="fop-queue-panel">
        <div class="fop-queue-filter" id="fop-queue-filter">
            <strong>Kuyruklar</strong>
            <div>
            <?php foreach ((array) ($snapshot['queue_live'] ?? []) as $queue): ?>
                <label><input type="checkbox" value="<?= e((string) $queue['id']) ?>" checked><span><?= e((string) (($queue['name'] ?? '') ?: $queue['id'])) ?></span><b class="<?= (int) ($queue['waiting'] ?? 0) > 0 ? 'has-waiting' : '' ?>"><?= (int) ($queue['waiting'] ?? 0) ?></b></label>
            <?php endforeach; ?>
            </div>
        </div>
        <div id="fop-queue-list">
        <?php foreach ((array) ($snapshot['queue_live'] ?? []) as $queue): ?>
            <article class="fop-queue-card" data-queue="<?= e((string) $queue['id']) ?>">
                <header>
                    <div><strong><?= e((string) (($queue['name'] ?? '') ?: $queue['id'])) ?></strong><small><?= e((string) $queue['id']) ?></small></div>
                    <div class="fop-queue-kpis">
                        <span><b><?= (int) ($queue['waiting'] ?? 0) ?></b> Bekleyen</span>
                        <span><b><?= (int) ($queue['answered_today'] ?? 0) ?></b> Cevaplanan</span>
                        <span><b><?= (int) ($queue['missed_today'] ?? 0) ?></b> Kaçan</span>
                    </div>
                </header>
                <?php if (!empty($queue['waiting_calls'])): ?>
                <div class="fop-waiting-list"><strong>Bekleyen Çağrılar</strong>
                    <?php foreach ($queue['waiting_calls'] as $call): ?><div><span>#<?= (int) ($call['position'] ?? 1) ?> · <?= e((string) ($call['caller'] ?? 'Bilinmiyor')) ?></span><b class="fop-duration" data-duration="<?= max(0, (int) ($call['wait_seconds'] ?? 0)) ?>"><?= e(supervisor_duration($call['wait_seconds'] ?? 0)) ?></b></div><?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="fop-queue-member-summary">
                    <span class="badge badge-success"><?= (int) ($queue['available'] ?? 0) ?> müsait</span>
                    <span class="badge badge-danger"><?= (int) ($queue['busy'] ?? 0) ?> görüşmede</span>
                    <span class="badge badge-warning"><?= (int) ($queue['paused'] ?? 0) ?> molada</span>
                    <span class="badge badge-neutral"><?= (int) ($queue['unavailable'] ?? 0) ?> kullanılamaz</span>
                </div>
                <div class="fop-table-scroll">
                    <table class="fop-member-table"><thead><tr><th>Üye</th><th>Durum</th><th>Mola</th><th>Login</th><th>Çağrı</th></tr></thead><tbody>
                    <?php foreach ((array) ($queue['members'] ?? []) as $member): ?>
                        <tr><td><strong><?= e((string) (($member['extension'] ?? '') ?: '—')) ?></strong><small><?= e((string) (($member['name'] ?? '') ?: '—')) ?></small></td>
                            <td><span class="badge <?= fop_badge_class((string) ($member['status'] ?? 'unknown')) ?>"><?= e((string) ($member['status_label'] ?? 'Bilinmiyor')) ?></span></td>
                            <td><?= !empty($member['active_break']) ? '<span class="badge badge-warning">' . e((string) $member['active_break']) . '</span>' : (!empty($member['paused']) ? '<span class="badge badge-warning">Duraklatıldı</span>' : '—') ?></td>
                            <td class="fop-duration" data-duration="<?= max(0, (int) ($member['login_seconds'] ?? 0)) ?>"><?= e(supervisor_duration($member['login_seconds'] ?? 0)) ?></td>
                            <td><?= (int) ($member['calls_taken'] ?? 0) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($queue['members'])): ?><tr><td colspan="5" class="empty">Kuyrukta canlı üye yok.</td></tr><?php endif; ?>
                    </tbody></table>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (empty($snapshot['queue_live'])): ?><div class="empty card">Yetki kapsamınızda kuyruk bulunamadı.</div><?php endif; ?>
        </div>
    </section>
</div>
<div class="alert">İşlem başlatıldığında girdiğiniz kayıtlı supervisor dahili çalar. Telefonu cevaplayınca seçilen mod başlar.</div>
<script>
(()=>{
    const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const duration=value=>{let seconds=Math.max(0,Number(value)||0);const hours=Math.floor(seconds/3600);seconds%=3600;const minutes=Math.floor(seconds/60);return [hours,minutes,seconds%60].map(number=>String(number).padStart(2,'0')).join(':');};
    const badgeClass=state=>({available:'badge-success',talking:'badge-danger',busy:'badge-danger',ringing:'badge-info',hold:'badge-warning'}[state]||'badge-neutral');
    const search=document.getElementById('fop-search');
    const callingOnly=document.getElementById('fop-calling-only');
    const showOffline=document.getElementById('fop-show-offline');
    const monitorObserver=document.getElementById('fop-monitor-observer');
    const monitorTarget=document.getElementById('fop-monitor-target');
    const monitorStatus=document.getElementById('fop-monitor-status');
    const csrf=<?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let refreshing=false;
    let nextRefresh=null;

    const applyExtensionFilters=()=>{
        const term=(search?.value||'').trim().toLocaleLowerCase('tr-TR');
        let visible=0;
        document.querySelectorAll('.fop-extension-row').forEach(row=>{
            const state=row.dataset.state||'unknown';
            const show=(!term||(row.dataset.search||'').toLocaleLowerCase('tr-TR').includes(term))
                &&(!callingOnly?.checked||['talking','ringing'].includes(state))
                &&(showOffline?.checked||!['offline','unknown'].includes(state));
            row.hidden=!show;if(show)visible++;
        });
        const label=document.getElementById('fop-extension-visible');if(label)label.textContent=`${visible} dahili`;
        localStorage.setItem('fopCallingOnly',callingOnly?.checked?'1':'0');
        localStorage.setItem('fopShowOffline',showOffline?.checked?'1':'0');
    };
    const extensionRows=extensions=>extensions.length?extensions.map(extension=>{
        const state=extension.state||'unknown';
        const agentState=extension.active_break
            ?`<span class="badge badge-warning">${esc(extension.active_break)}</span>`
            :(extension.is_logged_in?'<span class="badge badge-success">Hazır</span>':'<span class="muted">—</span>');
        const searchText=`${extension.extension||''} ${extension.name||''} ${extension.agent_name||''}`.toLocaleLowerCase('tr-TR');
        return `<tr class="fop-extension-row" data-extension="${esc(extension.extension)}" data-search="${esc(searchText)}" data-state="${esc(state)}"><td><strong>${esc(extension.extension)}</strong></td><td>${esc(extension.name||'—')}${extension.agent_name?`<small class="agent-detail-sub">${esc(extension.agent_name)}</small>`:''}</td><td><span class="badge ${badgeClass(state)}">${esc(extension.label||'Bilinmiyor')}</span></td><td>${esc(extension.peer||'—')}</td><td class="fop-duration" data-duration="${Number(extension.call_age)||0}">${duration(extension.call_age)}</td><td>${agentState}</td></tr>`;
    }).join(''):'<tr><td colspan="6" class="empty">Firma için tanımlı dahili bulunamadı.</td></tr>';
    const memberRows=members=>members.length?members.map(member=>
        `<tr><td><strong>${esc(member.extension||'—')}</strong><small>${esc(member.name||'—')}</small></td><td><span class="badge ${badgeClass(member.status)}">${esc(member.status_label||'Bilinmiyor')}</span></td><td>${member.active_break?`<span class="badge badge-warning">${esc(member.active_break)}</span>`:(member.paused?'<span class="badge badge-warning">Duraklatıldı</span>':'—')}</td><td class="fop-duration" data-duration="${Number(member.login_seconds)||0}">${duration(member.login_seconds)}</td><td>${Number(member.calls_taken)||0}</td></tr>`
    ).join(''):'<tr><td colspan="5" class="empty">Kuyrukta canlı üye yok.</td></tr>';
    const queueCards=queues=>queues.map(queue=>{
        const waits=(queue.waiting_calls||[]).length?`<div class="fop-waiting-list"><strong>Bekleyen Çağrılar</strong>${queue.waiting_calls.map(call=>`<div><span>#${Number(call.position)||1} · ${esc(call.caller||'Bilinmiyor')}</span><b class="fop-duration" data-duration="${Number(call.wait_seconds)||0}">${duration(call.wait_seconds)}</b></div>`).join('')}</div>`:'';
        return `<article class="fop-queue-card" data-queue="${esc(queue.id)}"><header><div><strong>${esc(queue.name||queue.id)}</strong><small>${esc(queue.id)}</small></div><div class="fop-queue-kpis"><span><b>${Number(queue.waiting)||0}</b> Bekleyen</span><span><b>${Number(queue.answered_today)||0}</b> Cevaplanan</span><span><b>${Number(queue.missed_today)||0}</b> Kaçan</span></div></header>${waits}<div class="fop-queue-member-summary"><span class="badge badge-success">${Number(queue.available)||0} müsait</span><span class="badge badge-danger">${Number(queue.busy)||0} görüşmede</span><span class="badge badge-warning">${Number(queue.paused)||0} molada</span><span class="badge badge-neutral">${Number(queue.unavailable)||0} kullanılamaz</span></div><div class="fop-table-scroll"><table class="fop-member-table"><thead><tr><th>Üye</th><th>Durum</th><th>Mola</th><th>Login</th><th>Çağrı</th></tr></thead><tbody>${memberRows(queue.members||[])}</tbody></table></div></article>`;
    }).join('');
    const selectedQueues=()=>JSON.parse(localStorage.getItem('fopSelectedQueues')||'null');
    const applyQueueFilters=()=>{
        const checked=[...document.querySelectorAll('#fop-queue-filter input:checked')].map(input=>input.value);
        localStorage.setItem('fopSelectedQueues',JSON.stringify(checked));
        document.querySelectorAll('.fop-queue-card').forEach(card=>card.hidden=!checked.includes(card.dataset.queue));
    };
    const renderQueueFilters=queues=>{
        const saved=selectedQueues();
        document.querySelector('#fop-queue-filter>div').innerHTML=queues.map(queue=>{
            const checked=!Array.isArray(saved)||saved.includes(String(queue.id));
            return `<label><input type="checkbox" value="${esc(queue.id)}"${checked?' checked':''}><span>${esc(queue.name||queue.id)}</span><b class="${Number(queue.waiting)>0?'has-waiting':''}">${Number(queue.waiting)||0}</b></label>`;
        }).join('');
        document.querySelectorAll('#fop-queue-filter input').forEach(input=>input.addEventListener('change',applyQueueFilters));
    };
    const renderMonitorTargets=extensions=>{
        const selected=monitorTarget?.value||'';
        const talking=extensions.filter(extension=>extension.state==='talking');
        if(!monitorTarget)return;
        monitorTarget.innerHTML='<option value="">Görüşmedeki dahiliyi seçin</option>'+talking.map(extension=>
            `<option value="${esc(extension.extension)}">${esc(`${extension.extension||''} ${extension.name||''}`.trim())}</option>`
        ).join('');
        if(talking.some(extension=>String(extension.extension)===selected))monitorTarget.value=selected;
        else if(talking.length===1)monitorTarget.value=String(talking[0].extension);
    };
    const render=data=>{
        document.getElementById('live-active-count').textContent=data.active_agent_count??0;
        document.getElementById('live-available-text').textContent=`${data.available_agent_count??0} hazır`;
        document.getElementById('live-break-count').textContent=data.active_break_count??0;
        const extensions=data.extensions??[];
        document.getElementById('live-extension-count').textContent=extensions.filter(item=>!['offline','unknown'].includes(item.state)).length;
        document.getElementById('live-call-count').textContent=extensions.filter(item=>['talking','ringing'].includes(item.state)).length;
        document.getElementById('live-waiting-count').textContent=(data.waiting_calls??[]).length;
        document.getElementById('live-updated-at').textContent=new Date(data.generated_at??Date.now()).toLocaleTimeString('tr-TR');
        document.getElementById('fop-extension-body').innerHTML=extensionRows(extensions);
        renderMonitorTargets(extensions);
        const queues=data.queue_live??[];
        renderQueueFilters(queues);
        document.getElementById('fop-queue-list').innerHTML=queues.length?queueCards(queues):'<div class="empty card">Yetki kapsamınızda kuyruk bulunamadı.</div>';
        applyQueueFilters();applyExtensionFilters();
    };
    const poll=async()=>{
        clearTimeout(nextRefresh);
        if(!document.hidden&&!refreshing){
            refreshing=true;
            document.getElementById('fop-refresh')?.classList.add('is-loading');
            try{const response=await fetch('api.php?view=fop',{headers:{Accept:'application/json'},cache:'no-store'});const payload=await response.json();if(response.ok&&payload.ok)render(payload.data);}catch(error){}
            finally{refreshing=false;document.getElementById('fop-refresh')?.classList.remove('is-loading');}
        }
        nextRefresh=window.setTimeout(poll,8000);
    };
    callingOnly.checked=localStorage.getItem('fopCallingOnly')==='1';
    showOffline.checked=localStorage.getItem('fopShowOffline')==='1';
    [search,callingOnly,showOffline].forEach(control=>control?.addEventListener('input',applyExtensionFilters));
    document.querySelectorAll('#fop-queue-filter input').forEach(input=>input.addEventListener('change',applyQueueFilters));
    document.getElementById('fop-refresh')?.addEventListener('click',poll);
    window.fopStartMonitor=async button=>{
        if(!button||button.disabled)return;
        const mode=button.dataset.monitorMode;
        const target=monitorTarget?.value||'';
        const cleanObserver=(monitorObserver?.value||'').trim();
        if(!cleanObserver){window.alert('Çalacak supervisor dahilisini girin.');monitorObserver?.focus();return;}
        if(!target){window.alert('İzlenecek görüşmedeki dahiliyi seçin.');monitorTarget?.focus();return;}
        localStorage.setItem('fopObserverExtension',cleanObserver);
        const buttons=[...document.querySelectorAll('.fop-monitor-console [data-monitor-mode]')];
        buttons.forEach(item=>item.disabled=true);
        if(monitorStatus)monitorStatus.textContent='İşlem başlatılıyor…';
        try{
            const body=new FormData();
            body.set('csrf',csrf);body.set('action','monitor_start');body.set('mode',mode);
            body.set('observer_extension',cleanObserver);body.set('target_extension',target);
            const response=await fetch('api.php',{method:'POST',headers:{Accept:'application/json'},body});
            const payload=await response.json();
            if(!response.ok||!payload.ok)throw new Error(payload.error||'İşlem başlatılamadı');
            if(monitorStatus)monitorStatus.textContent=payload.message;
            window.alert(payload.message);
        }catch(error){
            const message=error.message||'İşlem başlatılamadı';
            if(monitorStatus)monitorStatus.textContent=message;
            window.alert(message);
        }finally{buttons.forEach(item=>item.disabled=false);poll();}
    };
    if(monitorObserver){
        monitorObserver.value=localStorage.getItem('fopObserverExtension')||'';
        monitorObserver.addEventListener('input',()=>localStorage.setItem('fopObserverExtension',monitorObserver.value.trim()));
    }
    applyExtensionFilters();applyQueueFilters();
    window.setInterval(()=>document.querySelectorAll('.fop-duration[data-duration]').forEach(element=>{
        const state=element.closest('.fop-extension-row')?.dataset.state;
        if(!state||['talking','ringing'].includes(state)||element.closest('.fop-waiting-list')||element.closest('.fop-member-table')){
            element.dataset.duration=String((Number(element.dataset.duration)||0)+1);
            element.textContent=duration(element.dataset.duration);
        }
    }),1000);
    nextRefresh=window.setTimeout(poll,8000);
})();
</script>
<?php supervisor_page_end(); ?>
