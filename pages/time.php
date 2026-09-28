<?php
$rows = scoped('timeconditions');
$showDept = current_dept_id() === null;
?>
<div class="toolbar">
    <p class="muted">Çoklu mesai, vardiya, öğle arası, özel tarih ve Türkiye resmî tatillerine göre yönlendirme.</p>
    <button class="btn primary" type="button" id="addTime">Koşul ekle</button>
</div>
<article class="card flush">
    <table>
        <thead><tr><?php if ($showDept): ?><th>Firma</th><?php endif; ?><th>Kod</th><th>Ad</th><th>Zaman</th><th>Açık / kapalı</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) $row['dept'])) ?></td><?php endif; ?>
                <td><code><?= e($row['id'] ?? '') ?></code></td>
                <td><?= e($row['name'] ?? '') ?></td>
                <td>
                    <?php $ruleCount = count((array) ($row['rules'] ?? [])); ?>
                    <?php $exceptionCount = count((array) ($row['exceptions'] ?? [])); ?>
                    <?= $ruleCount ?: 1 ?> çalışma aralığı
                    <?php if ($exceptionCount): ?><small class="muted">· <?= $exceptionCount ?> istisna</small><?php endif; ?>
                    <?php if ($row['turkey_holidays'] ?? false): ?><br><small>🇹🇷 Resmî tatiller</small><?php endif; ?>
                </td>
                <td><?= e($row['true_type'] ?? '') ?> / <?= e($row['false_type'] ?? '') ?></td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-time='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-time="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= $showDept ? 6 : 5 ?>" class="muted">Zaman koşulu yok.</td></tr><?php endif; ?>
        </tbody>
    </table>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageTime());</script>
