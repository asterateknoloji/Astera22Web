<?php
require_once __DIR__ . '/ui_icons.php';

$currentPage = current_page();
$navItems = [
    ['page' => 'dashboard', 'label' => 'Özet', 'icon' => 'layout-dashboard'],
];
if (is_super()) {
    $navItems[] = ['page' => 'departments', 'label' => 'Firmalar', 'icon' => 'building-2'];
}
$navItems[] = ['page' => 'crm', 'label' => 'Fihrist Kayıtları', 'icon' => 'book-user'];
$navItems[] = ['group' => 'Aboneler', 'icon' => 'users', 'sections' => [[null, [
    'extensions' => 'Dahili',
    'webrtc' => 'WebPhone',
    'bulk' => 'Toplu ekle',
    'features' => 'Özellik kodları',
    'provisioning' => 'Telefon Autoprovision',
]]]];
$navItems[] = ['group' => 'Uygulamalar', 'icon' => 'layout-grid', 'sections' => [[null, [
    'queues' => 'Kuyruklar',
    'ringgroups' => 'Ring grup',
    'ivr' => 'IVR',
    'time' => 'Zaman koşulu',
    'announcements' => 'Duyurular',
    'conferences' => 'Konferans',
    'parking' => 'Park',
    'flow' => 'Gündüz / gece',
    'disa' => 'DISA',
    'paging' => 'Anons',
    'speed' => 'Hızlı arama',
    'custom' => 'Özel hedef',
    'urltriggers' => 'CRM / URL Tetikleme',
]]]];
$navItems[] = ['group' => 'Hatlar', 'icon' => 'route', 'sections' => [[null, [
    'trunks' => 'Dış hat',
    'pri' => 'PRI / DAHDI',
    'outbound' => 'Giden hat',
    'inbound' => 'Gelen hat',
    'blacklist' => 'Kara liste',
]]]];
$navItems[] = ['group' => 'Raporlar', 'icon' => 'chart-column', 'sections' => [[null, [
    'cdr' => 'Çağrı listesi',
    'reports' => 'Trunk raporu',
    'queue_missed_calls' => 'Kuyruk kaçan çağrılar',
    'queue_log' => 'Kuyruk olayları',
]]], 'hidden' => ['recordings' => 'Ses kayıtları']];
$navItems[] = ['group' => 'Medya', 'icon' => 'audio-lines', 'sections' => [[null, [
    'sounds' => 'Sistem sesleri',
]]]];
if (is_super()) {
    $maintenance = ['license' => 'Lisanslama'];
    if (license_issuer_enabled()) {
        $maintenance['license_manager'] = 'Vendor lisans yöneticisi';
    }
    $maintenance += [
        'alarmlar' => 'Alarmlar',
        'backup' => 'Backup',
        'system' => 'Sistem / Güç',
        'logs' => 'Loglar',
    ];
    $navItems[] = ['group' => 'Sistem', 'icon' => 'settings', 'sections' => [
        ['Ağ ve erişim', ['network' => 'Ethernet Ayarları', 'ntp' => 'NTP Ayarları', 'ssl' => 'SSL Sertifikası']],
        ['Güvenlik', ['security' => 'Güvenlik / IP İzinleri', 'fail2ban' => 'Fail2ban']],
        ['Santral servisleri', ['ami' => 'AMI Kullanıcıları', 'contexts' => 'Dialplan context’leri', 'integrations' => 'Entegrasyonlar']],
        ['Bakım ve izleme', $maintenance],
    ]];
}

