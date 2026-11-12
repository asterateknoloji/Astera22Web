<?php
require_once __DIR__ . '/../includes/report_period.php';
$filterKeys = ['date_from', 'date_to', 'src', 'dst', 'internal', 'disposition', 'uniqueid'];
$hasSubmittedFilters = false;
foreach ($filterKeys as $filterKey) {
    if (array_key_exists($filterKey, $_GET)) {
        $hasSubmittedFilters = true;
        break;
    }
}
$filters = array_merge(report_period_filters($_GET), [
    'src' => trim((string) ($_GET['src'] ?? '')),
    'dst' => trim((string) ($_GET['dst'] ?? '')),
    'internal' => trim((string) ($_GET['internal'] ?? '')),
    'disposition' => trim((string) ($_GET['disposition'] ?? '')),
    'uniqueid' => trim((string) ($_GET['uniqueid'] ?? '')),
    'cursor_calldate' => trim((string) ($_GET['cursor_calldate'] ?? '')),
    'cursor_sequence' => trim((string) ($_GET['cursor_sequence'] ?? '')),
]);
$deptId = current_dept_id();
$rows = pbx_cdr($hasSubmittedFilters ? 0 : 50, $deptId, $hasSubmittedFilters ? $filters : []);
$totalRows = $hasSubmittedFilters ? count($rows) : null;
$rowsWithoutRecordingFile = array_values(array_filter(
    $rows,
    static fn(array $row): bool => trim((string) ($row['recordingfile'] ?? '')) === ''
));
$recordings = array_values(array_filter(
    $rowsWithoutRecordingFile ? pbx_recordings($deptId, 200, $rowsWithoutRecordingFile) : [],
    static fn($recording) => empty($recording['empty'])
));
$recordingFor = static function (array $cdr) use ($recordings, $deptId): ?array {
    $recordingFile = trim((string) ($cdr['recordingfile'] ?? ''), '/');
    if ($recordingFile !== '') {
        $normalizedRecordingFile = str_replace('\\', '/', $recordingFile);
        if (!str_contains($normalizedRecordingFile, '..')
            && preg_match('~^[A-Za-z0-9_./-]+\.(?:wav|gsm|ulaw)$~iD', $normalizedRecordingFile) === 1
        ) {
            $recordingDept = $deptId;
            if ($recordingDept === null) {
                $account = explode('|', (string) (($cdr['accountcode'] ?? '') ?: ($cdr['userfield'] ?? '')))[0];
                foreach (departments() as $department) {
                    if (dept_code_of((string) $department['id']) === $account) {
                        $recordingDept = (string) $department['id'];
                        break;
                    }
                }
            }
            $answeredByEndpoint = '';
            $answeredBy = '';
            if (preg_match('#^PJSIP/(.+)-[A-Fa-f0-9]+$#', (string) ($cdr['dstchannel'] ?? ''), $match)) {
                $candidate = find_sip_user((string) $match[1]);
                if ($candidate !== null
                    && ($recordingDept === null || (string) ($candidate['dept'] ?? '') === $recordingDept)
                ) {
                    $answeredByEndpoint = (string) $match[1];
                    $answeredBy = trim(
                        (string) ($candidate['exten'] ?? '') . ' ' . (string) ($candidate['name'] ?? '')
                    );
                }
            }
            return $recordingDept === null ? null : [
                'dept' => $recordingDept,
                'file' => basename($normalizedRecordingFile),
                'relative_path' => $normalizedRecordingFile,
                'answered_by_endpoint' => $answeredByEndpoint,
                'answered_by' => $answeredBy,
            ];
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
$ivrNamesByContext = [];
foreach (scoped('ivrs') as $ivr) {
    $ivrId = ast_sanitize_id((string) ($ivr['id'] ?? ''));
    if ($ivrId !== '') {
        $ivrNamesByContext['ivr-' . $ivrId] = trim((string) ($ivr['name'] ?? $ivr['exten'] ?? $ivrId));
    }
}
$cdrDescription = static function (array $cdr) use ($ivrNamesByContext): string {
    $parts = explode('|', (string) ($cdr['userfield'] ?? ''));
    if (($parts[1] ?? '') === 'CID') {
        $labels = [];
        $inbound = trim((string) ($parts[2] ?? ''));
        $destinationType = strtoupper(trim((string) ($parts[3] ?? '')));
        $destination = trim((string) ($parts[4] ?? ''));
        if ($inbound !== '') {
            $labels[] = 'Inbound: ' . $inbound;
        }
        if ($destination !== '') {
            $labels[] = match ($destinationType) {
                'QUEUE' => 'Kuyruk: ' . $destination,
                'RINGGROUP' => 'Ring grup: ' . $destination,
                default => 'Hedef: ' . $destination,
            };
        }
        return implode(' · ', $labels);
    }
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
    if ((string) ($cdr['dst'] ?? '') === 's') {
        $lastApplication = strtoupper(trim((string) ($cdr['lastapp'] ?? '')));
        $lastData = trim((string) ($cdr['lastdata'] ?? ''));
        $ivrName = $ivrNamesByContext[(string) ($cdr['dcontext'] ?? '')] ?? '';
        if (in_array($lastApplication, ['BACKGROUND', 'PLAYBACK'], true)) {
            $announcement = trim(explode(',', $lastData, 2)[0]);
            $announcement = preg_replace('~^(?:custom/)?[^/]+/~', '', $announcement) ?? $announcement;
            return ($ivrName !== '' ? $ivrName . ' IVR’sinde anonsta kapandı' : 'Anonsta kapandı')
                . ($announcement !== '' ? ' · Anons: ' . $announcement : '');
        }
        if (str_starts_with((string) ($cdr['dcontext'] ?? ''), 'ivr-')) {
            return ($ivrName !== '' ? $ivrName . ' IVR’sinde' : 'IVR menüsünde') . ' kapandı';
        }
    }
    return '';
};
$reportDuration = max(
    0.0,
    microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))
);
?>
<div class="toolbar">
    <p class="muted">
        CDR kayıtları santral PostgreSQL veritabanından okunur.
        <?php if ($hasSubmittedFilters): ?>
            Toplam <?= (int) $totalRows ?> kayıt · Tamamı listeleniyor
        <?php else: ?>
            Son <?= count($rows) ?> kayıt · Ayrıntılı rapor için filtre uygulayın
        <?php endif; ?>
        · Hazırlama: <?= e(number_format($reportDuration, 3, ',', '.')) ?> sn.
    </p>
    <div class="toolbar">
        <a class="btn sm" href="cdr_export.php?<?= e(http_build_query(array_merge($filters, ['format' => 'excel']))) ?>">Excel'e aktar</a>
        <a class="btn sm" href="cdr_export.php?<?= e(http_build_query(array_merge($filters, ['format' => 'pdf']))) ?>">PDF'e aktar</a>
        <button class="btn sm" type="button" onclick="location.reload()">Yenile</button>
    </div>
</div>
<article class="card">
    <form method="get" class="cdr-filters">
        <input type="hidden" name="p" value="cdr">
        <?php render_report_period_filter($filters); ?>
        <label>Arayan<input name="src" value="<?= e($filters['src']) ?>" placeholder="905..."></label>
        <label>Aranan<input name="dst" value="<?= e($filters['dst']) ?>" placeholder="8001"></label>
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
                <th>Trunk</th>
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
            <?php $trunkLabels = pbx_cdr_trunk_labels($row, current_dept_id()); ?>
            <?php
            $callerInfo = pbx_cdr_internal_info($row, current_dept_id());
            $callerExtension = $callerInfo['extension'] ?? null;
            $callerEndpoint = (string) ($callerInfo['endpoint'] ?? '');
            ?>
            <tr>
                <td><?= e($row['accountcode'] ?: $row['userfield'] ?: '—') ?></td>
                <td class="num nowrap"><?= e($row['start']) ?></td>
                <td class="num nowrap"><?= e($row['src']) ?></td>
                <td>
                    <?php if ($callerExtension): ?>
                        <?= e(trim((string) ($callerExtension['exten'] ?? '') . ' ' . (string) ($callerExtension['name'] ?? ''))) ?>
                        <br><small><code><?= e($callerEndpoint) ?></code></small>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="num nowrap"><?= e($row['dst']) ?></td>
                <td>
                    <?= $trunkLabels ? implode('<br>', array_map('e', $trunkLabels)) : '—' ?>
                </td>
                <td class="nowrap"><?= e($row['billsec']) ?> sn</td>
                <td><span class="pill <?= $row['disposition'] === 'ANSWERED' ? 'ok' : 'dim' ?>"><?= e($row['disposition']) ?></span></td>
                <td>
                    <?php if ($description !== ''): ?>
                        <span class="pill <?= str_contains((string) ($row['userfield'] ?? ''), '|CID|') ? 'dim' : 'bad' ?>"><?= e($description) ?></span>
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
            <tr><td colspan="11" class="muted">Kayıt yok veya CDR okunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
