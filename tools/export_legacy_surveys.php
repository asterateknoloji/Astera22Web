<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu araç yalnız komut satırından çalıştırılabilir.\n");
    exit(1);
}

$output = $argv[1] ?? '';
$host = getenv('LEGACY_DB_HOST') ?: '';
$database = getenv('LEGACY_DB_NAME') ?: 'asteriskcdrdb';
$user = getenv('LEGACY_DB_USER') ?: '';
$password = getenv('LEGACY_DB_PASSWORD') ?: '';
if ($output === '' || $host === '' || $user === '') {
    fwrite(STDERR, "Kullanım: LEGACY_DB_HOST=... LEGACY_DB_USER=... LEGACY_DB_PASSWORD=... php export_legacy_surveys.php output.json\n");
    exit(1);
}

$pdo = new PDO(
    "mysql:host={$host};dbname={$database};charset=utf8",
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
    ]
);

$fetch = static fn(string $sql): array => $pdo->query($sql)->fetchAll();
$payload = [
    'source' => [
        'database' => $database,
        'exported_at' => date(DATE_ATOM),
    ],
    'surveys' => $fetch(
        'SELECT id,AnketAdi,Aciklama,Aktif,OlusturmaTarihi '
        . 'FROM Anketler ORDER BY id'
    ),
    'categories' => $fetch(
        'SELECT id,adi,anket_id,MaxPuan,Sira '
        . 'FROM anketkategori ORDER BY Sira,id'
    ),
    'topics' => $fetch(
        'SELECT id,Konuadi,kategori_id FROM AnketKonular ORDER BY kategori_id,id'
    ),
    'questions' => $fetch(
        'SELECT id,AnketAdi,Aktif,Agirlik,konu_id,Tip,MaxPuan,Kategori_id '
        . 'FROM AnketTanim ORDER BY Kategori_id,id'
    ),
    'masters' => $fetch(
        'SELECT id,AgentID,Tarih,Konu,Degerlendiren,kategori_id,Aciklama,cagriid,AramaYonu '
        . 'FROM AnketMaster ORDER BY id'
    ),
    'answers' => $fetch(
        'SELECT id,AgentID,AnketAdi,MaxPuan,Puan,Agirlik,AnketNotu,Anket_id,'
        . 'Kategori_id,Tip,AgentAdi,cagriid,DegerlendirmeTarihi,Degerlendiren '
        . 'FROM AnketHareket ORDER BY Anket_id,id'
    ),
    'agents' => $fetch(
        'SELECT id,AgentID FROM Agent ORDER BY id'
    ),
    'calls' => $fetch(
        'SELECT c.id,c.uniqid,c.Tarih,c.dahili,c.TelefonNo,c.disposition '
        . 'FROM cagrilar c INNER JOIN AnketMaster m ON m.cagriid=c.id ORDER BY c.id'
    ),
];

$encoded = json_encode(
    $payload,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
if (file_put_contents($output, $encoded . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('Dışa aktarım dosyası yazılamadı.');
}

printf(
    "Anket=%d Kategori=%d Konu=%d Soru=%d Değerlendirme=%d Cevap=%d\n",
    count($payload['surveys']),
    count($payload['categories']),
    count($payload['topics']),
    count($payload['questions']),
    count($payload['masters']),
    count($payload['answers'])
);
