<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ASTERA · <?= e(ucfirst(str_replace('_', ' ', current_page()))) ?></title>
<link rel="stylesheet" href="assets/css/app.css?v=43">
</head>
<body>
<aside class="rail">
    <div class="rail-brand">
        <span class="brand-mark sm">A</span>
        <div>
            <strong>ASTERA</strong>
            <small>MultiTenant</small>
        </div>
    </div>
    <nav>
        <a class="<?= nav_active('dashboard') ?>" href="index.php?p=dashboard">Özet</a>
        <?php if (is_super()): ?>
            <a class="<?= nav_active('departments') ?>" href="index.php?p=departments">Firmalar</a>
        <?php endif; ?>
        <a class="<?= nav_active('crm') ?>" href="index.php?p=crm">Müşteri kartları</a>
        <details class="nav-group" name="rail-menu"
            <?= in_array(current_page(), ['extensions', 'webrtc', 'bulk', 'features'], true) ? 'open' : '' ?>>
            <summary>Aboneler</summary>
            <div class="nav-group-items">
                <a class="<?= nav_active('extensions') ?>" href="index.php?p=extensions">Dahili</a>
                <a class="<?= nav_active('webrtc') ?>" href="index.php?p=webrtc">WebPhone</a>
                <a class="<?= nav_active('bulk') ?>" href="index.php?p=bulk">Toplu ekle</a>
                <a class="<?= nav_active('features') ?>" href="index.php?p=features">Özellik kodları</a>
            </div>
        </details>
        <details class="nav-group" name="rail-menu"
            <?= in_array(current_page(), ['queues', 'ringgroups', 'ivr', 'time', 'announcements', 'conferences', 'parking', 'flow', 'disa', 'paging', 'speed', 'custom', 'urltriggers'], true) ? 'open' : '' ?>>
            <summary>Uygulamalar</summary>
            <div class="nav-group-items">
                <a class="<?= nav_active('queues') ?>" href="index.php?p=queues">Kuyruklar</a>
                <a class="<?= nav_active('ringgroups') ?>" href="index.php?p=ringgroups">Ring grup</a>
                <a class="<?= nav_active('ivr') ?>" href="index.php?p=ivr">IVR</a>
                <a class="<?= nav_active('time') ?>" href="index.php?p=time">Zaman koşulu</a>
                <a class="<?= nav_active('announcements') ?>" href="index.php?p=announcements">Duyurular</a>
                <a class="<?= nav_active('conferences') ?>" href="index.php?p=conferences">Konferans</a>
                <a class="<?= nav_active('parking') ?>" href="index.php?p=parking">Park</a>
                <a class="<?= nav_active('flow') ?>" href="index.php?p=flow">Gündüz / gece</a>
                <a class="<?= nav_active('disa') ?>" href="index.php?p=disa">DISA</a>
                <a class="<?= nav_active('paging') ?>" href="index.php?p=paging">Anons</a>
                <a class="<?= nav_active('speed') ?>" href="index.php?p=speed">Hızlı arama</a>
                <a class="<?= nav_active('custom') ?>" href="index.php?p=custom">Özel hedef</a>
                <a class="<?= nav_active('urltriggers') ?>" href="index.php?p=urltriggers">CRM / URL Tetikleme</a>
            </div>
        </details>
        <details class="nav-group" name="rail-menu"
            <?= in_array(current_page(), ['trunks', 'outbound', 'inbound', 'blacklist'], true) ? 'open' : '' ?>>
            <summary>Hatlar</summary>
            <div class="nav-group-items">
                <a class="<?= nav_active('trunks') ?>" href="index.php?p=trunks">Dış hat</a>
                <a class="<?= nav_active('outbound') ?>" href="index.php?p=outbound">Giden hat</a>
                <a class="<?= nav_active('inbound') ?>" href="index.php?p=inbound">Gelen hat</a>
                <a class="<?= nav_active('blacklist') ?>" href="index.php?p=blacklist">Kara liste</a>
            </div>
        </details>
        <details class="nav-group" name="rail-menu"
            <?= in_array(current_page(), ['cdr', 'queue_log', 'reports', 'recordings'], true) ? 'open' : '' ?>>
            <summary>Raporlar</summary>
            <div class="nav-group-items">
                <a class="<?= nav_active('cdr') ?>" href="index.php?p=cdr">Çağrı listesi</a>
                <a class="<?= nav_active('queue_log') ?>" href="index.php?p=queue_log">Kuyruk olayları</a>
            </div>
        </details>
        <details class="nav-group" name="rail-menu"
            <?= current_page() === 'sounds' ? 'open' : '' ?>>
            <summary>Medya</summary>
            <div class="nav-group-items">
                <a class="<?= nav_active('sounds') ?>" href="index.php?p=sounds">Sistem sesleri</a>
            </div>
        </details>
        <?php if (is_super()): ?>
            <details class="nav-group" name="rail-menu"
                <?= in_array(current_page(), ['logs', 'security', 'ssl', 'system'], true) ? 'open' : '' ?>>
                <summary>Sistem</summary>
                <div class="nav-group-items">
                    <a class="<?= nav_active('logs') ?>" href="index.php?p=logs">Log</a>
                    <a class="<?= nav_active('security') ?>" href="index.php?p=security">Güvenlik / IP İzinleri</a>
                    <a class="<?= nav_active('ssl') ?>" href="index.php?p=ssl">SSL Sertifikası</a>
                    <a class="<?= nav_active('system') ?>" href="index.php?p=system">Sistem</a>
                </div>
            </details>
        <?php endif; ?>
    </nav>
    <div class="rail-foot">
        <span class="dot" id="liveDot"></span>
        <span id="liveHost"><?= e(PBX_HOST) ?></span>
        <a href="logout.php">Çıkış</a>
    </div>
