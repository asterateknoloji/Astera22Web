<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
$labels = [
    'agent_anket_ozet' => 'Agent Anket Değerlendirme', 'ayarlar' => 'Ayarlar',
    'agent_yonetim' => 'Agent Yönetimi', 'yetki_gruplari' => 'Yetki Grupları',
    'lograpor' => 'Log Rapor', 'kuyruksla' => 'Kuyruk SLA',
    'anket_yonetim' => 'Anket Yönetimi', 'mailtanimlari' => 'Mail Tanımları',
];
$route = basename($_SERVER['SCRIPT_FILENAME'] ?? '', '.php');
$title = $labels[$route] ?? 'Özellik';
supervisor_page_start($title);
?>
<div class="card"><div class="card-header"><?= e($title) ?></div><div class="card-body">
<div class="alert">Bu özellik henüz PostgreSQL şemasına güvenli biçimde taşınmadı.</div>
<p>Eski MySQL verisi veya sahte veri gösterilmiyor. Gerekli normalize tablolar, tenant kapsamı ve migration tamamlandığında bu rota etkinleştirilecektir.</p>
<a class="btn btn-primary" href="../../dashboard.php?tab=callcenter&subtab=<?= in_array($route, ['agent_anket_ozet'], true) ? 'raporlar' : 'sistem' ?>">Dashboard'a Dön</a>
</div></div>
<?php supervisor_page_end(); ?>
