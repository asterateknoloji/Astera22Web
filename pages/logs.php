<article class="card log-browser">
    <div class="log-browser-heading">
        <div>
            <span class="security-kicker">ASTERISK LOGLARI</span>
            <h2>/var/log/asterisk/</h2>
            <p class="muted">Bir dosya seçin, son satırları görüntüleyin veya dosya içinde metin arayın.</p>
        </div>
        <span class="pill dim" id="logFileMeta">Dosyalar yükleniyor…</span>
    </div>

    <form id="logFilterForm" class="log-filters">
        <label>Log dosyası
            <select name="file" id="logFile" required></select>
        </label>
        <label>Dosya içinde ara
            <input name="query" id="logQuery" maxlength="200"
                placeholder="Numara, kanal, hata veya IP…">
        </label>
        <label>Gösterilecek satır
            <select name="lines" id="logLines">
                <option value="100">Son 100</option>
                <option value="200" selected>Son 200</option>
                <option value="500">Son 500</option>
                <option value="1000">Son 1.000</option>
                <option value="2000">Son 2.000</option>
            </select>
        </label>
        <div class="log-filter-actions">
            <button class="btn primary" type="submit">Göster / ara</button>
            <button class="btn" type="button" id="reloadLog">Yenile</button>
            <button class="btn" type="button" id="exportLog" disabled>Görünen sonucu dışa aktar</button>
        </div>
    </form>

    <div class="log-result-heading">
        <strong id="logResultTitle">Log yükleniyor…</strong>
        <span class="muted" id="logResultMeta"></span>
    </div>
    <pre class="log log-browser-output" id="logBox">yükleniyor…</pre>
</article>
<script>document.addEventListener('DOMContentLoaded', () => Astera.pageLogs());</script>
