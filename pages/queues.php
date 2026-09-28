<?php
$rows = scoped('queues');
$exts = scoped('extensions');
$strategies = ring_strategies();
$showDept = current_dept_id() === null;
$departmentIndex = [];
foreach (departments() as $department) {
    $departmentIndex[(string) ($department['id'] ?? '')] = $department;
}
?>
<div class="toolbar">
    <p class="muted">Üyeler aynı firmadan seçilir. Sıra numarası, tahmini bekleme süresi, periyodik anons, müzik ve taşma hedefi ayarlanabilir.</p>
    <button class="btn primary" type="button" id="addQueue">Kuyruk ekle</button>
</div>
<article class="card flush">
    <table>
        <thead>
            <tr>
                <?php if ($showDept): ?><th>Firma</th><?php endif; ?>
                <th>Kuyruk</th>
                <th>Dahili</th>
                <th>Strateji</th>
                <th>Kayıt</th>
                <th>Üyeler</th>
                <th>Bekleme</th>
                <th>Anons</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) ($row['dept'] ?? ''))) ?></td><?php endif; ?>
                <td><code><?= e($row['id']) ?></code><br><small><?= e($row['name'] ?? '') ?></small></td>
                <td><?= e($row['exten'] ?? '') ?></td>
                <td><?= e($strategies[$row['strategy'] ?? 'ringall'] ?? ($row['strategy'] ?? '')) ?></td>
                <?php
                $queueDept = $departmentIndex[(string) ($row['dept'] ?? '')] ?? [];
                $recordEnabled = array_key_exists('record', $row)
                    ? !empty($row['record'])
                    : !empty($queueDept['record']);
                ?>
                <td><?= $recordEnabled ? strtoupper(e((string) ($row['record_format'] ?? 'wav'))) : 'Kapalı' ?></td>
                <td><?= e(implode(', ', $row['members'] ?? [])) ?></td>
                <td>
                    <?= (int) ($row['queue_timeout'] ?? 300) === 0 ? 'Sınırsız' : (int) ($row['queue_timeout'] ?? 300) . ' sn' ?>
                    <br><small>Maks. <?= (int) ($row['maxlen'] ?? 0) === 0 ? 'sınırsız' : (int) $row['maxlen'] ?></small>
                </td>
                <td>
                    <span class="pill <?= !empty($row['announce_position']) ? 'ok' : 'dim' ?>">Sıra <?= !empty($row['announce_position']) ? 'açık' : 'kapalı' ?></span>
                    <?php if (!empty($row['periodic_announce'])): ?><br><small><?= e($row['periodic_announce']) ?></small><?php endif; ?>
                </td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-queue='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-queue="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $showDept ? 9 : 8 ?>" class="muted">Kuyruk yok. “Kuyruk ekle” ile ilk kuyruğu oluşturabilirsiniz.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
<script>
window.ASTERA = window.ASTERA || {};
window.ASTERA.exts = <?= json_encode($exts, JSON_UNESCAPED_UNICODE) ?>;
window.ASTERA.strategies = <?= json_encode($strategies, JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('DOMContentLoaded', () => Astera.pageQueues());
</script>
