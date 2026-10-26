<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
supervisor_require_permission('sistem_ayarlar');

$flashKey = 'callcenter_queue_sla_flash';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auditIdentity = $supervisor;
    $auditIdentity['dept_id'] = $deptId;
    try {
        if (!csrf_ok()) {
            throw new RuntimeException('İstek doğrulanamadı. Sayfayı yenileyip tekrar deneyin.');
        }
        $queueId = trim((string) ($_POST['queue_id'] ?? ''));
        if ($queueId === '' || !in_array($queueId, $queueIds, true)) {
            throw new InvalidArgumentException('Yalnız yetkili olduğunuz mevcut bir kuyruk güncellenebilir.');
        }
        $rawSla = trim((string) ($_POST['sla_seconds'] ?? ''));
        if (!preg_match('/^\d+$/', $rawSla)) {
            throw new InvalidArgumentException('SLA süresi tam sayı olmalıdır.');
        }
        $slaSeconds = (int) $rawSla;
        if ($slaSeconds < 1 || $slaSeconds > 86400) {
            throw new InvalidArgumentException('SLA süresi 1 ile 86400 saniye arasında olmalıdır.');
        }
        callcenter_execute(
            'INSERT INTO callcenter_queue_settings (dept_id,queue_id,sla_seconds) VALUES (:dept,:queue,:sla) '
            . 'ON CONFLICT (dept_id,queue_id) DO UPDATE SET sla_seconds=EXCLUDED.sla_seconds,updated_at=now()',
            ['dept' => $deptId, 'queue' => $queueId, 'sla' => $slaSeconds]
        );
        callcenter_audit_write(
            $auditIdentity,
            'update',
            'kuyruksla',
            'Kuyruk SLA güncellendi',
            'Kuyruk: ' . $queueId . ', SLA: ' . $slaSeconds . ' saniye'
        );
        $_SESSION[$flashKey] = ['message' => 'Kuyruk SLA süresi güncellendi.', 'error' => false];
    } catch (Throwable $exception) {
        $message = $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'İşlem tamamlanamadı.';
        callcenter_audit_write($auditIdentity, 'access_denied', 'kuyruksla', 'POST işlemi reddedildi', $message);
        $_SESSION[$flashKey] = ['message' => $message, 'error' => true];
    }
    header('Location: kuyruksla.php');
    exit;
}

$flash = (array) ($_SESSION[$flashKey] ?? []);
unset($_SESSION[$flashKey]);
$slaRows = callcenter_rows(
    'SELECT queue_id,sla_seconds,updated_at FROM callcenter_queue_settings WHERE dept_id=:dept ORDER BY queue_id',
    ['dept' => $deptId]
);
$slaByQueue = [];
foreach ($slaRows as $slaRow) {
    $slaByQueue[(string) $slaRow['queue_id']] = $slaRow;
}

supervisor_page_start('Kuyruk SLA', '../../dashboard.php?tab=callcenter&subtab=sistem');
if ($flash) {
    supervisor_notice((string) ($flash['message'] ?? ''), !empty($flash['error']));
}
?>
<div class="alert operational-no-print">SLA değeri, çağrının kuyrukta hedeflenen azami bekleme süresidir. Henüz kaydedilmemiş kuyruklarda eski sistemle uyumlu olarak varsayılan 60 saniye gösterilir.</div>
<div class="operational-report-actions operational-no-print">
    <span>Excel ve yazdırma yalnız ekrandaki yetkili kuyrukları içerir.</span>
    <button class="btn btn-success" id="sla-export" type="button">Excel (.xls)</button>
    <button class="btn btn-outline" id="sla-print" type="button">Yazdır</button>
</div>
<div class="table-container operational-print-area">
    <div class="table-header">Kuyruk SLA Tanımları · <?= count($queues) ?> kuyruk</div>
    <table id="sla-table">
        <thead><tr><th>Kuyruk</th><th>Ad</th><th>SLA (saniye)</th><th>Durum</th><th class="operational-no-print">İşlem</th></tr></thead>
        <tbody>
        <?php foreach ($queues as $queue): ?>
            <?php
            $queueId = (string) ($queue['id'] ?? '');
            $saved = $slaByQueue[$queueId] ?? null;
            $slaSeconds = (int) ($saved['sla_seconds'] ?? 60);
            ?>
            <tr>
                <td><span class="badge"><?= e($queueId) ?></span></td>
                <td><?= e((string) ($queue['name'] ?? $queueId)) ?></td>
                <td data-sort-value="<?= $slaSeconds ?>"><strong><?= $slaSeconds ?></strong></td>
                <td><?= $saved ? '<span class="badge badge-success">Kaydedildi</span>' : '<span class="badge">Varsayılan</span>' ?></td>
                <td class="operational-no-print">
                    <form method="post" class="form-grid">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="queue_id" value="<?= e($queueId) ?>">
                        <label>Saniye<input type="number" name="sla_seconds" min="1" max="86400" value="<?= $slaSeconds ?>" required></label>
                        <button class="btn btn-primary" type="submit">Kaydet</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$queues): ?><tr><td colspan="5" class="empty">Yetkili olduğunuz kuyruk bulunamadı.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<script>
(()=>{
    const table=document.getElementById('sla-table');
    document.getElementById('sla-print')?.addEventListener('click',()=>window.print());
    document.getElementById('sla-export')?.addEventListener('click',()=>{
        const escapeHtml=value=>{const node=document.createElement('div');node.textContent=value;return node.innerHTML;};
        const excelSafe=value=>/^[=+\-@]/.test(value.trim())?"'"+value:value;
        const headers=[...table.tHead.rows[0].cells].slice(0,4).map(cell=>`<th>${escapeHtml(cell.textContent.trim())}</th>`).join('');
        const rows=[...table.tBodies[0].rows].filter(row=>row.cells.length>1).map(row=>'<tr>'+[...row.cells].slice(0,4).map(cell=>`<td>${escapeHtml(excelSafe(cell.textContent.trim()))}</td>`).join('')+'</tr>').join('');
        const html=`<!doctype html><html><head><meta charset="utf-8"><style>table{border-collapse:collapse}th,td{border:1px solid #999;padding:6px}th{background:#ddd}</style></head><body><h2>Kuyruk SLA Tanımları</h2><table><thead><tr>${headers}</tr></thead><tbody>${rows}</tbody></table></body></html>`;
        const url=URL.createObjectURL(new Blob(['\ufeff',html],{type:'application/vnd.ms-excel;charset=utf-8'}));
        const link=document.createElement('a');link.href=url;link.download=<?= json_encode('kuyruk_sla_' . date('Y-m-d') . '.xls', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        document.body.appendChild(link);link.click();link.remove();URL.revokeObjectURL(url);
    });
})();
</script>
<?php supervisor_page_end(); ?>
