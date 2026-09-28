<?php
$filters = [
    'date_from' => array_key_exists('date_from', $_GET)
        ? trim((string) $_GET['date_from'])
        : date('Y-m-d'),
    'date_to' => array_key_exists('date_to', $_GET)
        ? trim((string) $_GET['date_to'])
        : date('Y-m-d'),
    'src' => trim((string) ($_GET['src'] ?? '')),
    'dst' => trim((string) ($_GET['dst'] ?? '')),
    'internal' => trim((string) ($_GET['internal'] ?? '')),
    'disposition' => trim((string) ($_GET['disposition'] ?? '')),
    'uniqueid' => trim((string) ($_GET['uniqueid'] ?? '')),
];
$rows = pbx_cdr(1000, current_dept_id(), $filters);
$recordings = array_values(array_filter(
    pbx_recordings(current_dept_id(), 200, $rows),
    static fn($recording) => empty($recording['empty'])
));
$recordingFor = static function (array $cdr) use ($recordings): ?array {
    $recordingFile = trim((string) ($cdr['recordingfile'] ?? ''), '/');
    if ($recordingFile !== '') {
        foreach ($recordings as $recording) {
            if ((string) ($recording['relative_path'] ?? '') === $recordingFile) {
                return $recording;
            }
        }
    }
    $uniqueId = trim((string) ($cdr['uniqueid'] ?? ''));
    $linkedId = trim((string) ($cdr['linkedid'] ?? ''));
    if ($uniqueId !== '') {
        foreach ($recordings as $recording) {
            $recordingUniqueId = (string) ($recording['uniqueid'] ?? '');
            if ($recordingUniqueId === $uniqueId || ($linkedId !== '' && $recordingUniqueId === $linkedId)) {
                return $recording;
            }
        }
    }
    $started = strtotime((string) ($cdr['start'] ?? ''));
    $src = preg_replace('/\D+/', '', (string) ($cdr['src'] ?? '')) ?? '';
    $dst = preg_replace('/\D+/', '', (string) ($cdr['dst'] ?? '')) ?? '';
    if ($started === false || $src === '') {
        return null;
    }
    $best = null;
    $bestScore = -1;
    foreach ($recordings as $recording) {
        if ((string) ($recording['uniqueid'] ?? '') !== '') {
            continue;
        }
        if ((string) ($recording['caller_number'] ?? '') !== $src) {
            continue;
        }
        $recordedAt = DateTime::createFromFormat('Ymd-His', (string) ($recording['started_key'] ?? ''));
        if (!$recordedAt) {
            continue;
        }
        $rawDifference = abs($recordedAt->getTimestamp() - $started);
        $difference = min($rawDifference, abs($rawDifference - 10800));
        if ($difference > 5) {
            continue;
        }
        $recordingDeptCode = dept_code_of((string) ($recording['dept'] ?? ''));
        $account = (string) (($cdr['accountcode'] ?? '') ?: ($cdr['userfield'] ?? ''));
        if ($account !== '' && $recordingDeptCode !== $account) {
            continue;
        }
        $recordedDestination = preg_replace('/\D+/', '', (string) ($recording['destination_number'] ?? '')) ?? '';
        $score = 10 - $difference;
        if ($dst !== '' && $recordedDestination === $dst) {
            $score += 20;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $recording;
        }
    }
    return $best;
};
$cdrDescription = static function (array $cdr): string {
    $parts = explode('|', (string) ($cdr['userfield'] ?? ''));
    if (($parts[1] ?? '') === 'BLACKLIST') {
        $number = preg_replace('/\D+/', '', (string) ($parts[2] ?? $cdr['src'] ?? '')) ?? '';
        return 'Kara liste nedeniyle reddedildi' . ($number !== '' ? ': ' . $number : '');
    }
    if (($parts[1] ?? '') === 'FALLBACK_EXTERNAL') {
        $extension = preg_replace('/\D+/', '', (string) ($parts[2] ?? '')) ?? '';
        $number = preg_replace('/\D+/', '', (string) ($parts[3] ?? '')) ?? '';
        $reason = strtoupper(trim((string) ($parts[4] ?? '')));
        $reasonText = match ($reason) {
            'BUSY' => 'meşguldü',
            'CHANUNAVAIL', 'CONGESTION' => 'ulaşılamadı',
            default => 'cevaplamadı',
        };
        return trim($extension . ' ' . $reasonText)
            . ($number !== '' ? '; dış numaraya yönlendirildi: ' . $number : '');
    }
    if (($parts[1] ?? '') === 'ATXFER') {
        $caller = preg_replace('/\D+/', '', (string) ($parts[3] ?? $cdr['cnum'] ?? $cdr['src'] ?? '')) ?? '';
        $target = preg_replace('/\D+/', '', (string) ($parts[4] ?? $cdr['dst'] ?? '')) ?? '';
        $transferor = trim((string) ($parts[5] ?? ''));
        $description = 'Kontrollü transfer';
        if ($caller !== '' || $target !== '') {
            $description .= ': ' . ($caller !== '' ? $caller : '—')
                . ' → ' . ($target !== '' ? $target : '—');
        }
        if ($transferor !== '') {
            $description .= ' (aktaran: ' . $transferor . ')';
        }
        return $description;
    }
    return '';
};
?>
<div class="toolbar">
    <p class="muted">CDR kayıtları santral PostgreSQL veritabanından okunur. <?= count($rows) ?> sonuç.</p>
    <div class="toolbar">
        <a class="btn sm" href="cdr_export.php?<?= e(http_build_query(array_merge($filters, ['format' => 'excel']))) ?>">Excel'e aktar</a>
        <a class="btn sm" href="cdr_export.php?<?= e(http_build_query(array_merge($filters, ['format' => 'pdf']))) ?>">PDF'e aktar</a>
        <button class="btn sm" type="button" onclick="location.reload()">Yenile</button>
    </div>
