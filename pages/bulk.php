<?php
$bulkDept = current_dept_id();
$bulkCount = $bulkDept !== null ? department_extension_count($bulkDept) : 0;
$bulkLimit = $bulkDept !== null ? department_extension_limit($bulkDept) : 0;
$bulkDefaultPassword = 'Ast!' . bin2hex(random_bytes(6));
?>
<div class="toolbar">
    <p class="muted">
        Seçili firmaya ardışık PJSIP, DAHDI veya Custom dahili üretir. En fazla 50 numara.
        <?php if ($bulkDept !== null): ?>
            Mevcut: <strong><?= $bulkCount ?></strong> ·
            Limit: <strong><?= $bulkLimit > 0 ? $bulkLimit : 'Sınırsız' ?></strong>
        <?php endif; ?>
    </p>
</div>
<article class="card">
    <form id="bulkForm" class="form-grid">
        <label>Bağlantı türü
            <select name="technology">
                <option value="pjsip">PJSIP / SIP</option>
                <option value="dahdi">DAHDI / PRI</option>
                <option value="custom">Custom Trunk</option>
            </select>
        </label>
        <label>Başlangıç<input name="start" type="number" value="130" required></label>
        <label>Bitiş<input name="end" type="number" value="139" required></label>
        <label data-bulk-pjsip>Ortak SIP parolası
            <input name="password" minlength="8" required value="<?= e($bulkDefaultPassword) ?>"
                   autocomplete="new-password">
            <small>Otomatik oluşturuldu. Girilen parola tüm yeni SIP abonelerinde kullanılır.</small>
        </label>
        <label data-bulk-dahdi hidden>DAHDI grup bilgisi
            <input name="dahdi_group" value="g1" placeholder="Örn. g2">
            <small>Kart tanımlı olması gerekmez. <code>2</code> veya <code>g2</code> yazabilirsiniz.</small>
        </label>
        <label data-bulk-custom hidden>Custom Trunk şablonu
            <input name="custom_dial_template" value="ABC/g2/xxx"
                   placeholder="ABC/g2/xxx">
            <small><code>xxx</code> veya <code>{exten}</code>, oluşturulan dahili numarasıyla değiştirilir.</small>
        </label>
        <label><input type="checkbox" name="outbound_enabled" checked> Dış arama yetkisi</label>
        <label><input type="checkbox" name="vm_enabled"> Mesaj Kutusu - Açık</label>
        <div class="modal-actions">
            <button class="btn primary" type="submit">Aboneleri oluştur</button>
        </div>
    </form>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageBulk());</script>
