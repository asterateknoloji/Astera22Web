<?php
if (!is_super()) {
    http_response_code(403);
    exit('Yetkisiz');
}
$amiUsers = ami_users_for_page();
$permissions = ami_permission_names();
?>
<div class="toolbar">
    <div>
        <p class="muted">Asterisk Manager Interface kullanıcılarını, erişebilecekleri ağları ve Read/Write yetkilerini yönetin.</p>
        <p class="muted">AMI portu: <code><?= e((string) AMI_PORT) ?></code>. Genel internet yerine yalnız gerekli yerel ağlara izin verin.</p>
    </div>
    <button class="btn primary" type="button" id="addAmiUser">AMI kullanıcısı ekle</button>
</div>

<article class="card flush">
    <table>
        <thead>
        <tr>
            <th>Kullanıcı</th>
            <th>Kaynak</th>
            <th>İzinli ağlar</th>
            <th>Read</th>
            <th>Write</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($amiUsers as $user):
            $permits = array_values(array_filter(
                (array) ($user['acl'] ?? []),
                static fn(array $rule): bool => ($rule['action'] ?? '') === 'permit'
            ));
        ?>
            <tr>
                <td><strong><?= e((string) $user['manager_name']) ?></strong></td>
                <td><span class="pill <?= !empty($user['managed']) ? 'ok' : 'dim' ?>">
                    <?= !empty($user['managed']) ? 'Panel' : 'manager.conf' ?>
                </span></td>
                <td><?= $permits
                    ? e(implode(', ', array_column($permits, 'network')))
                    : '<span class="muted">Dosyadan yönetiliyor</span>' ?></td>
                <td><?= e(implode(', ', (array) ($user['read_permissions'] ?? [])) ?: '—') ?></td>
                <td><?= e(implode(', ', (array) ($user['write_permissions'] ?? [])) ?: '—') ?></td>
                <td class="right">
                    <?php if (!empty($user['managed'])): ?>
                        <button class="btn sm" type="button"
                            data-edit-ami='<?= e(json_encode($user, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>Düzenle</button>
                        <button class="btn sm danger" type="button"
                            data-delete-ami="<?= e((string) $user['manager_name']) ?>">Sil</button>
                    <?php elseif (!empty($user['protected'])): ?>
                        <span class="pill dim">Sistem kullanıcısı</span>
                    <?php else: ?>
                        <span class="muted">Salt okunur</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$amiUsers): ?>
            <tr><td colspan="6" class="muted">AMI kullanıcısı bulunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>

<script>
window.ASTERA = window.ASTERA || {};
window.ASTERA.amiPermissions = <?= json_encode($permissions, JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('DOMContentLoaded', () => Astera.pageAmi());
</script>
