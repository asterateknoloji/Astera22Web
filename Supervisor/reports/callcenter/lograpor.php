<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';

$today = date('Y-m-d');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? ''))
    ? (string) $_GET['from'] : $today;
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? ''))
    ? (string) $_GET['to'] : $today;
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$username = trim((string) ($_GET['username'] ?? ''));
$page = trim((string) ($_GET['page'] ?? ''));
$category = in_array($_GET['category'] ?? '', ['callcenter', 'cdr', 'system', 'auth'], true)
    ? (string) $_GET['category'] : '';
$eventType = in_array($_GET['event_type'] ?? '', [
    'login', 'logout', 'page_view', 'create', 'update', 'delete', 'access_denied', 'export',
], true) ? (string) $_GET['event_type'] : '';
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$before = max(0, (int) ($_GET['before'] ?? 0));

$where = [
    'dept_id=:dept',
    'created_at >= :from::date',
    "created_at < (:to::date + interval '1 day')",
];
$params = ['dept' => $deptId, 'from' => $from, 'to' => $to];
foreach ([
    'username' => [$username, 'username=:username'],
    'page' => [$page, 'page=:page'],
    'category' => [$category, 'category=:category'],
    'event' => [$eventType, 'event_type=:event'],
] as $name => [$value, $condition]) {
    if ($value !== '') {
        $where[] = $condition;
        $params[$name] = $value;
    }
}
if ($search !== '') {
    $where[] = '(username ILIKE :search OR page ILIKE :search OR action ILIKE :search OR description ILIKE :search OR request_path ILIKE :search)';
    $params['search'] = '%' . $search . '%';
}
$filterSql = implode(' AND ', $where);
$stats = callcenter_rows(
    "SELECT count(*)::integer total,"
    . "count(*) FILTER (WHERE event_type='login')::integer logins,"
    . "count(*) FILTER (WHERE event_type='logout')::integer logouts,"
    . "count(*) FILTER (WHERE event_type='access_denied')::integer denied "
    . "FROM callcenter_audit_logs WHERE {$filterSql}",
    $params
)[0] ?? ['total' => 0, 'logins' => 0, 'logouts' => 0, 'denied' => 0];

$rowWhere = $where;
$rowParams = $params;
if ($before > 0) {
    $rowWhere[] = 'audit_id < :before';
    $rowParams['before'] = $before;
}
$rows = callcenter_rows(
    "SELECT audit_id,username,event_type,category,page,action,description,request_method,request_path,"
    . "COALESCE(host(ip_address),'') AS ip_address,"
    . "to_char(created_at AT TIME ZONE 'Europe/Istanbul','DD.MM.YYYY HH24:MI:SS') AS event_time "
    . 'FROM callcenter_audit_logs WHERE ' . implode(' AND ', $rowWhere)
    . ' ORDER BY audit_id DESC LIMIT 101',
    $rowParams
);
$hasNext = count($rows) > 100;
if ($hasNext) {
    array_pop($rows);
}

$filterOptions = callcenter_rows(
    "SELECT DISTINCT username FROM callcenter_audit_logs WHERE dept_id=:dept ORDER BY username",
    ['dept' => $deptId]
);
$pageOptions = callcenter_rows(
    "SELECT DISTINCT page FROM callcenter_audit_logs WHERE dept_id=:dept ORDER BY page",
    ['dept' => $deptId]
);
$eventLabels = [
    'login' => 'Giriş', 'logout' => 'Çıkış', 'page_view' => 'Görüntüleme',
    'create' => 'Ekleme', 'update' => 'Güncelleme', 'delete' => 'Silme',
    'access_denied' => 'Yetki Reddi', 'export' => 'Dışa Aktarım',
];
$categoryLabels = ['callcenter' => 'Callcenter', 'cdr' => 'CDR', 'system' => 'Sistem', 'auth' => 'Oturum'];

