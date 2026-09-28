<?php
$rows = scoped('ivrs');
$showDept = current_dept_id() === null;
$extensions = array_map(static fn(array $row): array => [
    'exten' => (string) ($row['exten'] ?? ''),
    'name' => (string) ($row['name'] ?? ''),
    'dept' => (string) ($row['dept'] ?? ''),
], scoped('extensions'));
?>
<div class="toolbar">
    <p class="muted">Sesli menü. Tuş 0–9, *, # bir hedefe gider. Firma dahilileri varsayılan olarak doğrudan tuşlanabilir.</p>
    <button class="btn primary" type="button" id="addIvr">IVR ekle</button>
</div>
<article class="card flush">
    <table>
        <thead><tr><?php if ($showDept): ?><th>Firma</th><?php endif; ?><th>Kod</th><th>Ad</th><th>Ses</th><th>Dahili tuşlama</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) $row['dept'])) ?></td><?php endif; ?>
                <td><code><?= e($row['id']) ?></code></td>
                <td><?= e($row['name'] ?? '') ?></td>
                <td><?= e($row['sound'] ?? '') ?></td>
                <td>
                    <?php if ($row['direct_dial'] ?? true): ?>
                        Açık
                        <?php $blockedCount = count((array) ($row['blocked_extensions'] ?? [])); ?>
                        <?php if ($blockedCount): ?><small class="muted">(<?= $blockedCount ?> yasaklı)</small><?php endif; ?>
                    <?php else: ?>
                        Kapalı
                    <?php endif; ?>
                </td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-ivr='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-ivr="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= $showDept ? 6 : 5 ?>" class="muted">IVR yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</article>
<script>
window.ASTERA = window.ASTERA || {};
window.ASTERA.exts = <?= json_encode($extensions, JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('DOMContentLoaded', () => Astera.pageIvr());
</script>
