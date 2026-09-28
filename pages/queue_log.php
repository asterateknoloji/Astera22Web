<?php
$filters = [
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
    'callid' => trim((string) ($_GET['callid'] ?? '')),
    'queuename' => trim((string) ($_GET['queuename'] ?? '')),
    'agent' => trim((string) ($_GET['agent'] ?? '')),
    'event' => trim((string) ($_GET['event'] ?? '')),
    'data' => trim((string) ($_GET['data'] ?? '')),
];
$rows = pbx_queue_log_rows(1000, current_dept_id(), $filters);
$dataLabels = [
    'ENTERQUEUE' => ['URL / ek bilgi', 'Arayan numara', 'Kuyruğa giriş sırası', '', ''],
    'CONNECT' => ['Bekleme süresi (sn)', 'Bağlanan kanal UNIQUEID', 'Temsilci çalma süresi (sn)', '', ''],
    'COMPLETEAGENT' => ['Bekleme süresi (sn)', 'Görüşme süresi (sn)', 'İlk sıra', '', ''],
    'COMPLETECALLER' => ['Bekleme süresi (sn)', 'Görüşme süresi (sn)', 'İlk sıra', '', ''],
    'ABANDON' => ['Ayrıldığı sıra', 'İlk sıra', 'Bekleme süresi (sn)', '', ''],
    'RINGNOANSWER' => ['Çalma süresi (ms)', '', '', '', ''],
    'RINGCANCELED' => ['Çalma süresi (ms)', '', '', '', ''],
    'EXITWITHTIMEOUT' => ['Ayrıldığı sıra', '', '', '', ''],
    'EXITWITHKEY' => ['Basılan tuş', 'Ayrıldığı sıra', '', '', ''],
    'TRANSFER' => ['Aktarılan dahili', 'Context', 'Bekleme süresi (sn)', 'Görüşme süresi (sn)', 'İlk sıra'],
    'ADDMEMBER' => ['Üye adı', 'Ceza puanı', 'Duraklatıldı', '', ''],
    'REMOVEMEMBER' => ['Üye adı', '', '', '', ''],
    'PAUSE' => ['Duraklatma nedeni', '', '', '', ''],
    'UNPAUSE' => ['Duraklatma nedeni', '', '', '', ''],
];
?>
<div class="toolbar">
    <p class="muted">Asterisk kuyruk olayları PostgreSQL <code>queue_log</code> tablosundan okunur. Son <?= count($rows) ?> kayıt gösteriliyor.</p>
    <button class="btn sm" type="button" onclick="location.reload()">Yenile</button>
</div>
<article class="card">
    <form method="get" class="cdr-filters">
        <input type="hidden" name="p" value="queue_log">
        <label>Başlangıç tarihi<input type="date" name="date_from" value="<?= e($filters['date_from']) ?>"></label>
        <label>Bitiş tarihi<input type="date" name="date_to" value="<?= e($filters['date_to']) ?>"></label>
        <label>Unique ID<input name="callid" value="<?= e($filters['callid']) ?>" placeholder="178997..."></label>
        <label>Kuyruk<input name="queuename" value="<?= e($filters['queuename']) ?>" placeholder="120-SATIS"></label>
        <label>Temsilci<input name="agent" value="<?= e($filters['agent']) ?>" placeholder="120_1001"></label>
        <label>Olay<input name="event" value="<?= e($filters['event']) ?>" placeholder="CONNECT"></label>
        <label>Olay detaylarında ara<input name="data" value="<?= e($filters['data']) ?>" placeholder="Numara, süre, pozisyon..."></label>
        <div class="modal-actions">
            <a class="btn" href="index.php?p=queue_log">Temizle</a>
            <button class="btn primary" type="submit">Filtrele</button>
        </div>
    </form>
</article>
<p class="muted tiny">Detay alanlarının anlamı olay türüne göre değişir. Her değerin açıklaması kendi hücresinde gösterilir.</p>
<article class="card flush">
    <table>
        <thead>
            <tr>
                <th>Zaman</th>
                <th>Unique ID</th>
                <th>Kuyruk</th>
                <th>Temsilci</th>
                <th>Olay</th>
                <th>Detay 1</th>
                <th>Detay 2</th>
                <th>Detay 3</th>
                <th>Detay 4</th>
                <th>Detay 5</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php $labels = $dataLabels[strtoupper((string) ($row['event'] ?? ''))] ?? []; ?>
            <tr>
                <td><?= e($row['time'] ?? '') ?></td>
                <td><code><?= e($row['callid'] ?? '') ?></code></td>
                <td><code><?= e($row['queuename'] ?? '') ?></code></td>
                <td><code><?= e($row['agent'] ?? '') ?></code></td>
                <td><span class="pill"><?= e($row['event'] ?? '') ?></span></td>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <td>
                        <?php if (!empty($labels[$i - 1])): ?><small class="muted"><?= e($labels[$i - 1]) ?></small><br><?php endif; ?>
                        <?= e($row['data' . $i] ?? '') ?>
                    </td>
                <?php endfor; ?>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="10" class="muted">Kuyruk olayı bulunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
