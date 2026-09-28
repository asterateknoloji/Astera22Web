<?php
$rows = scoped('pagings');
$exts = scoped('extensions');
$showDept = current_dept_id() === null;
?>
<div class="toolbar">
    <p class="muted">Anons grubu tüm üyelerin hoparlörünü açar. Çift yönlü (duplex) konuşmaya izin verir. Tekli anons: <code>*80</code> + dahili (ör. <code>*801001</code>).</p>
    <button class="btn primary" type="button" id="addPage">Anons grubu</button>
</div>
<article class="card flush">
    <table>
        <thead><tr><?php if ($showDept): ?><th>Firma</th><?php endif; ?><th>Ad</th><th>Dahili</th><th>Üyeler</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) $row['dept'])) ?></td><?php endif; ?>
                <td><?= e($row['name'] ?? '') ?></td>
                <td><code><?= e($row['exten'] ?? '') ?></code></td>
                <td><?= e(implode(', ', $row['members'] ?? [])) ?></td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-page='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-page="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted">Anons grubu yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</article>
<script>
window.ASTERA.exts = <?= json_encode($exts, JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('DOMContentLoaded', () => Astera.pagePaging());
</script>
