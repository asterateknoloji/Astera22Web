<?php
$systemContexts = pbx_context_inventory();
$customContexts = custom_context_rows(current_dept_id());
foreach ($customContexts as &$context) {
    $context['steps_text'] = custom_context_steps_text((array) ($context['steps'] ?? []));
}
unset($context);
?>
<div class="toolbar">
    <p class="muted">
        Sistem context’leri salt okunurdur. Panelden oluşturulan özel context’ler
        PostgreSQL’de saklanır ve santrale uygulanınca dialplan’a eklenir.
    </p>
    <button class="btn primary" type="button" id="addContext">Özel context ekle</button>
</div>

<article class="card flush">
    <h2>Yönetilen özel context’ler</h2>
    <table>
        <thead><tr><th>Context</th><th>Firma</th><th>Açıklama</th><th>Adım</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($customContexts as $context): ?>
            <tr>
                <td><code><?= e($context['context_name']) ?></code></td>
                <td><?= e(dept_name((string) $context['dept_id'])) ?></td>
                <td><?= e($context['description']) ?></td>
                <td><?= count((array) $context['steps']) ?></td>
                <td class="right">
                    <button class="btn sm" type="button"
                        data-view-context="<?= e($context['context_name']) ?>">Görüntüle</button>
                    <button class="btn sm" type="button"
                        data-edit-context='<?= e(json_encode($context, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button"
                        data-delete-context="<?= e($context['context_id']) ?>"
                        data-context-dept="<?= e($context['dept_id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$customContexts): ?>
            <tr><td colspan="5" class="muted">Yönetilen özel context yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>

<article class="card flush">
    <h2>Asterisk context’leri <span class="pill dim"><?= count($systemContexts) ?></span></h2>
    <table>
        <thead><tr><th>Context</th><th>Kaynak</th><th>Tür</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($systemContexts as $context): ?>
            <tr>
                <td><code><?= e($context['name']) ?></code></td>
                <td><?= e($context['registrar']) ?></td>
                <td>
                    <span class="pill <?= $context['managed'] ? 'ok' : 'dim' ?>">
                        <?= $context['managed'] ? 'Yönetilen özel' : 'Sistem · salt okunur' ?>
                    </span>
                </td>
                <td class="right">
                    <button class="btn sm" type="button"
                        data-view-context="<?= e($context['name']) ?>">Görüntüle</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$systemContexts): ?>
            <tr><td colspan="4" class="muted">Asterisk context listesi okunamadı.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>

<script>document.addEventListener('DOMContentLoaded', () => Astera.pageContexts());</script>
