<?php
$cli = pbx_cli('core show version');
$uptime = pbx_cli('core show uptime');
$transports = pbx_cli('pjsip show transports');
?>
<div class="grid-2">
    <article class="card">
        <h2>Santral</h2>
        <dl class="kv">
            <dt>IP</dt><dd><?= e(PBX_HOST) ?></dd>
            <dt>Panel</dt><dd>admin / yerel oturum</dd>
            <dt>AMI</dt><dd><?= e(AMI_USER) ?> · <?= e((string) AMI_PORT) ?></dd>
        </dl>
        <div class="toolbar left">
            <button class="btn primary" type="button" data-action="apply">Ayarları yaz ve yükle</button>
            <button class="btn" type="button" data-action="reload">Asterisk core reload</button>
        </div>
    </article>
    <article class="card">
        <h2>Özellik kodları</h2>
        <dl class="kv">
            <dt>*43</dt><dd>Echo</dd>
            <dt>*72 / *73</dt><dd>Yönlendirme</dd>
            <dt>*78 / *79</dt><dd>DND</dd>
            <dt>700</dt><dd>Park</dd>
            <dt>*411</dt><dd>Rehber</dd>
        </dl>
        <p class="muted">Aboneler kendi firmasının <code>from-&lt;kod&gt;</code> bağlamındadır; başka firmayı include etmez.</p>
    </article>
</div>
<article class="card">
    <h2>CLI</h2>
    <pre class="log"><?= e(trim($cli . "\n" . $uptime . "\n" . $transports)) ?></pre>
</article>