$pageMeta = [
    'dashboard' => ['Santral özeti', 'Bugünkü çağrılar, kayıtlı aboneler ve sunucu durumu.'],
    'departments' => ['Firmalar / departmanlar', 'Firmaları, panel kullanıcılarını ve abone aralıklarını yönetin.'],
    'crm' => ['Fihrist Kayıtları', 'Müşteri ve kişi kayıtlarını arayın, düzenleyin.'],
    'extensions' => ['Dahili aboneler', 'SIP, DAHDI ve özel kanal abonelerini yönetin.'],
    'webrtc' => ['WebPhone', 'Tarayıcı tabanlı telefon ve BLF paneli.'],
    'bulk' => ['Toplu abone ekle', 'Belirli aralıkta çok sayıda dahili oluşturun.'],
    'features' => ['Özellik kodları', 'Yıldızlı servis kodlarını ve kısayolları düzenleyin.'],
    'provisioning' => ['Telefon autoprovision', 'IP telefonların otomatik yapılandırması ve tuş atamaları.'],
    'trunks' => ['Dış hat (trunk)', 'SIP operatör bağlantılarını ve kayıt durumlarını yönetin.'],
    'pri' => ['PRI / DAHDI ayarları', 'E1/PRI kart ve kanal yapılandırması.'],
    'outbound' => ['Giden arama kuralları', 'Numara desenlerine göre dış hat seçimi.'],
    'inbound' => ['Gelen arama kuralları', 'DID numaralarının yönlendirileceği hedefler.'],
    'queues' => ['Çağrı kuyrukları', 'Kuyruk stratejileri, üyeler ve bekleme ayarları.'],
    'ringgroups' => ['Ring grupları', 'Aynı anda ya da sırayla çalan dahili grupları.'],
    'ivr' => ['IVR / sesli menü', 'Tuşlamalı karşılama menüleri ve hedefleri.'],
    'time' => ['Zaman koşulları', 'Mesai saatlerine göre çağrı yönlendirme.'],
    'announcements' => ['Duyurular', 'Ses dosyası çalıp hedefe yönlendiren duyurular.'],
    'conferences' => ['Konferans odaları', 'Çok katılımcılı görüşme odaları ve PIN ayarları.'],
    'parking' => ['Çağrı parkı', 'Park alanı ve geri çağırma ayarları.'],
    'flow' => ['Gündüz / gece', 'Elle değiştirilebilen gündüz/gece akış anahtarları.'],
    'disa' => ['DISA', 'Dışarıdan santral üzerinden arama erişimi.'],
    'paging' => ['Anons / page', 'Hoparlör ve dahili gruplarına anons.'],
    'speed' => ['Hızlı arama', 'Kısa kodla aranan kayıtlı numaralar.'],
    'custom' => ['Özel hedefler', 'Dialplan içindeki özel hedef tanımları.'],
    'urltriggers' => ['CRM / URL tetikleme', 'Çağrı olaylarında harici sistemlere istek gönderin.'],
    'sounds' => ['Sistem sesleri ve MOH', 'Sistem sesleri, yüklenen kayıtlar ve bekleme müziği.'],
    'blacklist' => ['Kara liste', 'Engellenen arayan numaraları.'],
    'recordings' => ['Ses kayıtları', 'Görüşme kayıtlarını dinleyin ve indirin.'],
    'reports' => ['Trunk raporu', 'Dış hat bazında çağrı ve süre raporları.'],
    'cdr' => ['Çağrı listesi', 'Çağrı detay kayıtlarını filtreleyin ve dışa aktarın.'],
    'queue_log' => ['Kuyruk olayları', 'Kuyruk giriş, cevap ve terk olayları.'],
    'queue_missed_calls' => ['Kuyruk kaçan çağrılar', 'Kuyrukta cevapsız kalan ve geri aranacak çağrılar.'],
    'contexts' => ['Dialplan context’leri', 'Firma bazlı dialplan context tanımları.'],
    'logs' => ['Asterisk log', 'Santral günlüklerini filtreleyip inceleyin.'],
    'security' => ['Güvenlik / IP izinleri', 'Güvenlik duvarı, izinli ağlar ve ülke filtresi.'],
    'fail2ban' => ['Fail2ban', 'Hatalı giriş denemelerine karşı otomatik engelleme.'],
    'ssl' => ['SSL sertifikası', 'Panel ve WebRTC için TLS sertifikası.'],
    'network' => ['Ethernet ayarları', 'Ağ arayüzleri, IP adresleri ve yönlendirme.'],
    'ntp' => ['NTP Ayarları', 'Sunucu saat dilimi ve zaman senkronizasyonu.'],
    'ami' => ['AMI kullanıcıları', 'Asterisk Manager Interface erişim hesapları.'],
    'license' => ['Lisanslama', 'Lisans durumu, limitler ve lisans içe aktarma.'],
    'license_manager' => ['Vendor lisans yöneticisi', 'Müşteri lisanslarını üretin ve yönetin.'],
    'alarmlar' => ['Alarmlar', 'Kaynak, servis ve çağrı alarmları.'],
    'integrations' => ['Entegrasyonlar', 'Syslog ve harici sistem entegrasyonları.'],
    'backup' => ['Backup', 'Yapılandırma ve veri yedekleri.'],
    'system' => ['Sistem', 'Servis durumu, yeniden başlatma ve kapatma.'],
];
[$pageTitle, $pageDescription] = $pageMeta[$currentPage] ?? ['ASTERA', ''];