supervisor_page_start('Log Raporu', '../../dashboard.php?tab=callcenter&subtab=sistem');
?>
<form class="filter-bar audit-filter" method="get">
    <label class="filter-group"><span class="filter-label">Başlangıç</span><input type="date" name="from" value="<?= e($from) ?>"></label>
    <label class="filter-group"><span class="filter-label">Bitiş</span><input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="filter-group"><span class="filter-label">Kullanıcı</span><select name="username"><option value="">Tümü</option>
        <?php foreach ($filterOptions as $option): ?><option value="<?= e((string) $option['username']) ?>"<?= $username === (string) $option['username'] ? ' selected' : '' ?>><?= e((string) $option['username']) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group"><span class="filter-label">Kategori</span><select name="category"><option value="">Tümü</option>
        <?php foreach ($categoryLabels as $value => $label): ?><option value="<?= $value ?>"<?= $category === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group"><span class="filter-label">İşlem</span><select name="event_type"><option value="">Tümü</option>
        <?php foreach ($eventLabels as $value => $label): ?><option value="<?= $value ?>"<?= $eventType === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group"><span class="filter-label">Sayfa</span><select name="page"><option value="">Tümü</option>
        <?php foreach ($pageOptions as $option): ?><option value="<?= e((string) $option['page']) ?>"<?= $page === (string) $option['page'] ? ' selected' : '' ?>><?= e((string) $option['page']) ?></option><?php endforeach; ?>
    </select></label>
    <label class="filter-group audit-search"><span class="filter-label">Arama</span><input name="q" value="<?= e($search) ?>" placeholder="Kullanıcı, sayfa veya açıklama"></label>
    <button class="btn btn-primary" type="submit">Filtrele</button>
    <a class="btn btn-outline" href="lograpor.php">Temizle</a>
</form>

<section class="stats-grid">
    <article class="stat-card"><div class="stat-label">Toplam İşlem</div><div class="stat-value"><?= (int) $stats['total'] ?></div></article>
    <article class="stat-card"><div class="stat-label">Giriş</div><div class="stat-value"><?= (int) $stats['logins'] ?></div></article>
    <article class="stat-card"><div class="stat-label">Çıkış</div><div class="stat-value"><?= (int) $stats['logouts'] ?></div></article>
    <article class="stat-card"><div class="stat-label">Yetki Reddi</div><div class="stat-value"><?= (int) $stats['denied'] ?></div></article>
</section>

<div class="table-container">
    <div class="table-header">Sistem İşlem Kayıtları</div>
    <table class="audit-table">
        <thead><tr><th>Tarih / Saat</th><th>Kullanıcı</th><th>İşlem</th><th>Kategori</th><th>Sayfa</th><th>Açıklama</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td class="audit-time"><?= e((string) $row['event_time']) ?></td>
                <td><strong><?= e((string) $row['username']) ?></strong></td>
                <td><span class="badge audit-event <?= e((string) $row['event_type']) ?>"><?= e($eventLabels[(string) $row['event_type']] ?? (string) $row['event_type']) ?></span></td>
                <td><?= e($categoryLabels[(string) $row['category']] ?? (string) $row['category']) ?></td>
                <td><code><?= e((string) $row['page']) ?></code><small class="audit-method"><?= e((string) $row['request_method']) ?></small></td>
                <td><strong><?= e((string) $row['action']) ?></strong><?php if ((string) $row['description'] !== ''): ?><small class="audit-description"><?= e((string) $row['description']) ?></small><?php endif; ?></td>
                <td><?= e((string) $row['ip_address']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="empty">Seçilen filtrelerde log kaydı bulunamadı.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php if ($hasNext && $rows):
    $nextQuery = $_GET;
    $nextQuery['from'] = $from;
    $nextQuery['to'] = $to;
    $nextQuery['before'] = (int) end($rows)['audit_id'];
?>
<div class="audit-pagination"><a class="btn btn-outline" href="?<?= e(http_build_query($nextQuery)) ?>">Daha Eski Kayıtlar →</a></div>
<?php endif; ?>
<?php supervisor_page_end(); ?>
