<?php
$rows = scoped('url_triggers');
$showDept = current_dept_id() === null;
?>
<div class="toolbar">
    <p class="muted">
        URL içinde <code>{caller}</code>, <code>{extension}</code>,
        <code>{department}</code>, <code>{event}</code> ve <code>{callid}</code> alanlarını kullanabilirsiniz.
    </p>
    <button class="btn primary" type="button" id="addUrlTrigger">URL tanımla</button>
</div>

<article class="card flush">
    <table>
        <thead>
        <tr>
            <?php if ($showDept): ?><th>Firma</th><?php endif; ?>
            <th>Ad</th>
            <th>URL şablonu</th>
            <th>Tetikleme</th>
            <th>Çalışma</th>
            <th>Durum</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) ($row['dept'] ?? ''))) ?></td><?php endif; ?>
                <td><?= e($row['name'] ?? 'CRM') ?></td>
                <td><code><?= e($row['url_template'] ?? '') ?></code></td>
                <td><?= e([
                    'ring' => 'Çalarken',
                    'answer' => 'Cevaplanınca',
                    'both' => 'Çalarken + cevaplanınca',
                ][$row['trigger'] ?? 'ring'] ?? 'Çalarken') ?></td>
                <td><?= e([
                    'server' => 'Sunucu',
                    'browser' => 'WebPhone',
                    'both' => 'Sunucu + WebPhone',
                ][$row['mode'] ?? 'both'] ?? 'Sunucu + WebPhone') ?></td>
                <td><span class="pill <?= !empty($row['enabled']) ? 'ok' : 'dim' ?>">
                    <?= !empty($row['enabled']) ? 'Aktif' : 'Kapalı' ?>
                </span></td>
                <td class="right">
                    <button class="btn sm" type="button"
                        data-edit-url-trigger='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                        Düzenle
                    </button>
                    <button class="btn sm danger" type="button"
                        data-del-url-trigger="<?= e($row['id'] ?? '') ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $showDept ? 7 : 6 ?>" class="muted">URL tetikleme tanımı yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>

<article class="card">
    <h2>Örnek</h2>
    <p><code>https://crm.example.com/musteri?telefon={caller}&amp;dahili={extension}</code></p>
    <p class="muted">
        Sunucu modu URL’yi arka planda çağırır. WebPhone modu CRM sayfasını kullanıcının
        tarayıcısında açar; tarayıcı ilk kullanımda açılır pencere izni isteyebilir.
    </p>
</article>

<script>document.addEventListener('DOMContentLoaded', () => Astera.pageUrlTriggers());</script>
