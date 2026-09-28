<?php
$rows = scoped('blacklist');
$showDept = current_dept_id() === null;
?>
<div class="toolbar">
    <p class="muted">Bu numaralardan gelen çağrı firmanın santralinde reddedilir.</p>
    <button class="btn primary" type="button" id="addBl">Numara ekle</button>
</div>
<article class="card flush">
    <table>
        <thead><tr><?php if ($showDept): ?><th>Firma</th><?php endif; ?><th>Numara</th><th>Not</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) $row['dept'])) ?></td><?php endif; ?>
                <td><code><?= e($row['number'] ?? '') ?></code></td>
                <td><?= e($row['name'] ?? '') ?></td>
                <td class="right">
                    <button class="btn sm danger" type="button" data-del-bl="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="4" class="muted">Kara liste boş.</td></tr><?php endif; ?>
        </tbody>
    </table>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageBlacklist());</script>
