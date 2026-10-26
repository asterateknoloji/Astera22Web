<?php
$rows = scoped('pri_spans');
$showDept = is_super();
?>
<div class="toolbar">
    <div>
        <p class="muted">Fiziksel E1/PRI spanlarını firmalara atayın. Değişiklikler uygulanırken DAHDI yeniden yapılandırılır; aktif PRI çağrıları kesilebilir.</p>
        <p class="tiny muted">Kart sürücüsü ve <code>chan_dahdi.so</code> kurulu olmalıdır. Operatör değerleri doğrulanmadan spanı etkinleştirmeyin.</p>
    </div>
    <div class="toolbar-actions">
        <button class="btn" type="button" id="priRefresh">Donanımı yenile</button>
        <button class="btn primary" type="button" id="addPriSpan">PRI spanı ekle</button>
    </div>
</div>

<section class="grid-2">
    <article class="card">
        <h3>DAHDI donanımı</h3>
        <pre class="log" id="priHardware">Sorgulanıyor…</pre>
    </article>
    <article class="card">
        <h3>Span / alarm durumu</h3>
        <pre class="log" id="priStatus">Sorgulanıyor…</pre>
    </article>
</section>

<article class="card flush">
    <table>
        <thead>
            <tr>
                <th>Span</th>
                <?php if ($showDept): ?><th>Firma</th><?php endif; ?>
                <th>Ad</th>
                <th>Rol / switch</th>
                <th>Kanallar</th>
                <th>Hat kodlaması</th>
                <th>Durum</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><code><?= (int) ($row['span'] ?? 0) ?></code> / grup <?= (int) ($row['group'] ?? 0) ?></td>
                <?php if ($showDept): ?><td><?= e(dept_name((string) ($row['dept'] ?? ''))) ?></td><?php endif; ?>
                <td><?= e((string) ($row['name'] ?? $row['id'] ?? '')) ?></td>
                <td><?= e((string) ($row['signalling'] ?? 'pri_cpe')) ?> / <?= e((string) ($row['switchtype'] ?? 'euroisdn')) ?></td>
                <td>
                    <code><?= e((string) ($row['bchannels'] ?? '')) ?></code><br>
                    <small>D: <?= (int) ($row['dchannel'] ?? 0) ?> · Gain RX/TX:
                        <?= e(number_format((float) ($row['rxgain'] ?? 0), 1, '.', '')) ?> /
                        <?= e(number_format((float) ($row['txgain'] ?? 0), 1, '.', '')) ?> dB
                    </small>
                </td>
                <td><?= e((string) ($row['framing'] ?? 'ccs')) ?> / <?= e((string) ($row['coding'] ?? 'hdb3')) ?><?= !empty($row['crc4']) ? ' / CRC4' : '' ?></td>
                <td><span class="pill <?= !empty($row['enabled']) ? 'ok' : 'dim' ?>"><?= !empty($row['enabled']) ? 'Etkin' : 'Kapalı' ?></span></td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-pri='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-pri="<?= e((string) ($row['id'] ?? '')) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $showDept ? 8 : 7 ?>" class="muted">Henüz PRI spanı tanımlanmadı.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>

<script>
window.ASTERA = window.ASTERA || {};
window.ASTERA.priSpans = <?= json_encode($rows, JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('DOMContentLoaded', () => Astera.pagePri());
</script>
