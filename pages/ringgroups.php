<?php
$rows = scoped('ringgroups');
$exts = scoped('extensions');
$showDept = current_dept_id() === null;
?>
<div class="toolbar">
    <p class="muted">
        <strong>Ring grupları</strong> birden fazla dahiliye eş zamanlı veya sırayla arama yapar.<br>
        <strong>Ringall:</strong> Tüm üyeler aynı anda çalar. <strong>Hunt:</strong> Sırayla biri cevaplana kadar dener.<br>
        <strong>Arama onayı</strong>: Üye cevapladığında 1'e basarak onaylamalı (meşgulse atlar).<br>
        <strong>CID Öneki</strong>: Üye telefonunda arayan bilgisinin başına eklenir (ör: "Satış: Müşteri").
    </p>
    <button class="btn primary" type="button" id="addRg">Grup ekle</button>
</div>
<article class="card flush">
    <table>
        <thead>
            <tr>
                <?php if ($showDept): ?><th>Firma</th><?php endif; ?>
                <th>Grup</th>
                <th>Dahili</th>
                <th>Strateji</th>
                <th>Kayıt</th>
                <th>Üyeler</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) ($row['dept'] ?? ''))) ?></td><?php endif; ?>
                <td><code><?= e($row['id']) ?></code><br><small><?= e($row['name'] ?? '') ?></small></td>
                <td><?= e($row['exten'] ?? '') ?></td>
                <td>
                    <?= ($row['strategy'] ?? '') === 'hunt' ? 'Sırayla' : 'Tümü çalsın' ?>
                    <?php if (!empty($row['confirm_calls'])): ?><br><small class="muted">Onay: ✓</small><?php endif; ?>
                </td>
                <td>
                    <?= ($row['record'] ?? true) ? strtoupper(e((string) ($row['record_format'] ?? 'wav'))) : 'Kapalı' ?>
                </td>
                <td>
                    <?= e(implode(', ', $row['members'] ?? [])) ?>
                    <?php if (!empty($row['cid_prefix'])): ?><br><small class="muted">CID: <?= e($row['cid_prefix']) ?></small><?php endif; ?>
                </td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-rg='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-rg="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $showDept ? 7 : 6 ?>" class="muted">Ring grup yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
<script>
window.ASTERA = window.ASTERA || {};
window.ASTERA.exts = <?= json_encode($exts, JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('DOMContentLoaded', () => Astera.pageRinggroups());
</script>
