<?php
$rows = scoped('announcements');
$showDept = current_dept_id() === null;
?>
<div class="toolbar">
    <p class="muted">Ses çalıp bir hedefe gider. Gelen hatta veya IVR’da kullanılır.</p>
    <button class="btn primary" type="button" id="addAnn">Duyuru ekle</button>
</div>
<article class="card flush">
    <table>
        <thead><tr><?php if ($showDept): ?><th>Firma</th><?php endif; ?><th>Ad</th><th>Ses</th><th>Sonra</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) $row['dept'])) ?></td><?php endif; ?>
                <td><?= e($row['name'] ?? '') ?></td>
                <td><?= e($row['sound'] ?? '') ?></td>
                <td><?= e($row['dest_type'] ?? '') ?> <?= e($row['dest'] ?? '') ?></td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-ann='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-ann="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted">Duyuru yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageAnn());</script>
