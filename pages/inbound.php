<?php
$rows = scoped('inbound');
$showDept = current_dept_id() === null;
$types = dest_types();
?>
<div class="toolbar">
    <p class="muted">DID dış hattan gelen numaradır (ör. 908503338006). Hedef olarak MicroSIP’teki 1001’i (Astera) seçin. Kalıp <code>_X.</code> diğer numaraları da aynı hedefe yollar.</p>
    <button class="btn primary" type="button" id="addIn">Kural ekle</button>
</div>
<article class="card flush">
    <table>
        <thead>
            <tr>
                <?php if ($showDept): ?><th>Firma</th><?php endif; ?>
                <th>Ad</th>
                <th>DID</th>
                <th>Hedef</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) ($row['dept'] ?? ''))) ?></td><?php endif; ?>
                <td><?= e($row['name'] ?? '') ?></td>
                <td><code><?= e($row['did'] ?? '') ?></code></td>
                <td><?= e($types[$row['dest_type'] ?? ''] ?? ($row['dest_type'] ?? '')) ?> · <?= e($row['dest'] ?? '') ?></td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-in='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-in="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="5" class="muted">Gelen kural yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
<script>
document.addEventListener('DOMContentLoaded', () => Astera.pageInbound());
</script>