</aside>
<main class="stage">
    <header class="topbar">
        <div>
            <h1 id="pageTitle"><?= e([
                'dashboard' => 'Santral özeti',
                'departments' => 'Firmalar / departmanlar',
                'crm' => 'Müşteri kartları',
                'extensions' => 'Dahili aboneler',
                'webrtc' => 'WebPhone',
                'bulk' => 'Toplu abone ekle',
                'features' => 'Özellik kodları',
                'trunks' => 'Dış hat (trunk)',
                'outbound' => 'Giden arama kuralları',
                'inbound' => 'Gelen arama kuralları',
                'queues' => 'Çağrı kuyrukları',
                'ringgroups' => 'Ring grupları',
                'ivr' => 'IVR / sesli menü',
                'time' => 'Zaman koşulları',
                'announcements' => 'Duyurular',
                'conferences' => 'Konferans odaları',
                'parking' => 'Çağrı parkı',
                'flow' => 'Gündüz / gece',
                'disa' => 'DISA',
                'paging' => 'Anons / page',
                'speed' => 'Hızlı arama',
                'custom' => 'Özel hedefler',
                'urltriggers' => 'CRM / URL tetikleme',
                'sounds' => 'Sistem sesleri ve MOH',
                'blacklist' => 'Kara liste',
                'recordings' => 'Ses kayıtları',
                'reports' => 'Raporlama',
                'cdr' => 'Çağrı listesi',
                'queue_log' => 'Kuyruk olayları',
                'logs' => 'Asterisk log',
                'security' => 'Güvenlik / IP izinleri',
                'ssl' => 'SSL sertifikası',
                'system' => 'Sistem',
            ][current_page()] ?? 'ASTERA') ?></h1>
            <p class="muted" id="pageSub">Astera 22.11.0</p>
        </div>
        <div class="top-actions">
            <?php if (is_super()): ?>
                <select id="deptSwitch" class="dept-switch" title="Aktif firma">
                    <option value="*" <?= current_dept_id() === null ? 'selected' : '' ?>>Tüm firmalar</option>
                    <?php foreach (departments() as $d): ?>
                        <option value="<?= e($d['id']) ?>" <?= current_dept_id() === $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <span class="pill ok"><?= e(dept_name((string) current_dept_id())) ?></span>
            <?php endif; ?>
            <?php $pendingChangeCount = store_pending_count(); ?>
            <span class="pill <?= $pendingChangeCount > 0 ? 'bad' : 'dim' ?>" id="pendingPill">
                Bekleyen: <?= $pendingChangeCount ?>
            </span>
            <span class="pill" id="callPill">Çağrı: —</span>
            <button class="btn <?= $pendingChangeCount > 0 ? 'primary' : '' ?>" id="applyButton" type="button" data-action="apply">
                Santrale uygula
            </button>
            <a class="btn" href="logout.php">Çıkış</a>
        </div>
    </header>
    <section class="content">