$pageGroup = null;
foreach ($navItems as $item) {
    if (!isset($item['group'])) {
        continue;
    }
    $groupPages = $item['hidden'] ?? [];
    foreach ($item['sections'] as [, $links]) {
        $groupPages += $links;
    }
    if (isset($groupPages[$currentPage])) {
        $pageGroup = $item['group'];
        break;
    }
}
$sessionUser = trim((string) ($_SESSION['user'] ?? $_SESSION['username'] ?? ''));
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ASTERA · <?= e($pageTitle) ?></title>
    <link rel="preload" href="assets/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/css/tokens.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/css/tokens.css') ?>">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/css/app.css') ?>">
    <link rel="stylesheet" href="assets/css/design-system.css?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/css/design-system.css') ?>">
    <script>
    try { if (localStorage.getItem('astera-rail-collapsed') === '1') document.documentElement.classList.add('rail-collapsed'); } catch (e) {}
    </script>
</head>
<body class="app-shell">
<aside class="rail" id="appRail" aria-label="Ana menü">
    <div class="rail-brand">
        <span class="brand-mark sm">A</span>
        <div class="rail-brand-text">
            <strong>ASTERA</strong>
            <small>MultiTenant</small>
        </div>
        <button class="icon-btn rail-close" type="button" data-rail-close aria-label="Menüyü kapat"><?= ui_icon('x') ?></button>
    </div>
    <nav>
        <?php foreach ($navItems as $item): ?>
            <?php if (!isset($item['group'])): ?>
                <a class="nav-link<?= nav_active($item['page']) ?>" href="index.php?p=<?= e($item['page']) ?>"
                    title="<?= e($item['label']) ?>"<?= $currentPage === $item['page'] ? ' aria-current="page"' : '' ?>>
                    <?= ui_icon($item['icon'], 'nav-icon') ?>
                    <span class="nav-text"><?= e($item['label']) ?></span>
                </a>
            <?php else: ?>
                <details class="nav-group" name="rail-menu"<?= $pageGroup === $item['group'] ? ' open' : '' ?>>
                    <summary title="<?= e($item['group']) ?>"<?= $pageGroup === $item['group'] ? ' class="has-active"' : '' ?>>
                        <?= ui_icon($item['icon'], 'nav-icon') ?>
                        <span class="nav-text"><?= e($item['group']) ?></span>
                        <?= ui_icon('chevron-down', 'nav-chevron') ?>
                    </summary>
                    <div class="nav-group-items">
                        <?php foreach ($item['sections'] as [$sectionLabel, $links]): ?>
                            <?php if ($sectionLabel !== null): ?>
                                <span class="nav-section-label"><?= e($sectionLabel) ?></span>
                            <?php endif; ?>
                            <?php foreach ($links as $linkPage => $linkLabel): ?>
                                <a class="<?= trim(nav_active($linkPage)) ?>" href="index.php?p=<?= e($linkPage) ?>"<?= $currentPage === $linkPage ? ' aria-current="page"' : '' ?>><?= e($linkLabel) ?></a>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <div class="rail-foot">
        <div class="rail-system-title">
            <span class="dot" id="liveDot"></span>
            <span>Santral IP’leri</span>
        </div>
        <?php foreach (network_sidebar_addresses() as $interfaceAddress): ?>
            <div class="rail-interface-row">
                <?php $interfaceDisplayName = trim((string) ($interfaceAddress['display_name'] ?? '')); ?>
                <span title="<?= e((string) $interfaceAddress['interface']) ?>">
                    <?= e($interfaceDisplayName !== '' ? $interfaceDisplayName : (string) $interfaceAddress['interface']) ?>
                </span>
                <strong><?= e((string) $interfaceAddress['ip']) ?></strong>
            </div>
        <?php endforeach; ?>
        <div class="rail-system-row">
            <span>WAN IP</span>
            <strong><?= e(pbx_public_wan_ip() ?: 'Alınamadı') ?></strong>
        </div>
        <div class="rail-clock">
            <span>Sistem tarihi / saati</span>
            <time id="systemClock" data-server-epoch="<?= time() ?>">—</time>
        </div>
        <small class="rail-version">Astera 22.11.0</small>
    </div>
