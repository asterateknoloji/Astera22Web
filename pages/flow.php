<?php
$rows = scoped('flows');
$showDept = current_dept_id() === null;
$types = dest_types();
?>
<div class="toolbar">
    <p class="muted">FreePBX Call Flow. Özellik kodu (ör. <code>*28</code>) gündüz/gece anahtarıdır. Gelen hat hedefini buraya bağlayın.</p>
    <button class="btn primary" type="button" id="addFlow">Akış ekle</button>
</div>
<article class="card flush">
    <table>
        <thead><tr><?php if ($showDept): ?><th>Firma</th><?php endif; ?><th>Ad</th><th>Kod</th><th>Gündüz</th><th>Gece</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) $row['dept'])) ?></td><?php endif; ?>
                <td><?= e($row['name'] ?? '') ?></td>
                <td><code><?= e($row['feature'] ?? '*28') ?></code></td>
                <td><?= e($types[$row['true_type'] ?? ''] ?? '') ?> · <?= e($row['true_dest'] ?? '') ?></td>
                <td><?= e($types[$row['false_type'] ?? ''] ?? '') ?> · <?= e($row['false_dest'] ?? '') ?></td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-flow='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-flow="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="muted">Gündüz/gece yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageFlow());</script>
