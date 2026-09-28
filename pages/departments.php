<?php
$rows = departments();
$extensions = store_read('extensions');
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
                <th>Abone</th>
                <th>Abone limiti</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row):
            $count = count(array_filter($extensions, static fn($e) => ($e['dept'] ?? '') === $row['id']));
            $limit = max(0, (int) ($row['extension_limit'] ?? 0));
            ?>
            <tr>
                <td><code><?= e($row['id']) ?></code></td>
                <td><?= e($row['name'] ?? '') ?></td>
                <td><?= !empty($row['record']) ? 'Açık' : 'Kapalı' ?></td>
                <td><?= e($row['panel_user'] ?: '—') ?></td>
                <td><?= $count ?></td>
                <td>
                    <?php if ($limit > 0): ?>
                        <span class="pill <?= $count >= $limit ? 'bad' : 'ok' ?>"><?= $count ?> / <?= $limit ?></span>
                    <?php else: ?>
                        <span class="pill dim">Sınırsız</span>
                    <?php endif; ?>
                </td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-dept='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
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