</aside>
<div class="rail-backdrop" data-rail-close hidden></div>
<main class="stage">
    <header class="topbar">
        <div class="topbar-main">
            <button class="icon-btn rail-toggle" type="button" data-rail-toggle aria-controls="appRail"
                aria-expanded="false" aria-label="Menüyü aç veya daralt">
                <span class="rail-toggle-desktop rail-toggle-collapse"><?= ui_icon('panel-left-close') ?></span>
                <span class="rail-toggle-desktop rail-toggle-expand"><?= ui_icon('panel-left-open') ?></span>
                <span class="rail-toggle-mobile"><?= ui_icon('menu') ?></span>
            </button>
            <div class="page-heading">
                <nav class="breadcrumb" aria-label="Konum">
                    <a href="index.php?p=dashboard"><?= ui_icon('house') ?><span>Ana sayfa</span></a>
                    <?php if ($pageGroup !== null): ?>
                        <?= ui_icon('chevron-right', 'breadcrumb-sep') ?>
                        <span><?= e($pageGroup) ?></span>
                    <?php endif; ?>
                    <?php if ($currentPage !== 'dashboard'): ?>
                        <?= ui_icon('chevron-right', 'breadcrumb-sep') ?>
                        <span aria-current="page"><?= e($pageTitle) ?></span>
                    <?php endif; ?>
                </nav>
                <h1 id="pageTitle"><?= e($pageTitle) ?></h1>
                <?php if ($pageDescription !== ''): ?>
                    <p class="page-desc" id="pageSub"><?= e($pageDescription) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="top-actions">
            <div class="top-actions-status">
                <?php if (is_super()): ?>
                    <label class="dept-switch-wrap" title="Aktif firma">
                        <?= ui_icon('building-2') ?>
                        <select id="deptSwitch" class="dept-switch" aria-label="Aktif firma">
                            <option value="*" <?= current_dept_id() === null ? 'selected' : '' ?>>Tüm firmalar</option>
                            <?php foreach (departments() as $d): ?>
                                <option value="<?= e($d['id']) ?>" <?= current_dept_id() === $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php else: ?>
                    <span class="pill ok"><?= e(dept_name((string) current_dept_id())) ?></span>
                <?php endif; ?>
                <?php $pendingChangeCount = store_pending_count(); ?>
                <span class="pill <?= $pendingChangeCount > 0 ? 'bad' : 'dim' ?>" id="pendingPill">Bekleyen: <?= $pendingChangeCount ?></span>
                <span class="pill" id="callPill">Çağrı: —</span>
            </div>
            <div class="top-actions-main">
                <button class="btn apply-btn <?= $pendingChangeCount > 0 ? 'primary' : '' ?>" id="applyButton" type="button" data-action="apply">Santrale uygula</button>
                <span class="pill dim" id="applyTimer" hidden></span>
                <?php if (is_super()): ?>
                    <a class="icon-btn" href="index.php?p=alarmlar" title="Alarmlar" aria-label="Alarmlar"><?= ui_icon('bell') ?></a>
                <?php endif; ?>
                <div class="user-chip" title="<?= e($sessionUser) ?>">
                    <span class="user-avatar"><?= ui_icon('user-round') ?></span>
                    <span class="user-meta">
                        <strong><?= e($sessionUser !== '' ? $sessionUser : 'Kullanıcı') ?></strong>
                        <small><?= is_super() ? 'Süper yönetici' : 'Firma yöneticisi' ?></small>
                    </span>
                </div>
                <a class="icon-btn" href="logout.php" title="Çıkış" aria-label="Çıkış"><?= ui_icon('log-out') ?></a>
            </div>
        </div>
    </header>
    <section class="content">
<?php unset($navItems, $item, $links, $linkPage, $linkLabel, $sectionLabel, $groupPages, $maintenance, $pageMeta, $d, $interfaceAddress, $interfaceDisplayName); ?>
