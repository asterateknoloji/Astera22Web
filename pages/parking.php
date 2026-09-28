<?php
$rows = scoped('parking');
$showDept = current_dept_id() === null;
$row = $rows[0] ?? parking_for((string) (current_dept_id() ?: 'genel'));
?>
<div class="toolbar">
    <p class="muted">Çağrıyı park etmek için <code><?= e($row['parkext'] ?? '700') ?></code> çevirin. Slotlar <code><?= e(($row['start'] ?? '701') . '-' . ($row['end'] ?? '709')) ?></code>. Geri almak için slot numarasını çevirin.</p>
    <button class="btn primary" type="button" id="savePark">Park ayarını kaydet</button>
</div>
<article class="card">
    <form id="parkForm" class="form-grid">
        <?php if ($showDept): ?>
            <p class="muted">Üstten bir firma seçin.</p>
        <?php endif; ?>
        <div class="grid-2">
            <label>Park tuşu<input name="parkext" value="<?= e($row['parkext'] ?? '700') ?>"></label>
            <label>Süre (sn)<input name="time" type="number" min="15" value="<?= e((string) ($row['time'] ?? 45)) ?>"></label>
            <label>İlk slot<input name="start" value="<?= e($row['start'] ?? '701') ?>"></label>
            <label>Son slot<input name="end" value="<?= e($row['end'] ?? '709') ?>"></label>
        </div>
    </form>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageParking());</script>
