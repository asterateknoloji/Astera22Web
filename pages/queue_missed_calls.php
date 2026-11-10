<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/report_period.php';

$filters = report_period_filters($_GET);
$validDate = static fn(string $value): bool =>
    preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
    && DateTimeImmutable::createFromFormat('!Y-m-d', $value) !== false;
if (!$validDate($filters['date_from']) || !$validDate($filters['date_to'])) {
    $filters = report_period_filters([]);
}
if ($filters['date_from'] > $filters['date_to']) {
    [$filters['date_from'], $filters['date_to']] = [$filters['date_to'], $filters['date_from']];
}

$queueFilter = trim((string) ($_GET['queue_id'] ?? ''));
$phoneFilter = preg_replace('/\D+/', '', (string) ($_GET['phone'] ?? '')) ?? '';
$mailFilter = (string) ($_GET['mail_status'] ?? '');
$allowedMailFilters = ['', 'sent', 'partial', 'failed', 'pending', 'not_sent'];
if (!in_array($mailFilter, $allowedMailFilters, true)) {
    $mailFilter = '';
}
$cursorTime = trim((string) ($_GET['cursor_time'] ?? ''));
$cursorId = max(0, (int) ($_GET['cursor_id'] ?? 0));
$pageSize = 500;

$queues = scoped('queues');
$queueMap = [];
foreach ($queues as $queue) {
    $queueId = trim((string) ($queue['id'] ?? ''));
    $deptId = trim((string) ($queue['dept'] ?? ''));
    if ($queueId !== '' && $deptId !== '') {
        $queueMap[$queueId] = [
            'dept' => $deptId,
            'name' => trim((string) ($queue['name'] ?? '')) ?: $queueId,
        ];
    }
}
ksort($queueMap, SORT_NATURAL);

