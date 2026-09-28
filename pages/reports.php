<?php
$rows = pbx_cdr(500, current_dept_id());
$sum = cdr_summary($rows);
?>
<div class="stats">
    <article class="stat"><small>Çağrı</small><strong><?= (int) $sum['total'] ?></strong><span>listelenen</span></article>
    <article class="stat"><small>Cevaplanan</small><strong><?= (int) $sum['answered'] ?></strong><span>ANSWERED</span></article>
    <article class="stat"><small>Dahili / dış</small><strong><?= (int) $sum['internal'] ?> / <?= (int) $sum['outbound'] ?></strong><span>tahmini</span></article>
    <article class="stat"><small>Konuşma</small><strong><?= gmdate('H:i:s', (int) $sum['billsec']) ?></strong><span>billsec</span></article>
</div>
<article class="card flush">
    <table>
        <thead>
            <tr>
                <th>Firma</th>
                <th>Başlangıç</th>
                <th>Kaynak</th>
                <th>Hedef</th>
                <th>Süre</th>
                <th>Durum</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($rows, 0, 120) as $row): ?>
            <tr>
                <td><?= e($row['accountcode'] ?: $row['userfield'] ?: '—') ?></td>
                <td><?= e($row['start']) ?></td>
                <td><?= e($row['src']) ?></td>
                <td><?= e($row['dst']) ?></td>
                <td><?= e($row['billsec']) ?> sn</td>
                <td><span class="pill <?= $row['disposition'] === 'ANSWERED' ? 'ok' : 'dim' ?>"><?= e($row['disposition']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="muted">Bu firma için CDR yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
