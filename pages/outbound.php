<?php
$rows = scoped('outbound');
$trunks = scoped('trunks');
$showDept = current_dept_id() === null;
?>
<div class="toolbar">
    <p class="muted">Astera aboneleri (1001, 121…) dış numarayı <strong>0</strong> ile tuşlar: <code>0532…</code> → hat <code>90532…</code> olarak gider. Üstten <strong>Astera</strong> firmasını seçin.</p>
    <button class="btn primary" type="button" id="addOut">Kural ekle</button>
</div>
<article class="card flush">
    <table>
        <thead>
            <tr>
                <?php if ($showDept): ?><th>Firma</th><?php endif; ?>
                <th>Ad</th>
                <th>Kalıp</th>
                <th>Sil / önek</th>
                <th>Trunk</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) ($row['dept'] ?? ''))) ?></td><?php endif; ?>
                <td><?= e($row['name'] ?? '') ?></td>
                <td><code><?= e($row['pattern'] ?? '') ?></code></td>
                <td><?= (int) ($row['strip'] ?? 0) ?> / <?= e($row['prefix'] ?? '-') ?></td>
                <td><code><?= e($row['trunk'] ?? '') ?></code></td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-out='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-out="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="muted">Giden kural yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
<script>
window.ASTERA.trunks = <?= json_encode($trunks, JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('DOMContentLoaded', () => Astera.pageOutbound());
</script>
