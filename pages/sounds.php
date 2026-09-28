<?php
$sounds = panel_sounds(current_dept_id());
?>
<div class="toolbar">
    <p class="muted">IVR, duyuru ve bekleme müzikleri firma bazında ayrılır. Yüklenen anonslar <code>custom/firma/ses</code> olarak saklanır.</p>
</div>
<div class="grid-2">
    <article class="card">
        <h2>Ses yükle (IVR)</h2>
        <form id="soundForm">
            <?php if (current_dept_id() === null): ?>
                <label>Firma<select name="dept" required>
                    <?php foreach (departments() as $dept): ?><option value="<?= e($dept['id']) ?>"><?= e($dept['name']) ?></option><?php endforeach; ?>
                </select></label>
            <?php endif; ?>
            <label>Wav / gsm / ulaw<input type="file" name="file" accept=".wav,.gsm,.ulaw" required></label>
            <input type="hidden" name="kind" value="sound">
            <div class="modal-actions">
                <button class="btn primary">Yükle</button>
            </div>
        </form>
        <p class="muted tiny">Kayıtlı adlar: <?= e(implode(', ', $sounds)) ?></p>
    </article>
    <article class="card">
        <h2>Bekleme müziği (MOH)</h2>
        <form id="mohForm">
            <?php if (current_dept_id() === null): ?>
                <label>Firma<select name="dept" required>
                    <?php foreach (departments() as $dept): ?><option value="<?= e($dept['id']) ?>"><?= e($dept['name']) ?></option><?php endforeach; ?>
                </select></label>
            <?php endif; ?>
            <label>Wav<input type="file" name="file" accept=".wav,.gsm,.ulaw" required></label>
            <input type="hidden" name="kind" value="moh">
            <div class="modal-actions">
                <button class="btn primary">Yükle</button>
            </div>
        </form>
        <p class="muted tiny">Kuyruk ve park bu firmanın MOH klasörünü kullanır.</p>
    </article>
</div>
<article class="card flush">
    <h2>Santraldeki dosyalar</h2>
    <div class="toolbar left"><button class="btn sm" type="button" id="reloadSounds">Yenile</button></div>
    <div class="sound-list" id="soundBox">yükleniyor…</div>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageSounds());</script>
