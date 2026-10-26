<?php
declare(strict_types=1);

session_name('ASTERA_CALLCENTER_SUPERVISOR');
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/callcenter.php';

if (empty($_SESSION['callcenter_supervisor'])) {
    header('Location: ' . (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/reports/') ? '../../login.php' : 'login.php'));
    exit;
}

$supervisor = (array) $_SESSION['callcenter_supervisor'];
if (!empty($supervisor['must_change_password'])) {
    header('Location: ' . (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/reports/') ? '../../change_password.php' : 'change_password.php'));
    exit;
}

$ownerDeptId = (string) $supervisor['dept_id'];
$deptId = (string) ($_SESSION['callcenter_supervisor_dept'] ?? $ownerDeptId);
if (!callcenter_supervisor_can_access_department($supervisor, $deptId)) {
    $deptId = $ownerDeptId;
    $_SESSION['callcenter_supervisor_dept'] = $deptId;
}
$isAdmin = ($supervisor['role'] ?? '') === 'admin';
$queues = $ownerDeptId === $deptId
    ? callcenter_supervisor_queues($deptId, (int) $supervisor['supervisor_id'])
    : array_values(callcenter_config_queues($deptId));
$queueIds = array_values(array_filter(array_map(
    static fn(array $queue): string => trim((string) ($queue['id'] ?? '')),
    $queues
)));

function supervisor_require_admin(): void
{
    global $isAdmin;
    if (!$isAdmin) {
        http_response_code(403);
        supervisor_page_start('Erişim reddedildi');
        echo '<div class="alert alert-danger">Bu sayfa yalnızca yöneticilere açıktır.</div>';
        supervisor_page_end();
        exit;
    }
}

function supervisor_date(string $name, ?string $default = null): string
{
    $value = trim((string) ($_GET[$name] ?? ($default ?? date('Y-m-d'))));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : ($default ?? date('Y-m-d'));
}

function supervisor_duration(int|float|string|null $seconds): string
{
    $seconds = max(0, (int) $seconds);
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}

function supervisor_bool(mixed $value): bool
{
    return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
}

function supervisor_queue_sql(string $column = 'queuename'): string
{
    global $queueIds;
    if (!$queueIds) {
        return 'FALSE';
    }
    return $column . ' IN (' . implode(',', array_map('callcenter_literal', $queueIds)) . ')';
}

function supervisor_agent_sql(string $alias = 'a'): string
{
    return 'EXISTS (SELECT 1 FROM callcenter_agent_queue_assignments saq '
        . "WHERE saq.dept_id = {$alias}.dept_id AND saq.agent_id = {$alias}.agent_id "
        . 'AND saq.active AND ' . supervisor_queue_sql('saq.queue_id') . ')';
}

function supervisor_nav_sections(): array
{
    return [
        'GENEL' => [
            ['dashboard.php', '⌂', 'Genel Bakış', 'dashboard_genel'],
            ['reports/callcenter/dashboard1.php', '▦', 'Callcenter Dashboard', 'dashboard_callcenter'],
            ['fop_abone.php', '◉', 'Canlı İzleme', 'canli_izleme'],
        ],
        'AYRINTILI RAPORLAR' => [
            ['reports/callcenter/kuyruk_detay.php', '☏', 'Kuyruk Detay', 'cc_kuyruk_detay'],
            ['reports/callcenter/agentdetay.php', '♙', 'Agent Detay', 'cc_agent_detay'],
            ['reports/callcenter/agent_logins.php', '↪', 'Login / Logout', 'cc_login_logout'],
            ['reports/callcenter/moladetay.php', 'Ⅱ', 'Mola Detay', 'cc_mola_raporlar'],
        ],
        'ÖZET RAPORLAR' => [
            ['reports/callcenter/agent_ozet1.php', '♟', 'Agent Özet', 'cc_agent_ozet'],
            ['reports/callcenter/agent_ozet2.php', '▤', 'Agent Özet 2', 'cc_agent_ozet'],
            ['reports/callcenter/agent_anket_ozet.php', '★', 'Agent Anket Özeti', 'cc_anket'],
            ['reports/callcenter/molaozet.php', '◷', 'Mola Özet', 'cc_mola_raporlar'],
            ['reports/callcenter/molaozettoplam.php', '◴', 'Mola Özet Toplam', 'cc_mola_raporlar'],
            ['reports/callcenter/kuyrukozet.php', '▥', 'Kuyruk Özet', 'cc_kuyruk_detay'],
            ['reports/callcenter/kuyruk_analiz.php', '⌕', 'Kuyruk Analiz', 'cc_kuyruk_detay'],
        ],
        'YÖNETİM' => [
            ['dashboard.php?tab=callcenter&subtab=sistem', '⚙', 'Sistem Tanımları', 'sistem_ayarlar'],
            ['reports/callcenter/agent_tanim.php', '♟', 'Agent Tanım', 'sistem_ayarlar'],
            ['reports/callcenter/agent_yonetim.php', '◎', 'Agent Yönetimi', 'sistem_ayarlar'],
            ['reports/callcenter/kuyruklistesi.php', '≡', 'Kuyruk Listesi', 'sistem_ayarlar'],
            ['reports/callcenter/kuyruksla.php', '◴', 'Kuyruk SLA', 'sistem_ayarlar'],
            ['reports/callcenter/mola_tanim.php', '◷', 'Mola Tanım', 'sistem_ayarlar'],
            ['reports/callcenter/anket_yonetim.php', '▣', 'Anket Yönetimi', 'cc_anket'],
            ['reports/callcenter/mailtanimlari.php', '✉', 'Mail Tanımları', 'sistem_ayarlar'],
            ['reports/callcenter/kullanicilar.php', '♙', 'Kullanıcılar', 'sistem_kullanicilar'],
            ['reports/callcenter/yetki_gruplari.php', '⌘', 'Yetki Grupları', 'sistem_yetki'],
            ['reports/callcenter/ayarlar.php', '⚙', 'Ayarlar', 'sistem_ayarlar'],
            ['reports/callcenter/lograpor.php', '☷', 'Log Raporu', 'log_rapor'],
        ],
    ];
}

function supervisor_page_permission(): ?string
{
    $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
    return [
        'dashboard' => 'dashboard_genel',
        'dashboard1' => 'dashboard_callcenter',
        'fop_abone' => 'canli_izleme',
        'kuyruk_detay' => 'cc_kuyruk_detay',
        'kuyrukozet' => 'cc_kuyruk_detay',
        'kuyruk_analiz' => 'cc_kuyruk_detay',
        'kuyruksla' => 'cc_kuyruk_detay',
        'agentdetay' => 'cc_agent_detay',
        'agent_logins' => 'cc_login_logout',
        'agent_ozet1' => 'cc_agent_ozet',
        'agent_ozet2' => 'cc_agent_ozet',
        'moladetay' => 'cc_mola_raporlar',
        'molaozet' => 'cc_mola_raporlar',
        'molaozettoplam' => 'cc_mola_raporlar',
        'agent_anket_ozet' => 'cc_anket',
        'anket' => 'cc_anket',
        'anket_yonetim' => 'cc_anket',
        'agent_tanim' => 'sistem_ayarlar',
        'agent_yonetim' => 'sistem_ayarlar',
        'kuyruklistesi' => 'sistem_ayarlar',
        'mola_tanim' => 'sistem_ayarlar',
        'ayarlar' => 'sistem_ayarlar',
        'mailtanimlari' => 'sistem_ayarlar',
        'kullanicilar' => 'sistem_kullanicilar',
        'yetki_gruplari' => 'sistem_yetki',
        'lograpor' => 'log_rapor',
    ][$page] ?? null;
}

function supervisor_require_permission(string $permission): void
{
    global $supervisor;
    if (callcenter_supervisor_has_permission($supervisor, $permission)) {
        return;
    }
    callcenter_audit_write(
        $supervisor,
        'access_denied',
        basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php'),
        'Yetkisiz erişim engellendi',
        'Gerekli yetki: ' . $permission
    );
    http_response_code(403);
    supervisor_page_start('Erişim Reddedildi', '');
    echo '<div class="alert alert-danger">Bu sayfa için <strong>' . e($permission)
        . '</strong> yetkisi gereklidir. Firma yöneticinizden erişim isteyin.</div>';
    supervisor_page_end();
    exit;
}

function supervisor_nav_path(string $path): string
{
    $fromReports = str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/reports/');
    return ($fromReports ? '../../' : '') . $path;
}

function supervisor_sidebar(): void
{
    global $isAdmin, $supervisor, $deptId;
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    echo '<aside class="app-sidebar" id="app-sidebar" aria-label="Ana menü">';
    echo '<a class="sidebar-brand" href="' . e(supervisor_nav_path('dashboard.php')) . '">';
    echo '<span class="sidebar-logo">A</span><span><strong>ASTERA</strong><small>CALL CENTER</small></span></a>';
    echo '<nav class="sidebar-nav">';
    foreach (supervisor_nav_sections() as $section => $items) {
        $visible = array_filter($items, static fn(array $item): bool =>
            empty($item[3]) || callcenter_supervisor_has_permission($supervisor, (string) $item[3])
        );
        if (!$visible) {
            continue;
        }
        $sectionKey = substr(hash('sha256', (string) $section), 0, 12);
        $sectionLinksId = 'sidebar-links-' . $sectionKey;
        echo '<div class="sidebar-section" data-sidebar-section="' . e($sectionKey) . '">';
        echo '<button class="sidebar-heading" type="button" data-section-toggle="' . e($sectionKey)
            . '" data-default-open="' . ($section === 'GENEL' ? 'true' : 'false')
            . '" aria-expanded="true" aria-controls="' . e($sectionLinksId) . '"><span>'
            . e($section) . '</span><span class="sidebar-heading-chevron" aria-hidden="true">⌄</span></button>';
        echo '<div class="sidebar-section-links" id="' . e($sectionLinksId) . '">';
        foreach ($visible as $item) {
            [$path, $icon, $label] = $item;
            $pathOnly = (string) (parse_url($path, PHP_URL_PATH) ?: $path);
            $pathMatches = str_ends_with($script, '/Supervisor/' . $pathOnly)
                || str_ends_with($script, '/Supervisor/' . str_replace('reports/callcenter/', '', $pathOnly));
            $itemQuery = (string) (parse_url($path, PHP_URL_QUERY) ?? '');
            $active = $pathMatches;
            if ($itemQuery !== '') {
                parse_str($itemQuery, $expectedQuery);
                $active = $pathMatches && array_reduce(
                    array_keys($expectedQuery),
                    static fn(bool $match, string $key): bool =>
                        $match && (string) ($_GET[$key] ?? '') === (string) $expectedQuery[$key],
                    true
                );
            } elseif ($pathOnly === 'dashboard.php' && !empty($_GET['subtab'])) {
                $active = false;
            }
            echo '<a class="sidebar-link' . ($active ? ' active' : '') . '" href="'
                . e(supervisor_nav_path($path)) . '"><span class="sidebar-icon" aria-hidden="true">'
                . e($icon) . '</span><span>' . e($label) . '</span></a>';
        }
        echo '</div></div>';
    }
    echo '</nav><div class="sidebar-account"><span class="presence-dot"></span><div><strong>'
        . e((string) $supervisor['display_name']) . '</strong><small>' . e($deptId) . ' · '
        . e((string) $supervisor['role']) . '</small></div></div></aside>';
    echo '<button class="sidebar-overlay" id="sidebar-overlay" type="button" aria-label="Menüyü kapat"></button>';
}

function supervisor_page_start(string $title, string $back = '../../dashboard.php?tab=callcenter&subtab=raporlar'): void
{
    global $supervisor, $deptId;
    $css = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/reports/') ? '../../assets/css/theme.css' : 'assets/css/theme.css';
    echo '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($title) . ' · Astera Callcenter</title><link rel="stylesheet" href="' . e($css) . '?v=28"></head><body class="supervisor-app">';
    echo '<div class="app-shell">';
    supervisor_sidebar();
    echo '<div class="app-workspace"><header class="app-topbar"><div class="topbar-title-wrap">';
    echo '<button class="menu-toggle" id="menu-toggle" type="button" aria-controls="app-sidebar" aria-expanded="false">☰</button>';
    echo '<div><h1>' . e($title) . '</h1><p>Bugün · ' . e(date('d.m.Y H:i')) . '</p></div></div>';
    echo '<div class="topbar-actions"><span class="tenant-pill"><span class="presence-dot"></span>'
        . e($deptId) . '</span>';
    if ($back !== '') {
        echo '<a class="btn btn-outline" href="' . e($back) . '">← Geri</a>';
    }
    echo '<form method="post" action="' . e(supervisor_nav_path('logout.php')) . '"><input type="hidden" name="csrf" value="'
        . e(csrf_token()) . '"><button class="icon-button" type="submit" title="Çıkış">↪</button></form>';
    echo '</div></header><main class="dashboard-container">';
}

function supervisor_page_end(): void
{
    echo '</main></div></div><script>
const menuButton=document.getElementById("menu-toggle"),sidebar=document.getElementById("app-sidebar"),overlay=document.getElementById("sidebar-overlay");
function setMenu(open){document.body.classList.toggle("sidebar-open",open);menuButton?.setAttribute("aria-expanded",open?"true":"false")}
menuButton?.addEventListener("click",()=>setMenu(!document.body.classList.contains("sidebar-open")));
overlay?.addEventListener("click",()=>setMenu(false));
window.addEventListener("keydown",event=>{if(event.key==="Escape")setMenu(false)});
const sectionStorageKey="astera.supervisor.sidebar.sections";
let sectionState={};
try{sectionState=JSON.parse(localStorage.getItem(sectionStorageKey)||"{}")||{}}catch(error){sectionState={}}
document.querySelectorAll("[data-section-toggle]").forEach(button=>{
 const key=button.dataset.sectionToggle,links=document.getElementById(button.getAttribute("aria-controls"));
 if(!links)return;
 const apply=open=>{button.setAttribute("aria-expanded",open?"true":"false");links.hidden=!open};
 const hasActive=!!links.querySelector(".sidebar-link.active");
 const hasStoredState=Object.prototype.hasOwnProperty.call(sectionState,key);
 apply(hasActive||(hasStoredState?sectionState[key]!==false:button.dataset.defaultOpen==="true"));
 button.addEventListener("click",()=>{
  const open=button.getAttribute("aria-expanded")!=="true";
  apply(open);sectionState[key]=open;
  try{localStorage.setItem(sectionStorageKey,JSON.stringify(sectionState))}catch(error){}
 });
});
document.addEventListener("click",event=>{
 const button=event.target.closest(".permission-select-all");if(!button)return;
 const section=button.closest("section"),boxes=[...(section?.querySelectorAll("input[type=checkbox]")||[])];
 const check=boxes.some(box=>!box.checked);boxes.forEach(box=>{box.checked=check});
 button.textContent=check?"Tümünü kaldır":"Tümünü seç";
});
</script></body></html>';
}

function supervisor_filter_bar(string $from, string $to): void
{
    echo '<form class="filter-bar" method="get"><label class="filter-group"><span class="filter-label">Başlangıç</span>';
    echo '<input class="filter-input" type="date" name="from" value="' . e($from) . '"></label>';
    echo '<label class="filter-group"><span class="filter-label">Bitiş</span>';
    echo '<input class="filter-input" type="date" name="to" value="' . e($to) . '"></label>';
    echo '<button class="btn btn-primary" type="submit">Raporla</button></form>';
}

function supervisor_notice(string $message, bool $error = false): void
{
    echo '<div class="alert ' . ($error ? 'alert-danger' : 'alert-success') . '">' . e($message) . '</div>';
}

$requiredPermission = supervisor_page_permission();
if ($requiredPermission !== null) {
    supervisor_require_permission($requiredPermission);
}
callcenter_audit_request($supervisor);