$rows = [];
$reportDuration = 0.0;
if ($queueMap) {
    $queueValues = implode(',', array_map(
        static fn(string $queueId, array $queue): string => '('
            . callcenter_literal($queueId) . ','
            . callcenter_literal((string) $queue['dept']) . ','
            . callcenter_literal((string) $queue['name']) . ')',
        array_keys($queueMap),
        array_values($queueMap)
    ));
    $where = [
        'q.time>=:date_from::date',
        "q.time<(:date_to::date+interval '1 day')",
        "q.event IN ('ABANDON','EXITWITHTIMEOUT')",
    ];
    $params = [
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
    ];
    if ($queueFilter !== '' && isset($queueMap[$queueFilter])) {
        $where[] = 'q.queuename=:queue_id';
        $params['queue_id'] = $queueFilter;
    }
    if ($phoneFilter !== '') {
        $where[] = "regexp_replace(COALESCE(en.data2,''),'[^0-9]','','g') LIKE :phone";
        $params['phone'] = '%' . $phoneFilter . '%';
    }
    $statusExpression = "CASE "
        . "WHEN delivery_count>0 AND sent_count=delivery_count THEN 'sent' "
        . "WHEN sent_count>0 THEN 'partial' "
        . "WHEN failed_count>0 THEN 'failed' "
        . "WHEN pending_count>0 THEN 'pending' "
        . "WHEN recipient_count>0 AND event_time>=now()-interval '2 minutes' THEN 'pending' "
        . "ELSE 'not_sent' END";
    $mailWhere = '';
    if ($mailFilter !== '') {
        $mailWhere = 'WHERE mail_status=:mail_status';
        $params['mail_status'] = $mailFilter;
    }
    $cursorWhere = '';
    if ($cursorTime !== '' && $cursorId > 0) {
        $cursorWhere = 'WHERE (event_time,queue_log_id)<(:cursor_time::timestamp,:cursor_id)';
        $params['cursor_time'] = $cursorTime;
        $params['cursor_id'] = $cursorId;
    }
    $sql = "WITH queue_map(queue_id,dept_id,queue_name) AS (VALUES {$queueValues}), "
        . 'base AS (SELECT q.id AS queue_log_id,q.time AS event_time,q.callid,q.queuename,'
        . 'qm.dept_id,qm.queue_name,q.event,'
        . "COALESCE(en.data2,'') AS caller_number,"
        . "CASE WHEN q.data1~'^[0-9]+$' THEN q.data1::integer END AS exit_position,"
        . "CASE WHEN q.data3~'^[0-9]+$' THEN q.data3::integer ELSE 0 END AS wait_seconds,"
        . 'COALESCE(d.delivery_count,0)::integer AS delivery_count,'
        . 'COALESCE(d.sent_count,0)::integer AS sent_count,'
        . 'COALESCE(d.failed_count,0)::integer AS failed_count,'
        . 'COALESCE(d.pending_count,0)::integer AS pending_count,'
        . "COALESCE(d.delivery_recipients,'') AS delivery_recipients,"
        . "COALESCE(d.error_message,'') AS error_message,d.last_sent_at,"
        . 'COALESCE(r.recipient_count,0)::integer AS recipient_count,'
        . "COALESCE(r.active_recipients,'') AS active_recipients,s.created_at AS service_started_at "
        . 'FROM queue_log q JOIN queue_map qm ON qm.queue_id=q.queuename '
        . 'LEFT JOIN LATERAL (SELECT e.data2 FROM queue_log e '
        . "WHERE e.callid=q.callid AND e.queuename=q.queuename AND e.event='ENTERQUEUE' "
        . 'ORDER BY e.id DESC LIMIT 1) en ON TRUE '
        . 'LEFT JOIN LATERAL (SELECT count(*)::integer AS delivery_count,'
        . "count(*) FILTER (WHERE status='sent')::integer AS sent_count,"
        . "count(*) FILTER (WHERE status='failed')::integer AS failed_count,"
        . "count(*) FILTER (WHERE status='pending')::integer AS pending_count,"
        . "string_agg(recipient_email||' ('||status||')',', ' ORDER BY recipient_email) AS delivery_recipients,"
        . "string_agg(DISTINCT NULLIF(error_message,''),'; ') AS error_message,max(sent_at) AS last_sent_at "
        . 'FROM callcenter_queue_notification_deliveries nd '
        . 'WHERE nd.dept_id=qm.dept_id AND nd.queue_log_id=q.id) d ON TRUE '
        . 'LEFT JOIN LATERAL (SELECT count(*)::integer AS recipient_count,'
        . "string_agg(email,', ' ORDER BY email) AS active_recipients "
        . 'FROM callcenter_queue_notification_recipients nr '
        . 'WHERE nr.dept_id=qm.dept_id AND nr.queue_id=q.queuename AND nr.active) r ON TRUE '
        . 'LEFT JOIN callcenter_queue_notification_state s ON s.dept_id=qm.dept_id '
        . 'WHERE ' . implode(' AND ', $where) . '), '
        . "classified AS (SELECT base.*,{$statusExpression} AS mail_status FROM base), "
        . "filtered AS (SELECT * FROM classified {$mailWhere}), "
        . 'counted AS (SELECT filtered.*,count(*) OVER()::bigint AS total_count FROM filtered) '
        . "SELECT * FROM counted {$cursorWhere} "
        . "ORDER BY event_time DESC,queue_log_id DESC LIMIT {$pageSize}";
    $startedAt = microtime(true);
    $rows = callcenter_rows($sql, $params);
    $reportDuration = microtime(true) - $startedAt;
}

