<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
supervisor_require_permission('sistem_ayarlar');

$message = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_ok()) {
            throw new RuntimeException('İstek doğrulanamadı. Sayfayı yenileyin.');
        }
        $agentId = max(0, (int) ($_POST['agent_id'] ?? 0));
        $status = (string) ($_POST['status'] ?? '');
        if ($agentId < 1 || !in_array($status, ['active', 'inactive', 'locked'], true)) {
            throw new InvalidArgumentException('Geçersiz Agent veya durum seçimi.');
        }
        $agent = callcenter_rows(
            'SELECT agent_id,display_name,status FROM callcenter_agents '
            . 'WHERE dept_id=:dept AND agent_id=:agent',
            ['dept' => $deptId, 'agent' => $agentId]
        )[0] ?? null;
        if (!$agent) {
            throw new RuntimeException('Agent bulunamadı.');
        }
        if ($status !== 'active') {
            if ((string) $agent['status'] === 'active') {
                callcenter_logout_agent_queues($deptId, $agentId);
            }
            callcenter_execute_batch([
                [
                    'UPDATE callcenter_breaks SET ended_at=now(),updated_at=now() '
                    . 'WHERE dept_id=:dept AND agent_id=:agent AND ended_at IS NULL',
                    ['dept' => $deptId, 'agent' => $agentId],
                ],
                [
                    "UPDATE callcenter_agent_sessions SET ended_at=now(),end_reason='admin',updated_at=now() "
                    . 'WHERE dept_id=:dept AND agent_id=:agent AND ended_at IS NULL',
                    ['dept' => $deptId, 'agent' => $agentId],
                ],
                [
                    'UPDATE callcenter_agents SET status=:status,updated_at=now() '
                    . 'WHERE dept_id=:dept AND agent_id=:agent',
                    ['status' => $status, 'dept' => $deptId, 'agent' => $agentId],
                ],
            ]);
        } else {
            callcenter_execute(
                'UPDATE callcenter_agents SET status=:status,updated_at=now() '
                . 'WHERE dept_id=:dept AND agent_id=:agent',
                ['status' => $status, 'dept' => $deptId, 'agent' => $agentId]
            );
        }
        $auditIdentity = $supervisor;
        $auditIdentity['dept_id'] = $deptId;
        callcenter_audit_write(
            $auditIdentity,
            'update',
            'agent_yonetim',
            'Agent durumu değiştirildi',
            (string) $agent['display_name'] . ' → ' . $status
        );
        $message = 'Agent durumu güncellendi.';
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$agents = callcenter_rows(
    "SELECT a.agent_id,a.username,a.display_name,a.extension,a.status,"
    . "COALESCE(q.queue_names,'') AS queue_names,"
    . "to_char(last_session.started_at,'DD.MM.YYYY HH24:MI:SS') AS last_login,"
    . '(active_session.session_id IS NOT NULL) AS is_logged_in,'
    . 'bt.name AS active_break,su.role AS panel_role '
    . 'FROM callcenter_agents a '
    . "LEFT JOIN LATERAL (SELECT string_agg(queue_id,', ' ORDER BY queue_id) AS queue_names "
    . 'FROM callcenter_agent_queue_assignments WHERE dept_id=a.dept_id '
    . 'AND agent_id=a.agent_id AND active) q ON TRUE '
    . 'LEFT JOIN LATERAL (SELECT started_at FROM callcenter_agent_sessions '
    . 'WHERE dept_id=a.dept_id AND agent_id=a.agent_id ORDER BY started_at DESC LIMIT 1) last_session ON TRUE '
    . 'LEFT JOIN LATERAL (SELECT session_id FROM callcenter_agent_sessions '
    . 'WHERE dept_id=a.dept_id AND agent_id=a.agent_id AND ended_at IS NULL LIMIT 1) active_session ON TRUE '
    . 'LEFT JOIN callcenter_breaks ab ON ab.dept_id=a.dept_id AND ab.agent_id=a.agent_id AND ab.ended_at IS NULL '
    . 'LEFT JOIN callcenter_break_types bt ON bt.dept_id=ab.dept_id AND bt.break_type_id=ab.break_type_id '
    . 'LEFT JOIN callcenter_supervisor_users su ON su.dept_id=a.dept_id AND su.username=a.username '
    . 'WHERE a.dept_id=:dept ORDER BY a.display_name,a.agent_id',
    ['dept' => $deptId]
);

