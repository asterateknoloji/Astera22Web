<?php
$rows = scoped('disas');
$showDept = current_dept_id() === null;
?>
<div class="toolbar">
    <p class="muted">DISA: dışarıdan arayan PIN girince firmanın dahili/dış arama tonunu alır. Gelen hatta hedef olarak seçin veya dahili verin.</p>
    <button class="btn primary" type="button" id="addDisa">DISA ekle</button>
</div>
<article class="card flush">
    <table>
        <thead><tr><?php if ($showDept): ?><th>Firma</th><?php endif; ?><th>Ad</th><th>Dahili</th><th>PIN</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) $row['dept'])) ?></td><?php endif; ?>
                <td><?= e($row['name'] ?? '') ?></td>
                <td><code><?= e($row['exten'] ?: '—') ?></code></td>
                <td><?= e($row['pin'] ? '***' : 'yok') ?></td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-disa='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-disa="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted">DISA yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageDisa());</script>
