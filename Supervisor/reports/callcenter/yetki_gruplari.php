<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$definitions = callcenter_permission_definitions();
$groups = [];
foreach ($definitions as $definition) {
    $groups[(string) $definition['category']][] = $definition;
}

supervisor_page_start('Yetki Grupları', '../../dashboard.php?tab=callcenter&subtab=sistem');
?>
<div class="agent-guide">
    <div>
        <span class="eyebrow">AYRINTILI ERİŞİM YÖNETİMİ</span>
        <h2>Menü ve rapor yetkileri</h2>
        <p>Bu yetkileri kullanıcı bazında Kullanıcılar ekranından atayabilirsiniz. Yöneticiler tüm yetkilere otomatik sahiptir.</p>
    </div>
    <a class="btn btn-primary" href="kullanicilar.php">Kullanıcı Yetkilerini Düzenle</a>
</div>
<div class="permission-catalog">
    <?php foreach ($groups as $category => $items): ?>
        <section class="card">
            <div class="card-header"><?= e($category) ?></div>
            <div class="card-body">
                <?php foreach ($items as $item): ?>
                    <article class="permission-catalog-item">
                        <div>
                            <strong><?= e((string) $item['label']) ?></strong>
                            <p><?= e((string) $item['description']) ?></p>
                        </div>
                        <code><?= e((string) $item['permission_code']) ?></code>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php supervisor_page_end(); ?>
