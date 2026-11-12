<?php
require_once dirname(__DIR__) . '/includes/callcenter.php';

$rows = departments();
$extensions = store_read('extensions');
$supervisorsByDepartment = [];
foreach (callcenter_rows(
    "SELECT dept_id, username, password_hash FROM callcenter_supervisor_users "
    . "WHERE username = 'admin' AND role = 'admin' ORDER BY supervisor_id"
) as $supervisorAccount) {
    $departmentId = (string) $supervisorAccount['dept_id'];
    $supervisorsByDepartment[$departmentId] ??= [
        'username' => (string) $supervisorAccount['username'],
        'default_password' => password_verify('password', (string) $supervisorAccount['password_hash']),
    ];
}
?>
<div class="toolbar">
    <p class="muted">Her kayıt ayrı bir santral gibidir: kendi aboneleri, dış hattı, kuyruğu ve kayıtları. Birbirlerini arayamazlar.</p>
    <button class="btn primary" type="button" id="addDept">Firma ekle</button>
</div>
<article class="card flush">
    <table>
        <thead>
            <tr>
                <th>Kod</th>
                <th>Ad</th>
                <th>Kayıt</th>
                <th>Panel kullanıcısı</th>
                <th>Supervisor yöneticisi</th>
                <th>Abone</th>
                <th>Abone limiti</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row):
            $count = count(array_filter($extensions, static fn($e) => ($e['dept'] ?? '') === $row['id']));
            $limit = max(0, (int) ($row['extension_limit'] ?? 0));
            $supervisorAccount = $supervisorsByDepartment[(string) $row['id']] ?? null;
            $editRow = $row;
            unset($editRow['panel_pass']);
            $editRow['panel_pass_set'] = !empty($row['panel_pass']);
            $editRow['supervisor_username'] = (string) ($supervisorAccount['username'] ?? 'admin');
            $editRow['supervisor_password_default'] = !empty($supervisorAccount['default_password']);
            ?>
            <tr>
                <td><code><?= e($row['id']) ?></code></td>
                <td><?= e($row['name'] ?? '') ?></td>
                <td><?= !empty($row['record']) ? 'Açık' : 'Kapalı' ?></td>
                <td><?= e($row['panel_user'] ?: '—') ?></td>
                <td>
                    <code><?= e((string) ($supervisorAccount['username'] ?? 'admin')) ?></code>
                    <?php if (!empty($supervisorAccount['default_password'])): ?>
                        <span class="pill dim">password</span>
                    <?php elseif ($supervisorAccount): ?>
                        <span class="pill ok">Özel parola</span>
                    <?php else: ?>
                        <span class="pill bad">Hesap yok</span>
                    <?php endif; ?>
                </td>
                <td><?= $count ?></td>
                <td>
                    <?php if ($limit > 0): ?>
                        <span class="pill <?= $count >= $limit ? 'bad' : 'ok' ?>"><?= $count ?> / <?= $limit ?></span>
                    <?php else: ?>
                        <span class="pill dim">Sınırsız</span>
                    <?php endif; ?>
                </td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-dept='<?= e(json_encode($editRow, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <?php if (($row['id'] ?? '') !== 'genel'): ?>
                        <button class="btn sm danger" type="button" data-del-dept="<?= e($row['id']) ?>">Sil</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</article>
<script>
document.addEventListener('DOMContentLoaded', () => Astera.pageDepartments());
</script>