$loggedIn = count(array_filter($agents, static fn(array $agent): bool => supervisor_bool($agent['is_logged_in'] ?? false)));
$onBreak = count(array_filter($agents, static fn(array $agent): bool =>
    supervisor_bool($agent['is_logged_in'] ?? false) && !empty($agent['active_break'])
));

supervisor_page_start('Agent Yönetimi', '../../dashboard.php?tab=callcenter&subtab=sistem');
?>
<?php if ($message !== ''): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="dashboard-welcome">
    <div><span class="eyebrow">OPERASYON YÖNETİMİ</span><h2>Agent durumları</h2>
        <p>Oturum, kuyruk ve yetki durumlarını izleyin; erişimi güvenli şekilde yönetin.</p></div>
    <a class="btn btn-primary" href="agent_tanim.php">Agent Tanımlarını Düzenle</a>
</div>
<section class="stats-grid">
    <article class="stat-card"><div class="stat-label">Toplam Agent</div><div class="stat-value"><?= count($agents) ?></div></article>
    <article class="stat-card"><div class="stat-label">Giriş Yapmış</div><div class="stat-value"><?= $loggedIn ?></div></article>
    <article class="stat-card"><div class="stat-label">Molada</div><div class="stat-value"><?= $onBreak ?></div></article>
    <article class="stat-card"><div class="stat-label">Hazır</div><div class="stat-value"><?= max(0, $loggedIn - $onBreak) ?></div></article>
</section>
<div class="table-container"><div class="table-header">Agent Listesi</div>
<table><thead><tr><th>Agent</th><th>Dahili</th><th>Oturum</th><th>Kuyruklar</th><th>Son Giriş</th><th>Panel Yetkisi</th><th>Hesap Durumu</th><th>İşlem</th></tr></thead><tbody>
<?php foreach ($agents as $agent):
    $isLoggedIn = supervisor_bool($agent['is_logged_in'] ?? false);
    $sessionLabel = !$isLoggedIn ? 'Çevrimdışı' : (!empty($agent['active_break']) ? 'Molada: ' . $agent['active_break'] : 'Hazır');
?>
<tr>
    <td><strong><?= e((string) $agent['display_name']) ?></strong><small class="agent-detail-sub"><?= e((string) $agent['username']) ?></small></td>
    <td><span class="badge"><?= e((string) $agent['extension']) ?></span></td>
    <td><span class="badge <?= !$isLoggedIn ? 'badge-danger' : (!empty($agent['active_break']) ? 'badge-warning' : 'badge-success') ?>"><?= e($sessionLabel) ?></span></td>
    <td><?= e((string) ($agent['queue_names'] ?: '—')) ?></td>
    <td><?= e((string) ($agent['last_login'] ?: '—')) ?></td>
    <td><?= e((string) ($agent['panel_role'] ?: 'Agent')) ?></td>
    <td><span class="badge"><?= e((string) $agent['status']) ?></span></td>
    <td><form method="post" class="inline-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="agent_id" value="<?= (int) $agent['agent_id'] ?>">
        <select name="status" aria-label="Agent durumu">
            <?php foreach (['active' => 'Aktif', 'inactive' => 'Pasif', 'locked' => 'Kilitli'] as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= (string) $agent['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline" type="submit">Uygula</button>
    </form></td>
</tr>
<?php endforeach; ?>
<?php if (!$agents): ?><tr><td colspan="8" class="empty">Bu firma için Agent tanımı bulunamadı.</td></tr><?php endif; ?>
</tbody></table></div>
<?php supervisor_page_end(); ?>
