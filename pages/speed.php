<?php
$rows = scoped('speeddials');
$showDept = current_dept_id() === null;
?>
<div class="toolbar">
    <p class="muted">Hızlı arama. Kod <code>01</code> ise telefon <code>*01</code> çevirir. Numara firma dahiliyse içeri, değilse giden kurala gider.</p>
    <button class="btn primary" type="button" id="addSpeed">Hızlı tuş</button>
</div>
<article class="card flush">
    <table>
        <thead><tr><?php if ($showDept): ?><th>Firma</th><?php endif; ?><th>Ad</th><th>Kod</th><th>Numara</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) $row['dept'])) ?></td><?php endif; ?>
                <td><?= e($row['name'] ?? '') ?></td>
                <td><code>*<?= e($row['code'] ?? '') ?></code></td>
                <td><code><?= e($row['number'] ?? '') ?></code></td>
                <td class="right">
                    <button class="btn sm danger" type="button" data-del-speed="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="muted">Hızlı tuş yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageSpeed());</script>
