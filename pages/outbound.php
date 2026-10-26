<?php
$rows = scoped('outbound');
$trunks = scoped('trunks');
$priSpans = array_map(static function (array $span): array {
    $span['name'] = '[PRI] ' . (string) ($span['name'] ?? $span['id'] ?? '');
    $span['technology'] = 'DAHDI';
    return $span;
}, array_values(array_filter(
    scoped('pri_spans'),
    static fn(array $span): bool => !empty($span['enabled'])
)));
$trunks = array_merge($trunks, $priSpans);
$showDept = current_dept_id() === null;
$routeCounts = [];
$routePositions = [];
foreach ($rows as $row) {
    $routeDept = (string) ($row['dept'] ?? '');
    $routeCounts[$routeDept] = ($routeCounts[$routeDept] ?? 0) + 1;
    $routePositions[(string) ($row['id'] ?? '')] = $routeCounts[$routeDept];
}
?>
<div class="toolbar">
    <p class="muted">Kurallar yukarıdan aşağıya öncelik sırasıyla uygulanır. Oklarla satırları taşıyabilirsiniz. Astera aboneleri dış numarayı <strong>0</strong> ile tuşlar: <code>0532…</code> → <code>90532…</code>.</p>
    <button class="btn primary" type="button" id="addOut">Kural ekle</button>
</div>
<article class="card flush">
    <table>
        <thead>
            <tr>
                <?php if ($showDept): ?><th>Firma</th><?php endif; ?>
                <th>Öncelik</th>
                <th>Ad</th>
                <th>Arama kalıpları</th>
                <th>Trunk sırası</th>
                <th>Dağıtım</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php
            $routeDept = (string) ($row['dept'] ?? '');
            $routePosition = $routePositions[(string) ($row['id'] ?? '')] ?? 1;
            $routePatterns = is_array($row['patterns'] ?? null) ? $row['patterns'] : [];
            if (!$routePatterns) {
                $routePatterns = [[
                    'match' => (string) ($row['pattern'] ?? ''),
                    'prefix' => '',
                    'prepend' => (string) ($row['prefix'] ?? ''),
                ]];
            }
            $routeTrunks = array_values(array_filter(array_map(
                'strval',
                is_array($row['trunks'] ?? null) ? $row['trunks'] : [(string) ($row['trunk'] ?? '')]
            )));
            ?>
            <tr>
                <?php if ($showDept): ?><td><?= e(dept_name((string) ($row['dept'] ?? ''))) ?></td><?php endif; ?>
                <td>
                    <div class="row-actions">
                        <strong><?= $routePosition ?></strong>
                        <button class="btn sm" type="button" title="Yukarı taşı"
                                data-move-out="<?= e($row['id']) ?>" data-direction="up"
                                <?= $routePosition === 1 ? 'disabled' : '' ?>>↑</button>
                        <button class="btn sm" type="button" title="Aşağı taşı"
                                data-move-out="<?= e($row['id']) ?>" data-direction="down"
                                <?= $routePosition === ($routeCounts[$routeDept] ?? 1) ? 'disabled' : '' ?>>↓</button>
                    </div>
                </td>
                <td><?= e($row['name'] ?? '') ?></td>
                <td>
                    <?php foreach ($routePatterns as $patternRow): ?>
                        <div><code><?= e((string) ($patternRow['match'] ?? $patternRow['pattern'] ?? '')) ?></code>
                            <?php if ((string) ($patternRow['prefix'] ?? '') !== ''): ?>
                                <span class="muted">önek <?= e((string) $patternRow['prefix']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </td>
                <td><code><?= e(implode(' → ', $routeTrunks)) ?></code></td>
                <td><?= !empty($row['equal_distribution']) ? 'Eşit' : 'Sıralı' ?></td>
                <td class="right">
                    <button class="btn sm" type="button" data-edit-out='<?= e(json_encode($row, JSON_UNESCAPED_UNICODE)) ?>'>Düzenle</button>
                    <button class="btn sm danger" type="button" data-del-out="<?= e($row['id']) ?>">Sil</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $showDept ? 7 : 6 ?>" class="muted">Giden kural yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</article>
<script>
window.ASTERA = window.ASTERA || {};
window.ASTERA.trunks = <?= json_encode($trunks, JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('DOMContentLoaded', () => Astera.pageOutbound());
</script>