</div>
<article class="card">
    <form method="get" class="cdr-filters">
        <input type="hidden" name="p" value="cdr">
        <label>Başlangıç tarihi<input type="date" name="date_from" value="<?= e($filters['date_from']) ?>"></label>
        <label>Bitiş tarihi<input type="date" name="date_to" value="<?= e($filters['date_to']) ?>"></label>
        <label>Kaynak / dış numara<input name="src" value="<?= e($filters['src']) ?>" placeholder="905..."></label>
        <label>Hedef numara<input name="dst" value="<?= e($filters['dst']) ?>" placeholder="8001"></label>
        <label>Arayan dahili<input name="internal" value="<?= e($filters['internal']) ?>" placeholder="1001"></label>
        <label>Durum
            <select name="disposition">
                <option value="">Tümü</option>
                <?php foreach (['ANSWERED', 'NO ANSWER', 'BUSY', 'FAILED', 'CONGESTION'] as $status): ?>
                    <option value="<?= e($status) ?>" <?= $filters['disposition'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>UNIQUEID<input name="uniqueid" value="<?= e($filters['uniqueid']) ?>" placeholder="178997..."></label>
        <div class="modal-actions">
            <a class="btn" href="index.php?p=cdr">Temizle</a>
            <button class="btn primary" type="submit">Filtrele</button>
        </div>
    </form>
</article>
<article class="card flush">
    <table>
        <thead>
            <tr>
                <th>Firma</th>
                <th>Başlangıç</th>
                <th>Kaynak</th>
                <th>Arayan dahili</th>
                <th>Hedef</th>
                <th>Süre</th>
                <th>Durum</th>
                <th>Açıklama</th>
                <th>Cevaplayan</th>
                <th>Ses kaydı</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php $recording = $recordingFor($row); ?>
            <?php $description = $cdrDescription($row); ?>
            <?php
            $callerEndpoint = '';
            $callerExtension = null;
            if (preg_match('#^PJSIP/(.+)-[A-Fa-f0-9]+$#', (string) ($row['channel'] ?? ''), $callerMatch)) {
                $callerEndpoint = $callerMatch[1];
                $callerExtension = find_sip_user($callerEndpoint);
            }
            ?>
            <tr>
                <td><?= e($row['accountcode'] ?: $row['userfield'] ?: '—') ?></td>
                <td><?= e($row['start']) ?></td>
                <td><?= e($row['src']) ?></td>
                <td>
                    <?php if ($callerExtension): ?>
                        <?= e(trim((string) ($callerExtension['exten'] ?? '') . ' ' . (string) ($callerExtension['name'] ?? ''))) ?>
                        <br><small><code><?= e($callerEndpoint) ?></code></small>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= e($row['dst']) ?></td>
                <td><?= e($row['billsec']) ?> sn</td>
                <td><span class="pill <?= $row['disposition'] === 'ANSWERED' ? 'ok' : 'dim' ?>"><?= e($row['disposition']) ?></span></td>
                <td>
                    <?php if ($description !== ''): ?>
                        <span class="pill bad"><?= e($description) ?></span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($recording && !empty($recording['answered_by'])): ?>
                        <?= e($recording['answered_by']) ?>
                        <br><small><code><?= e($recording['answered_by_endpoint']) ?></code></small>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($recording): ?>
                        <audio controls preload="none" src="play.php?dept=<?= e($recording['dept']) ?>&f=<?= e($recording['relative_path'] ?? $recording['file']) ?>"></audio>
                        <small><code><?= e($recording['relative_path'] ?? $recording['file']) ?></code></small>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="10" class="muted">Kayıt yok veya CDR okunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
