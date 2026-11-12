<?php
$rows = scoped('queues');
$exts = scoped('extensions');
$strategies = ring_strategies();
$showDept = current_dept_id() === null;
$departmentIndex = [];
$notificationEmails = [];
$notificationTemplates = [];
$defaultNotificationFromName = 'Astera Santral ve Callcenter';
$defaultNotificationSubject = '[Astera Santral ve Callcenter] Kaçan çağrı - {kuyruk}';
$defaultNotificationBody = "Astera Santral ve Callcenter\n\nKaçan çağrı bildirimi\n\n"
    . "Kuyruk Adı: {kuyruk}\nNumara: {numara}\nTarih / Saat: {tarih} {saat}\n"
    . "Kaçtığı Sıra: {sira}\nBekleme Süresi: {bekleme} sn\nSantral: {santral}";
$queueLicense = license_capacity_view('callcenter.queues', 'Kuyruk', current_dept_id());
$mohFiles = [];
try {
    $mohFiles = pbx_moh_files(current_dept_id());
} catch (Throwable) {
    $mohFiles = [];
}
try {
    foreach (callcenter_rows(
        'SELECT dept_id,queue_id,email FROM callcenter_queue_notification_recipients '
        . 'WHERE active ORDER BY dept_id,queue_id,email'
    ) as $recipient) {
        $notificationEmails[(string) $recipient['dept_id']][(string) $recipient['queue_id']][] =
            (string) $recipient['email'];
    }
    foreach (callcenter_rows(
        'SELECT dept_id,queue_id,missed_call_from_name,missed_call_subject_template,'
        . 'missed_call_body_template FROM callcenter_queue_settings'
    ) as $template) {
        $notificationTemplates[(string) $template['dept_id']][(string) $template['queue_id']] = $template;
    }
} catch (Throwable) {
    $notificationEmails = [];
    $notificationTemplates = [];
}
foreach ($rows as &$queueRow) {
    $rowDept = (string) ($queueRow['dept'] ?? '');
    $rowId = (string) ($queueRow['id'] ?? '');
    $queueRow['missed_call_emails'] = $notificationEmails[$rowDept][$rowId] ?? [];
    $template = $notificationTemplates[$rowDept][$rowId] ?? [];
    $queueRow['missed_call_from_name'] =
        (string) ($template['missed_call_from_name'] ?? $defaultNotificationFromName);
    $queueRow['missed_call_subject_template'] =
        (string) ($template['missed_call_subject_template'] ?? $defaultNotificationSubject);
    $queueRow['missed_call_body_template'] =
        (string) ($template['missed_call_body_template'] ?? $defaultNotificationBody);
}
unset($queueRow);
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
                <th>Kaçan çağrı bildirimi</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php
            $memberPenaltyIndex = [];
            foreach ((array) ($row['member_penalties'] ?? []) as $key => $penaltyRow) {
                if (is_array($penaltyRow)) {
                    $memberNumber = (string) ($penaltyRow['member'] ?? '');
                    if ($memberNumber !== '') {
                        $memberPenaltyIndex[$memberNumber] = max(1, (int) ($penaltyRow['penalty'] ?? 1));
                    }
                } else {
                    $memberPenaltyIndex[(string) $key] = max(1, (int) $penaltyRow);
                }
            }
            $queueMembers = array_values((array) ($row['members'] ?? []));
            $memberLabels = array_map(
                static fn($member, int $index): string => ($index + 1) . '. ' . (string) $member
                    . ' (Öncelik ' . (int) ($memberPenaltyIndex[(string) $member] ?? 1) . ')',
                $queueMembers,
                array_keys($queueMembers)
            );
            ?>
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
                <td><?= e(implode(' → ', $memberLabels)) ?></td>
                <td>
                    <?= (int) ($row['queue_timeout'] ?? 300) === 0 ? 'Sınırsız' : (int) ($row['queue_timeout'] ?? 300) . ' sn' ?>
                    <br><small>Maks. <?= (int) ($row['maxlen'] ?? 0) === 0 ? 'sınırsız' : (int) $row['maxlen'] ?></small>
                </td>
                <td>
                    <span class="pill <?= !empty($row['announce_position']) ? 'ok' : 'dim' ?>">Sıra <?= !empty($row['announce_position']) ? 'açık' : 'kapalı' ?></span>
                    <?php if (!empty($row['periodic_announce'])): ?><br><small><?= e($row['periodic_announce']) ?></small><?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($row['missed_call_emails'])): ?>
                        <span class="pill ok"><?= count($row['missed_call_emails']) ?> alıcı</span>
                        <br><small><?= e(implode(', ', $row['missed_call_emails'])) ?></small>
                    <?php else: ?>
                        <span class="pill dim">Kapalı</span>
                    <?php endif; ?>
                </td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-queue='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm" type="button" data-copy-queue='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Çoğalt</button>
                    <button class="btn sm danger" type="button" data-del-queue="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $showDept ? 10 : 9 ?>" class="muted">Kuyruk yok. “Kuyruk ekle” ile ilk kuyruğu oluşturabilirsiniz.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
<script>
window.ASTERA = window.ASTERA || {};
window.ASTERA.exts = <?= json_encode($exts, JSON_UNESCAPED_UNICODE) ?>;
window.ASTERA.strategies = <?= json_encode($strategies, JSON_UNESCAPED_UNICODE) ?>;
window.ASTERA.mohFiles = <?= json_encode($mohFiles, JSON_UNESCAPED_UNICODE) ?>;
window.ASTERA.queueLicense = <?= json_encode($queueLicense, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
document.addEventListener('DOMContentLoaded', () => Astera.pageQueues());
</script>
