<?php
$rows = scoped('extensions');
$showDept = current_dept_id() === null;
$departmentIndex = [];
foreach (departments() as $department) {
    $departmentIndex[(string) ($department['id'] ?? '')] = $department;
}
?>
<div class="toolbar">
    <p class="muted">Dahili numara firma içinde kullanılır. SIP kullanıcı adı farklı firmada aynı dahili varsa otomatik öneklenir. Mevcut 1001/1002: <code>1001</code> / <code>1002</code>.</p>
    <button class="btn primary" type="button" id="addExt">Abone ekle</button>
</div>
<article class="card">
    <div class="toolbar">
        <label>Dahili veya isim ara
            <input id="extSearch" autocomplete="off" placeholder="Örn. 121 veya Tuncay">
        </label>
        <div class="toolbar">
            <span class="muted" id="extSelectedCount">0 abone seçildi</span>
            <button class="btn danger" type="button" id="deleteSelectedExt" disabled>Seçilenleri sil</button>
        </div>
    </div>
</article>
<article class="card flush">
    <table>
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAllExt" aria-label="Görünen abonelerin tümünü seç"></th>
                <?php if ($showDept): ?><th>Firma</th><?php endif; ?>
                <th>Dahili</th>
                <th>Ad</th>
                <th>SIP kullanıcı</th>
                <th>Auth kullanıcı</th>
                <th>WebRTC</th>
                <th>Kayıt</th>
                <th>Durum</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr data-ext-row data-search="<?= e(strtolower(trim(
                (string) ($row['exten'] ?? '') . ' '
                . (string) ($row['sipuser'] ?? '') . ' '
                . (string) ($row['name'] ?? '')
            ))) ?>">
                <td><input type="checkbox" class="ext-select" value="<?= e($row['id'] ?? ext_id((string) $row['dept'], (string) $row['exten'])) ?>" aria-label="<?= e((string) $row['exten']) ?> abonesini seç"></td>
                <?php if ($showDept): ?><td><?= e(dept_name((string) ($row['dept'] ?? ''))) ?></td><?php endif; ?>
                <td><code><?= e($row['exten']) ?></code></td>
                <td><?= e($row['name'] ?? '') ?></td>
                <td><code><?= e($row['sipuser'] ?? $row['exten']) ?></code></td>
                <td><code><?= e($row['authuser'] ?? $row['sipuser'] ?? $row['exten']) ?></code></td>
                <td><?php if (!empty($row['webrtc'])): ?>
                    <a class="pill ok" href="index.php?p=webrtc&amp;ext=<?= e($row['exten']) ?>">WebRTC</a>
                <?php else: ?>
                    <span class="pill dim">kapalı</span>
                <?php endif; ?></td>
                <?php
                $extensionDept = $departmentIndex[(string) ($row['dept'] ?? '')] ?? [];
                $recordEnabled = array_key_exists('record', $row)
                    ? !empty($row['record'])
                    : !empty($extensionDept['record']);
                ?>
                <td><?= $recordEnabled ? strtoupper(e((string) ($row['record_format'] ?? 'wav'))) : 'Kapalı' ?></td>
                <td><span class="pill dim ext-state" data-sip="<?= e($row['sipuser'] ?? $row['exten']) ?>">—</span></td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-ext='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-ext="<?= e($row['id'] ?? ext_id((string) $row['dept'], (string) $row['exten'])) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $showDept ? 10 : 9 ?>" class="muted">Bu kapsamda abone yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
<script>
document.addEventListener('DOMContentLoaded', () => Astera.pageExtensions());
</script>
