<?php
$bulkDept = current_dept_id();
$bulkCount = $bulkDept !== null ? department_extension_count($bulkDept) : 0;
$bulkLimit = $bulkDept !== null ? department_extension_limit($bulkDept) : 0;
?>
<div class="toolbar">
    <p class="muted">
        Seçili firmaya ardışık dahili üretir. En fazla 50 numara.
        <?php if ($bulkDept !== null): ?>
            Mevcut: <strong><?= $bulkCount ?></strong> ·
            Limit: <strong><?= $bulkLimit > 0 ? $bulkLimit : 'Sınırsız' ?></strong>
        <?php endif; ?>
    </p>
</div>
<article class="card">
    <form id="bulkForm" class="form-grid">
        <label>Başlangıç<input name="start" type="number" value="130" required></label>
        <label>Bitiş<input name="end" type="number" value="139" required></label>
        <label>Ortak parola<input name="password" required placeholder="Firma123!"></label>
        <label><input type="checkbox" name="vm_enabled"> Gelen kutusu açık</label>
        <div class="modal-actions">
            <button class="btn primary" type="submit">Aboneleri oluştur</button>
        </div>
    </form>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageBulk());</script>
