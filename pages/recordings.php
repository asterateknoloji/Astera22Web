<?php
$files = pbx_recordings(current_dept_id());
$showEmpty = !empty($_GET['show_empty']);
if (!$showEmpty) {
    $files = array_values(array_filter($files, static fn($file) => empty($file['empty'])));
}
$formatDuration = static function (int $seconds): string {
    return $seconds >= 3600 ? gmdate('H:i:s', $seconds) : gmdate('i:s', $seconds);
};
?>
<div class="toolbar">
    <p class="muted">Kayıtlar MixMonitor ile firma ve tarih bazında saklanır. Klasör: <code>/var/spool/asterisk/monitor/&lt;firma&gt;/&lt;yıl&gt;/&lt;ay&gt;/&lt;gün&gt;</code></p>
    <form method="get" class="checks">
        <input type="hidden" name="p" value="recordings">
        <label><input type="checkbox" name="show_empty" value="1" <?= $showEmpty ? 'checked' : '' ?> onchange="this.form.submit()"> Boş kayıtları göster</label>
    </form>
</div>
<article class="card flush">
    <table>
        <thead>
            <tr>
                <th>Firma</th>
                <th>Dosya</th>
                <th>Süre</th>
                <th>Dış numara</th>
                <th>Dahili</th>
                <th>Cevaplayan</th>
                <th>Dinle</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($files as $f): ?>
            <tr>
                <td><?= e($f['dept_name'] ?? '') ?></td>
                <td><code><?= e($f['relative_path'] ?? $f['file']) ?></code></td>
                <td><?= e($formatDuration((int) ($f['duration'] ?? 0))) ?></td>
                <td><code><?= e($f['external_number'] ?: '—') ?></code></td>
                <td><code><?= e($f['internal_number'] ?: '—') ?></code></td>
                <td>
                    <?= e($f['answered_by'] ?: '—') ?>
                    <?php if (!empty($f['answered_by_endpoint'])): ?><br><small><code><?= e($f['answered_by_endpoint']) ?></code></small><?php endif; ?>
                </td>
                <td>
                    <audio controls preload="none" src="play.php?dept=<?= e($f['dept']) ?>&f=<?= e($f['relative_path'] ?? $f['file']) ?>"></audio>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$files): ?>
            <tr><td colspan="7" class="muted">Kayıt yok. Firma kartında ses kaydını açıp bir görüşme yapın.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
