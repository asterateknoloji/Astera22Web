<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
supervisor_require_permission('sistem_ayarlar');

$flashKey = 'callcenter_mail_recipients_flash';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auditIdentity = $supervisor;
    $auditIdentity['dept_id'] = $deptId;
    try {
        if (!csrf_ok()) {
            throw new RuntimeException('İstek doğrulanamadı. Sayfayı yenileyip tekrar deneyin.');
        }
        $queueId = trim((string) ($_POST['queue_id'] ?? ''));
        if ($queueId === '' || !in_array($queueId, $queueIds, true)) {
            throw new InvalidArgumentException('Yalnız yetkili olduğunuz mevcut bir kuyruk güncellenebilir.');
        }
        $rawEmails = trim((string) ($_POST['emails'] ?? ''));
        $emails = [];
        if ($rawEmails !== '') {
            foreach (explode(',', $rawEmails) as $rawEmail) {
                $email = mb_strtolower(trim($rawEmail), 'UTF-8');
                if ($email === '' || mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new InvalidArgumentException('Geçerli e-posta adresleri girin ve adresleri virgülle ayırın.');
                }
                $emails[$email] = $email;
            }
        }
        $emails = array_values($emails);
        if (count($emails) > 50) {
            throw new InvalidArgumentException('Bir kuyruk için en fazla 50 e-posta alıcısı tanımlanabilir.');
        }
        $statements = [[
            'DELETE FROM callcenter_queue_notification_recipients WHERE dept_id=:dept AND queue_id=:queue',
            ['dept' => $deptId, 'queue' => $queueId],
        ]];
        foreach ($emails as $email) {
            $statements[] = [
                'INSERT INTO callcenter_queue_notification_recipients (dept_id,queue_id,email,active) '
                . 'VALUES (:dept,:queue,:email,TRUE)',
                ['dept' => $deptId, 'queue' => $queueId, 'email' => $email],
            ];
        }
        callcenter_execute_batch($statements);
        callcenter_audit_write(
            $auditIdentity,
            $emails ? 'update' : 'delete',
            'mailtanimlari',
            $emails ? 'Kuyruk mail alıcıları kaydedildi' : 'Kuyruk mail alıcıları temizlendi',
            'Kuyruk: ' . $queueId . ', alıcı sayısı: ' . count($emails)
        );
        $_SESSION[$flashKey] = [
            'message' => $emails ? 'Mail alıcıları kaydedildi.' : 'Kuyruğun mail alıcıları temizlendi.',
            'error' => false,
        ];
    } catch (Throwable $exception) {
        $message = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'İşlem tamamlanamadı.';
        callcenter_audit_write($auditIdentity, 'access_denied', 'mailtanimlari', 'POST işlemi reddedildi', $message);
        $_SESSION[$flashKey] = ['message' => $message, 'error' => true];
    }
    header('Location: mailtanimlari.php');
    exit;
}

$flash = (array) ($_SESSION[$flashKey] ?? []);
unset($_SESSION[$flashKey]);
$recipientRows = callcenter_rows(
    'SELECT queue_id,email,active FROM callcenter_queue_notification_recipients '
    . 'WHERE dept_id=:dept ORDER BY queue_id,email',
    ['dept' => $deptId]
);
$recipientsByQueue = [];
foreach ($recipientRows as $recipient) {
    if (supervisor_bool($recipient['active'])) {
        $recipientsByQueue[(string) $recipient['queue_id']][] = (string) $recipient['email'];
    }
}

supervisor_page_start('Mail Tanımları', '../../dashboard.php?tab=callcenter&subtab=sistem');
if ($flash) {
    supervisor_notice((string) ($flash['message'] ?? ''), !empty($flash['error']));
}
?>
<div class="alert">Her e-posta alıcısı PostgreSQL'de ayrı satırda saklanır. Birden fazla adresi virgülle ayırın; alanı boş kaydetmek kuyruğun tüm alıcılarını siler.</div>
<div class="table-container">
    <div class="table-header">Kuyruk Mail Alıcıları · <?= count($queues) ?> kuyruk</div>
    <table>
        <thead><tr><th>Kuyruk</th><th>Ad</th><th>Aktif Alıcılar</th><th>İşlem</th></tr></thead>
        <tbody>
        <?php foreach ($queues as $queue): ?>
            <?php
            $queueId = (string) ($queue['id'] ?? '');
            $emails = $recipientsByQueue[$queueId] ?? [];
            ?>
            <tr>
                <td><span class="badge"><?= e($queueId) ?></span></td>
                <td><?= e((string) ($queue['name'] ?? $queueId)) ?></td>
                <td>
                    <?php if ($emails): ?>
                        <?php foreach ($emails as $email): ?><span class="badge badge-success"><?= e($email) ?></span> <?php endforeach; ?>
                    <?php else: ?>
                        <span class="empty">Alıcı tanımlı değil</span>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="queue_id" value="<?= e($queueId) ?>">
                        <label>Virgülle ayrılmış e-postalar
                            <textarea name="emails" rows="3" placeholder="operasyon@example.com, yonetici@example.com"><?= e(implode(', ', $emails)) ?></textarea>
                        </label>
                        <button class="btn btn-primary" type="submit">Alıcıları Kaydet</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$queues): ?><tr><td colspan="4" class="empty">Yetkili olduğunuz kuyruk bulunamadı.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php supervisor_page_end(); ?>
