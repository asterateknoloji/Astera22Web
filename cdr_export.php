<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$filters = [
    'date_from' => trim((string) ($_GET['date_from'] ?? date('Y-m-d'))),
    'date_to' => trim((string) ($_GET['date_to'] ?? date('Y-m-d'))),
    'src' => trim((string) ($_GET['src'] ?? '')),
    'dst' => trim((string) ($_GET['dst'] ?? '')),
    'internal' => trim((string) ($_GET['internal'] ?? '')),
    'disposition' => trim((string) ($_GET['disposition'] ?? '')),
    'uniqueid' => trim((string) ($_GET['uniqueid'] ?? '')),
];
$rows = pbx_cdr(5000, current_dept_id(), $filters);
$format = strtolower(trim((string) ($_GET['format'] ?? 'excel')));
$stamp = date('Ymd-His');

function cdr_export_description(array $row): string
{
    $parts = explode('|', (string) ($row['userfield'] ?? ''));
    if (($parts[1] ?? '') === 'BLACKLIST') {
        return 'Kara liste nedeniyle reddedildi: ' . (string) ($parts[2] ?? $row['src'] ?? '');
    }
    if (($parts[1] ?? '') === 'FALLBACK_EXTERNAL') {
        $reason = match (strtoupper((string) ($parts[4] ?? ''))) {
            'BUSY' => 'meşguldü',
            'CHANUNAVAIL', 'CONGESTION' => 'ulaşılamadı',
            default => 'cevaplamadı',
        };
        return trim((string) ($parts[2] ?? '') . ' ' . $reason)
            . '; dış numaraya yönlendirildi: ' . (string) ($parts[3] ?? '');
    }
    return '';
}

function cdr_export_internal(array $row): string
{
    if (!preg_match('#^PJSIP/(.+)-[A-Fa-f0-9]+$#', (string) ($row['channel'] ?? ''), $match)) {
        return '';
    }
    $extension = find_sip_user($match[1]);
    return $extension
        ? trim((string) ($extension['exten'] ?? '') . ' ' . (string) ($extension['name'] ?? ''))
        : '';
}

function cdr_export_values(array $row): array
{
    return [
        (string) (($row['accountcode'] ?? '') ?: ($row['userfield'] ?? '')),
        (string) ($row['start'] ?? ''),
        (string) ($row['src'] ?? ''),
        cdr_export_internal($row),
        (string) ($row['dst'] ?? ''),
        (string) ($row['duration'] ?? '0'),
        (string) ($row['billsec'] ?? '0'),
        (string) ($row['disposition'] ?? ''),
        cdr_export_description($row),
        (string) ($row['uniqueid'] ?? ''),
        (string) ($row['linkedid'] ?? ''),
    ];
}

function cdr_excel_phone(string $value): string
{
    $value = str_replace(['"', "\r", "\n"], ['""', '', ''], trim($value));
    return $value === '' ? '' : '="' . $value . '"';
}

if ($format === 'excel') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cdr-' . $stamp . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, [
        'Firma', 'Başlangıç', 'Kaynak', 'Arayan dahili', 'Hedef', 'Toplam süre (sn)',
        'Konuşma süresi (sn)', 'Durum', 'Açıklama', 'UNIQUEID', 'LINKEDID',
    ], ';');
    foreach ($rows as $row) {
        $values = cdr_export_values($row);
        $values[2] = cdr_excel_phone($values[2]);
        $values[4] = cdr_excel_phone($values[4]);
        $values = array_map(static function (string $value): string {
            return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
        }, $values);
        $values[2] = ltrim($values[2], "'");
        $values[4] = ltrim($values[4], "'");
        fputcsv($output, $values, ';');
    }
    fclose($output);
    exit;
}

if ($format !== 'pdf') {
    http_response_code(400);
    exit('Geçersiz dışa aktarma biçimi');
}

function cdr_pdf_text(string $text): string
{
    $encoded = iconv('UTF-8', 'Windows-1254//TRANSLIT', $text);
    $encoded = $encoded === false ? $text : $encoded;
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $encoded);
}

function cdr_pdf_cell(string $value, int $length): string
{
    if (mb_strlen($value, 'UTF-8') > $length) {
        $value = mb_substr($value, 0, max(1, $length - 1), 'UTF-8') . '…';
    }
    return cdr_pdf_text($value);
}

$columns = [
    ['Firma', 18, 55],
    ['Başlangıç', 20, 112],
    ['Kaynak', 18, 96],
    ['Arayan dahili', 18, 105],
    ['Hedef', 18, 96],
    ['Süre', 7, 42],
    ['Durum', 11, 68],
    ['Açıklama', 40, 232],
];
$pageChunks = array_chunk($rows, 42);
if (!$pageChunks) {
    $pageChunks = [[]];
}
$pageCount = count($pageChunks);
$objects = [];
$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
$kids = [];
foreach (array_keys($pageChunks) as $index) {
    $kids[] = (4 + ($index * 2)) . ' 0 R';
}
$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';
$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica '
    . '/Encoding << /Type /Encoding /BaseEncoding /WinAnsiEncoding '
    . '/Differences [208 /Gbreve 221 /Idotaccent 222 /Scedilla 240 /gbreve 253 /dotlessi 254 /scedilla] >> >>';

foreach ($pageChunks as $pageIndex => $pageRows) {
    $pageId = 4 + ($pageIndex * 2);
    $contentId = $pageId + 1;
    $stream = "BT /F1 13 Tf 18 568 Td (" . cdr_pdf_text('CDR Çağrı Raporu') . ") Tj ET\n";
    $subtitle = ($filters['date_from'] ?: 'Tümü') . ' - ' . ($filters['date_to'] ?: 'Tümü')
        . '  |  ' . count($rows) . ' kayıt  |  Sayfa ' . ($pageIndex + 1) . '/' . $pageCount;
    $stream .= "BT /F1 8 Tf 18 552 Td (" . cdr_pdf_text($subtitle) . ") Tj ET\n";
    $x = 18;
    foreach ($columns as [$label, $length, $width]) {
        $stream .= sprintf("BT /F1 7 Tf %.2F 535 Td (%s) Tj ET\n", $x, cdr_pdf_text($label));
        $x += $width;
    }
    $stream .= "0.5 w 18 530 m 824 530 l S\n";
    $y = 517;
    foreach ($pageRows as $row) {
        $values = cdr_export_values($row);
        $display = [$values[0], $values[1], $values[2], $values[3], $values[4], $values[6], $values[7], $values[8]];
        $x = 18;
        foreach ($columns as $columnIndex => [$label, $length, $width]) {
            $stream .= sprintf(
                "BT /F1 6.5 Tf %.2F %.2F Td (%s) Tj ET\n",
                $x,
                $y,
                cdr_pdf_cell((string) ($display[$columnIndex] ?? ''), $length)
            );
            $x += $width;
        }
        $stream .= sprintf("0.15 w 18 %.2F m 824 %.2F l S\n", $y - 4, $y - 4);
        $y -= 12;
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
header('Content-Disposition: attachment; filename="cdr-' . $stamp . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
