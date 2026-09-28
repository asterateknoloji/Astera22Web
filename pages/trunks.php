<?php
$rows = scoped('trunks');
$showDept = current_dept_id() === null;
?>
<div class="toolbar">
    <p class="muted">Dış hat yalnızca seçili firmaya aittir. Gelen çağrılar o firmanın gelen kurallarına düşer.</p>
    <button class="btn primary" type="button" id="addTrunk">Trunk ekle</button>
</div>
<article class="card flush">
    <table>
        <thead>
            <tr>
                <?php if ($showDept): ?><th>Firma</th><?php endif; ?>
                <th>Ad</th>
                <th>Tür</th>
                <th>Durum</th>
                <th>Sunucu</th>
                <th>Kullanıcı</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) ($row['dept'] ?? ''))) ?></td><?php endif; ?>
                <td><code><?= e($row['id']) ?></code><br><small><?= e($row['name'] ?? '') ?></small></td>
                <td><?= ($row['type'] ?? '') === 'peer' ? 'IP eş' : 'Kayıtlı' ?></td>
                <td><?= ($row['enabled'] ?? true) ? 'Etkin' : 'Devre dışı' ?></td>
                <td><?= e($row['host'] ?? '') ?>:<?= e($row['port'] ?? 5060) ?></td>
                <td><?= e($row['username'] ?? '') ?></td>
                <td class="right">
                    <button class="btn sm<?= ($row['enabled'] ?? true) ? ' danger' : ' primary' ?>" type="button" data-toggle-trunk="<?= e($row['id']) ?>">
                        <?= ($row['enabled'] ?? true) ? 'Devre dışı bırak' : 'Etkinleştir' ?>
                    </button>
                    <button class="btn sm" type="button" data-edit-trunk='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-trunk="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="7" class="muted">Trunk yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
<script>
document.addEventListener('DOMContentLoaded', () => Astera.pageTrunks());
</script>