$totalCount = (int) ($rows[0]['total_count'] ?? 0);
$statusLabels = [
    'sent' => ['Gönderildi', 'ok'],
    'partial' => ['Kısmen gönderildi', 'warn'],
    'failed' => ['Gönderilemedi', 'bad'],
    'pending' => ['Bekliyor', 'warn'],
    'not_sent' => ['Gönderilmedi', 'dim'],
];
$reasonFor = static function (array $row): string {
    $status = (string) ($row['mail_status'] ?? 'not_sent');
    if ($status === 'sent') {
        return 'Tüm alıcılara başarıyla gönderildi.';
    }
    if ($status === 'partial') {
        return trim((string) ($row['error_message'] ?? ''))
            ?: 'Bazı alıcılara gönderildi, bazı alıcılarda hata oluştu.';
    }
    if ($status === 'failed') {
        return trim((string) ($row['error_message'] ?? ''))
            ?: 'E-posta gönderimi başarısız oldu.';
    }
    if ($status === 'pending') {
        return 'Bildirim servisi tarafından işlenmesi bekleniyor.';
    }
    if ((int) ($row['recipient_count'] ?? 0) === 0) {
        return 'Bu kuyruk için aktif e-posta alıcısı tanımlı değildi.';
    }
    if (!empty($row['service_started_at'])
        && strtotime((string) $row['event_time']) < strtotime((string) $row['service_started_at'])) {
        return 'Kaçan çağrı bildirim özelliği bu olaydan sonra etkinleştirildi.';
    }
    return 'Bildirim kaydı oluşmadı; servis günlükleri kontrol edilmeli.';
};
?>
<div class="toolbar">
    <p class="muted">
        PostgreSQL kuyruk olayları ve e-posta teslim kayıtları birlikte gösterilir.
        Toplam <?= $totalCount ?> kaçan çağrı · Hazırlama <?= e(number_format($reportDuration, 3, ',', '.')) ?> sn.
    </p>
    <button class="btn sm" type="button" onclick="location.reload()">Yenile</button>
</div>

<article class="card">
    <form method="get" class="cdr-filters">
        <input type="hidden" name="p" value="queue_missed_calls">
        <?php render_report_period_filter($filters); ?>
        <label>Kuyruk
            <select name="queue_id">
                <option value="">Tüm kuyruklar</option>
                <?php foreach ($queueMap as $queueId => $queue): ?>
                    <option value="<?= e($queueId) ?>" <?= $queueFilter === $queueId ? 'selected' : '' ?>>
                        <?= e($queue['name'] . ' (' . $queueId . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Arayan numara<input name="phone" value="<?= e($phoneFilter) ?>" placeholder="0530..."></label>
        <label>Mail durumu
            <select name="mail_status">
                <option value="">Tümü</option>
                <?php foreach ($statusLabels as $value => [$label]): ?>
                    <option value="<?= e($value) ?>" <?= $mailFilter === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="modal-actions">
            <a class="btn" href="index.php?p=queue_missed_calls">Temizle</a>
            <button class="btn primary" type="submit">Raporla</button>
        </div>
    </form>
</article>

<article class="card flush">
    <table>
        <thead>
            <tr>
                <th>Tarih / Saat</th>
                <th>Kuyruk</th>
                <th>Numara</th>
                <th>Kaçtığı sıra</th>
                <th>Bekleme</th>
                <th>Mail durumu</th>
                <th>Alıcılar</th>
                <th>Gönderim zamanı</th>
                <th>Açıklama / hata nedeni</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php [$statusLabel, $statusClass] = $statusLabels[(string) $row['mail_status']] ?? ['Bilinmiyor', 'dim']; ?>
            <tr>
                <td><?= e(date('d.m.Y H:i:s', strtotime((string) $row['event_time']))) ?></td>
                <td><strong><?= e($row['queue_name']) ?></strong><br><code><?= e($row['queuename']) ?></code></td>
                <td><strong><?= e($row['caller_number'] ?: 'Bilinmiyor') ?></strong></td>
                <td><?= $row['exit_position'] === null ? '—' : (int) $row['exit_position'] ?></td>
                <td><?= (int) $row['wait_seconds'] ?> sn</td>
                <td><span class="pill <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                <td><?= e($row['delivery_recipients'] ?: $row['active_recipients'] ?: '—') ?></td>
                <td><?= empty($row['last_sent_at']) ? '—' : e(date('d.m.Y H:i:s', strtotime((string) $row['last_sent_at']))) ?></td>
                <td><?= e($reasonFor($row)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="9" class="muted">Seçilen dönemde kaçan kuyruk çağrısı bulunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>

<?php if (count($rows) === $pageSize): ?>
    <?php
    $last = $rows[array_key_last($rows)];
    $nextQuery = $_GET;
    $nextQuery['p'] = 'queue_missed_calls';
    $nextQuery['cursor_time'] = (string) $last['event_time'];
    $nextQuery['cursor_id'] = (int) $last['queue_log_id'];
    ?>
    <div class="modal-actions">
        <a class="btn" href="index.php?<?= e(http_build_query($nextQuery)) ?>">Sonraki <?= $pageSize ?> kayıt</a>
    </div>
<?php endif; ?>
