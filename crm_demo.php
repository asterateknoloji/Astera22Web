<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Cache-Control: no-store, private');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline';");

$tokenFile = DATA_PATH . DIRECTORY_SEPARATOR . 'crm_demo_token.txt';
$token = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
$providedToken = trim((string) ($_GET['key'] ?? ''));
if ($token === '' || $providedToken === '' || !hash_equals($token, $providedToken)) {
    http_response_code(403);
    exit('Geçersiz CRM demo anahtarı.');
}

$phone = substr(trim((string) ($_GET['telefon'] ?? '')), 0, 32);
$extension = substr(preg_replace('/\D+/', '', (string) ($_GET['dahili'] ?? '')) ?? '', 0, 8);
$event = strtolower(trim((string) ($_GET['olay'] ?? 'test')));
if (!in_array($event, ['ring', 'answer', 'test'], true)) {
    $event = 'test';
}
$department = substr(preg_replace('/[^a-zA-Z0-9_-]+/', '', (string) ($_GET['firma'] ?? '')) ?? '', 0, 64);
$eventLabels = [
    'ring' => 'Çağrı çalıyor',
    'answer' => 'Çağrı cevaplandı',
    'test' => 'Test isteği',
];

$eventsFile = DATA_PATH . DIRECTORY_SEPARATOR . 'crm_demo_events.json';
$handle = fopen($eventsFile, 'c+');
$events = [];
if ($handle !== false) {
    flock($handle, LOCK_EX);
    rewind($handle);
    $decoded = json_decode((string) stream_get_contents($handle), true);
    if (is_array($decoded)) {
        $events = $decoded;
    }
    $entry = [
        'id' => bin2hex(random_bytes(6)),
        'time' => date(DATE_ATOM),
        'phone' => $phone,
        'extension' => $extension,
        'event' => $event,
        'department' => $department,
    ];
    $last = $events[0] ?? [];
    $duplicate = (string) ($last['phone'] ?? '') === $phone
        && (string) ($last['extension'] ?? '') === $extension
        && (string) ($last['event'] ?? '') === $event
        && abs(time() - strtotime((string) ($last['time'] ?? '1970-01-01'))) <= 5;
    if (!$duplicate) {
        array_unshift($events, $entry);
        $events = array_slice($events, 0, 100);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($handle);
    }
    flock($handle, LOCK_UN);
    fclose($handle);
}

$customerNumber = strtoupper(substr(hash('sha256', $phone ?: 'demo'), 0, 8));
$safe = static fn(mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ASTERA · CRM Demo</title>
    <style>
        :root { color-scheme: light; font-family: Inter, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; padding: 32px; background: #1c2739; color: #1f2937; }
        main { width: min(760px, 100%); margin: auto; }
        header { display: flex; justify-content: space-between; align-items: center; color: #fff; margin-bottom: 18px; }
        header h1 { margin: 0; font-size: 22px; }
        header span { color: #b9c5d5; font-size: 13px; }
        .card { background: #fffaf3; border-radius: 16px; padding: 24px; box-shadow: 0 14px 40px #0003; }
        .status { display: inline-flex; padding: 6px 10px; border-radius: 999px; background: #dff4e8; color: #18794e; font-weight: 700; font-size: 12px; }
        .phone { margin: 18px 0 4px; font-size: 32px; font-weight: 750; letter-spacing: .02em; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 22px; }
        .box { padding: 14px; border: 1px solid #e6ded2; border-radius: 10px; background: #fff; }
        .box small { display: block; color: #7a8290; margin-bottom: 5px; }
        .events { margin-top: 18px; }
        .event { display: grid; grid-template-columns: 150px 1fr 100px; gap: 12px; padding: 10px 0; border-bottom: 1px solid #ece5da; font-size: 13px; }
        .muted { color: #7a8290; }
        @media (max-width: 650px) { body { padding: 16px; } .grid { grid-template-columns: 1fr; } .event { grid-template-columns: 1fr; gap: 2px; } }
    </style>
</head>
<body>
<main>
    <header>
        <h1>ASTERA CRM Demo</h1>
        <span>URL tetikleme örneği</span>
    </header>
    <section class="card">
        <span class="status"><?= $safe($eventLabels[$event]) ?></span>
        <div class="phone"><?= $safe($phone ?: 'Numara gönderilmedi') ?></div>
        <p class="muted">Örnek müşteri kartı başarıyla açıldı.</p>
        <div class="grid">
            <div class="box"><small>Müşteri no</small><strong><?= $safe($customerNumber) ?></strong></div>
            <div class="box"><small>Çalan dahili</small><strong><?= $safe($extension ?: '—') ?></strong></div>
            <div class="box"><small>Firma</small><strong><?= $safe($department ?: '—') ?></strong></div>
        </div>
        <div class="events">
            <h3>Son tetiklemeler</h3>
            <?php foreach (array_slice($events, 0, 10) as $row): ?>
                <div class="event">
                    <span><?= $safe(date('d.m.Y H:i:s', strtotime((string) ($row['time'] ?? 'now')))) ?></span>
                    <strong><?= $safe($row['phone'] ?? '') ?></strong>
                    <span><?= $safe($eventLabels[$row['event'] ?? 'test'] ?? '') ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body>
</html>
