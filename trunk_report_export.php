<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$filters = [
    'date_from' => trim((string) ($_GET['date_from'] ?? date('Y-m-d'))),
    'date_to' => trim((string) ($_GET['date_to'] ?? date('Y-m-d'))),
];
$report = pbx_trunk_report(current_dept_id(), $filters);
$format = strtolower(trim((string) ($_GET['format'] ?? 'excel')));
$stamp = date('Ymd-His');

function trunk_report_totals(array $rows): array
{
    $totals = [
        'total_calls' => 0,
        'billsec' => 0,
        'answered' => 0,
        'no_answer' => 0,
        'failed' => 0,
        'busy' => 0,
    ];
    foreach ($rows as $row) {
        foreach (array_keys($totals) as $field) {
            $totals[$field] += (int) ($row[$field] ?? 0);
        }
    }
    return $totals;
}

function trunk_report_values(array $row): array
{
    return [
        (string) ($row['trunk_name'] ?? '—'),
        (string) ((int) ($row['total_calls'] ?? 0)),
        number_format((int) ($row['billsec'] ?? 0) / 60, 1, ',', '.'),
        (string) ((int) ($row['answered'] ?? 0)),
        (string) ((int) ($row['no_answer'] ?? 0)),
        (string) ((int) ($row['failed'] ?? 0)),
        (string) ((int) ($row['busy'] ?? 0)),
    ];
}

if ($format === 'excel') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="trunk-raporu-' . $stamp . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Trunk Raporu', $filters['date_from'] . ' - ' . $filters['date_to']], ';');
    foreach ([
        'Giden aramalar (Outbound)' => $report['outbound'],
        'Gelen aramalar (Inbound)' => $report['inbound'],
    ] as $title => $rows) {
        fputcsv($output, [], ';');
        fputcsv($output, [$title], ';');
        fputcsv($output, ['Hat', 'Toplam çağrı', 'Dakika', 'Answered', 'No Answer', 'Failed', 'Busy'], ';');
        foreach ($rows as $row) {
            fputcsv($output, trunk_report_values($row), ';');
        }
        $totals = trunk_report_totals($rows);
        $totals['trunk_name'] = 'TOPLAM';
        fputcsv($output, trunk_report_values($totals), ';');
    }
    fclose($output);
    exit;
}

if ($format !== 'pdf') {
    http_response_code(400);
    exit('Geçersiz dışa aktarma biçimi');
}

function trunk_pdf_text(string $text): string
{
    $encoded = iconv('UTF-8', 'Windows-1254//TRANSLIT', $text);
    $encoded = $encoded === false ? $text : $encoded;
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $encoded);
}

function trunk_pdf_cell(string $value, int $length): string
{
    if (mb_strlen($value, 'UTF-8') > $length) {
        $value = mb_substr($value, 0, max(1, $length - 1), 'UTF-8') . '…';
    }
    return trunk_pdf_text($value);
}

$lines = [];
foreach ([
    '1. Giden aramalar (Outbound)' => $report['outbound'],
    '2. Gelen aramalar (Inbound)' => $report['inbound'],
] as $title => $rows) {
    $lines[] = ['type' => 'section', 'title' => $title];
    $lines[] = ['type' => 'header'];
    foreach ($rows as $row) {
        $lines[] = ['type' => 'row', 'values' => trunk_report_values($row)];
    }
    $totals = trunk_report_totals($rows);
    $totals['trunk_name'] = 'TOPLAM';
    $lines[] = ['type' => 'total', 'values' => trunk_report_values($totals)];
    $lines[] = ['type' => 'space'];
}
$pageChunks = array_chunk($lines, 32);
if (!$pageChunks) {
    $pageChunks = [[]];
}
$columns = [
    ['Hat', 36, 210],
    ['Toplam çağrı', 12, 90],
    ['Dakika', 10, 75],
    ['Answered', 10, 85],
    ['No Answer', 10, 85],
    ['Failed', 10, 70],
    ['Busy', 10, 65],
];
$objects = [];
$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
$kids = [];
foreach (array_keys($pageChunks) as $index) {
    $kids[] = (4 + ($index * 2)) . ' 0 R';
}
$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pageChunks) . ' >>';
$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica '
    . '/Encoding << /Type /Encoding /BaseEncoding /WinAnsiEncoding '
    . '/Differences [208 /Gbreve 221 /Idotaccent 222 /Scedilla 240 /gbreve 253 /dotlessi 254 /scedilla] >> >>';

foreach ($pageChunks as $pageIndex => $pageLines) {
    $pageId = 4 + ($pageIndex * 2);
    $contentId = $pageId + 1;
    $stream = "BT /F1 15 Tf 24 565 Td (" . trunk_pdf_text('Trunk Raporu') . ") Tj ET\n";
    $subtitle = ($filters['date_from'] ?: 'Tümü') . ' - ' . ($filters['date_to'] ?: 'Tümü')
        . '  |  Sayfa ' . ($pageIndex + 1) . '/' . count($pageChunks);
    $stream .= "BT /F1 8 Tf 24 548 Td (" . trunk_pdf_text($subtitle) . ") Tj ET\n";
    $y = 523;
    foreach ($pageLines as $line) {
        $type = (string) ($line['type'] ?? '');
        if ($type === 'space') {
            $y -= 8;
            continue;
        }
        if ($type === 'section') {
            $stream .= sprintf(
                "BT /F1 10 Tf 24 %.2F Td (%s) Tj ET\n",
                $y,
                trunk_pdf_text((string) ($line['title'] ?? ''))
            );
            $y -= 18;
            continue;
        }
        $values = $type === 'header'
            ? array_column($columns, 0)
            : (array) ($line['values'] ?? []);
        $x = 24;
        foreach ($columns as $columnIndex => [$label, $length, $width]) {
            $stream .= sprintf(
                "BT /F1 %s Tf %.2F %.2F Td (%s) Tj ET\n",
                in_array($type, ['header', 'total'], true) ? '8' : '7.5',
                $x,
                $y,
                trunk_pdf_cell((string) ($values[$columnIndex] ?? ''), $length)
            );
            $x += $width;
        }
        $stream .= sprintf("0.15 w 24 %.2F m 804 %.2F l S\n", $y - 4, $y - 4);
        $y -= 15;
    }
    $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] '
        . '/Resources << /Font << /F1 3 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
    $objects[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
}

ksort($objects);
$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
$offsets = [0];
foreach ($objects as $id => $object) {
    $offsets[$id] = strlen($pdf);
    $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
}
$xref = strlen($pdf);
$pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
for ($id = 1; $id <= count($objects); $id++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
}
$pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n"
    . "startxref\n" . $xref . "\n%%EOF";

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="trunk-raporu-' . $stamp . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
