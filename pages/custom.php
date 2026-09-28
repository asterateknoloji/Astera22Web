<?php
$rows = scoped('customs');
$showDept = current_dept_id() === null;
$types = dest_types();
?>
<div class="toolbar">
    <p class="muted">Özel hedef: isteğe bağlı ses çal, sonra bir hedefe git veya bilinen bir context’e <code>Goto</code>.</p>
    <button class="btn primary" type="button" id="addCust">Özel hedef</button>
</div>
<article class="card flush">
    <table>
        <thead><tr><?php if ($showDept): ?><th>Firma</th><?php endif; ?><th>Ad</th><th>Sonra</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) $row['dept'])) ?></td><?php endif; ?>
                <td><?= e($row['name'] ?? '') ?></td>
                <td><?= e($row['goto_context'] ?: (($types[$row['dest_type'] ?? ''] ?? '') . ' · ' . ($row['dest'] ?? ''))) ?></td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-cust='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-cust="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="4" class="muted">Özel hedef yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageCustom());</script>
