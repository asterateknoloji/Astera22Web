<?php
$rows = scoped('conferences');
$showDept = current_dept_id() === null;
?>
<div class="toolbar">
    <p class="muted">Dahili numarayı çevirince konferans. PIN isteğe bağlı.</p>
    <button class="btn primary" type="button" id="addConf">Oda ekle</button>
</div>
<article class="card flush">
    <table>
        <thead><tr><?php if ($showDept): ?><th>Firma</th><?php endif; ?><th>Ad</th><th>Dahili</th><th>PIN</th><th>Kayıt</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) $row['dept'])) ?></td><?php endif; ?>
                <td><?= e($row['name'] ?? '') ?></td>
                <td><code><?= e($row['exten'] ?? '') ?></code></td>
                <td><?= e($row['pin'] ?? '—') ?></td>
                <td>
                    <?php if (!empty($row['record'])): ?>
                        <span class="pill ok"><?= strtoupper(e((string) ($row['record_format'] ?? 'wav'))) ?></span>
                    <?php else: ?>
                        <span class="pill dim">Kapalı</span>
                    <?php endif; ?>
                </td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-conf='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-conf="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= $showDept ? 6 : 5 ?>" class="muted">Konferans yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageConf());</script>
