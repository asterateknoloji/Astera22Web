<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
supervisor_require_permission('sistem_ayarlar');

$flashKey = 'callcenter_settings_flash';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auditIdentity = $supervisor;
    $auditIdentity['dept_id'] = $deptId;
    try {
        if (!csrf_ok()) {
            throw new RuntimeException('İstek doğrulanamadı. Sayfayı yenileyip tekrar deneyin.');
        }
        $action = (string) ($_POST['action'] ?? 'save');
        $name = trim((string) ($_POST['name'] ?? ''));
        $originalName = trim((string) ($_POST['original_name'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,119}$/', $name)) {
            throw new InvalidArgumentException('Ayar anahtarı harf veya rakamla başlamalı; yalnız harf, rakam, nokta, tire ve alt çizgi içermelidir.');
        }
        if ($action === 'delete') {
            callcenter_execute(
                'DELETE FROM callcenter_settings WHERE dept_id=:dept AND name=:name',
                ['dept' => $deptId, 'name' => $name]
            );
            callcenter_audit_write($auditIdentity, 'delete', 'ayarlar', 'Ayar silindi', 'Ayar: ' . $name);
            $_SESSION[$flashKey] = ['message' => 'Ayar silindi.', 'error' => false];
        } elseif ($action === 'save') {
            $value = (string) ($_POST['value'] ?? '');
            if (mb_strlen($value) > 4000) {
                throw new InvalidArgumentException('Ayar değeri en fazla 4000 karakter olabilir.');
            }
            $statements = [];
            if ($originalName !== '' && $originalName !== $name) {
                if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,119}$/', $originalName)) {
                    throw new InvalidArgumentException('Mevcut ayar anahtarı geçersiz.');
                }
                $statements[] = [
                    'DELETE FROM callcenter_settings WHERE dept_id=:dept AND name=:name',
                    ['dept' => $deptId, 'name' => $originalName],
                ];
            }
            $statements[] = [
                'INSERT INTO callcenter_settings (dept_id,name,value) VALUES (:dept,:name,:value) '
                . 'ON CONFLICT (dept_id,name) DO UPDATE SET value=EXCLUDED.value,updated_at=now()',
                ['dept' => $deptId, 'name' => $name, 'value' => $value],
            ];
            callcenter_execute_batch($statements);
            $event = $originalName === '' ? 'create' : 'update';
            callcenter_audit_write($auditIdentity, $event, 'ayarlar', 'Ayar kaydedildi', 'Ayar: ' . $name);
            $_SESSION[$flashKey] = ['message' => 'Ayar kaydedildi.', 'error' => false];
        } else {
            throw new InvalidArgumentException('Geçersiz işlem.');
        }
    } catch (Throwable $exception) {
        $message = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'İşlem tamamlanamadı.';
        callcenter_audit_write($auditIdentity, 'access_denied', 'ayarlar', 'POST işlemi reddedildi', $message);
        $_SESSION[$flashKey] = ['message' => $message, 'error' => true];
    }
    header('Location: ayarlar.php');
    exit;
}

$flash = (array) ($_SESSION[$flashKey] ?? []);
unset($_SESSION[$flashKey]);
$settings = callcenter_rows(
    'SELECT name,value,created_at,updated_at FROM callcenter_settings WHERE dept_id=:dept ORDER BY name',
    ['dept' => $deptId]
);

supervisor_page_start('Ayarlar', '../../dashboard.php?tab=callcenter&subtab=sistem');
if ($flash) {
    supervisor_notice((string) ($flash['message'] ?? ''), !empty($flash['error']));
}
?>
<div class="card operational-no-print">
    <div class="card-header">Yeni Sistem Ayarı</div>
    <div class="card-body">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <label>Ayar anahtarı
                <input name="name" maxlength="120" pattern="[A-Za-z0-9][A-Za-z0-9_.-]{0,119}" placeholder="ör. callback.enabled" required>
            </label>
            <label>Ayar değeri
                <textarea name="value" maxlength="4000" rows="3" placeholder="Değer"></textarea>
            </label>
            <button class="btn btn-primary" type="submit">Ayar Ekle</button>
        </form>
    </div>
</div>

<div class="table-container">
    <div class="table-header">Firma Sistem Ayarları · <?= count($settings) ?> kayıt</div>
    <table>
        <thead><tr><th>Anahtar</th><th>Değer</th><th>Güncelleme</th><th class="operational-no-print">İşlem</th></tr></thead>
        <tbody>
        <?php foreach ($settings as $setting): ?>
            <tr>
                <td><code><?= e((string) $setting['name']) ?></code></td>
                <td><?= nl2br(e((string) $setting['value'])) ?></td>
                <td class="audit-time"><?= e(date('d.m.Y H:i', strtotime((string) $setting['updated_at']))) ?></td>
                <td class="operational-no-print">
                    <details>
                        <summary>Düzenle</summary>
                        <form method="post" class="form-grid">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="original_name" value="<?= e((string) $setting['name']) ?>">
                            <label>Anahtar<input name="name" maxlength="120" value="<?= e((string) $setting['name']) ?>" required></label>
                            <label>Değer<textarea name="value" maxlength="4000" rows="3"><?= e((string) $setting['value']) ?></textarea></label>
                            <button class="btn btn-primary" type="submit">Kaydet</button>
                        </form>
                    </details>
                    <form method="post" onsubmit="return confirm('Bu ayar silinsin mi?')">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="name" value="<?= e((string) $setting['name']) ?>">
                        <button class="btn btn-danger" type="submit">Sil</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$settings): ?><tr><td colspan="4" class="empty">Henüz sistem ayarı tanımlanmamış.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php supervisor_page_end(); ?>
