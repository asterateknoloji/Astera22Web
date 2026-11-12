<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu araç yalnız komut satırında çalışır.\n");
    exit(2);
}
$options = getopt('', [
    'generate-key',
    'private-key:',
    'public-key:',
    'payload:',
    'output:',
    'openssl:',
    'passphrase-env:',
]);

$privatePath = (string) ($options['private-key'] ?? '');
$publicPath = (string) ($options['public-key'] ?? '');
$openssl = (string) ($options['openssl'] ?? getenv('OPENSSL_BIN') ?: 'openssl');
$passphraseEnv = (string) ($options['passphrase-env'] ?? 'ASTERA_LICENSE_ISSUER_PASSPHRASE');
$passphrase = (string) getenv($passphraseEnv);

if (array_key_exists('generate-key', $options)) {
    if ($privatePath === '' || $publicPath === '') {
        fwrite(STDERR, "--generate-key için --private-key ve --public-key zorunludur.\n");
        exit(2);
    }
    if (is_file($privatePath) || is_file($publicPath)) {
        fwrite(STDERR, "Anahtar dosyası zaten var; üzerine yazılmadı.\n");
        exit(2);
    }
    if ($passphrase === '') {
        fwrite(STDERR, "Şifreli PEM üretimi için {$passphraseEnv} ortam değişkeni zorunludur.\n");
        exit(2);
    }
    $passphrasePath = license_tool_temp('passphrase', $passphrase);
    try {
        license_tool_exec(
            escapeshellarg($openssl) . ' genpkey -algorithm ED25519 -aes-256-cbc '
            . '-pass file:' . escapeshellarg($passphrasePath) . ' -out ' . escapeshellarg($privatePath)
        );
        license_tool_exec(
            escapeshellarg($openssl) . ' pkey -in ' . escapeshellarg($privatePath)
            . ' -passin file:' . escapeshellarg($passphrasePath)
            . ' -pubout -out ' . escapeshellarg($publicPath)
        );
    } finally {
        @unlink($passphrasePath);
        $passphrase = str_repeat("\0", strlen($passphrase));
    }
        @chmod($privatePath, 0600);
        @chmod($publicPath, 0644);
    fwrite(STDOUT, "Şifreli Ed25519 PEM anahtar çifti üretildi. Özel anahtarı müşteri santralına kopyalamayın.\n");
    exit(0);
}

$payloadPath = (string) ($options['payload'] ?? '');
$outputPath = (string) ($options['output'] ?? '');
if ($privatePath === '' || $payloadPath === '' || $outputPath === '') {
    fwrite(STDERR, <<<TXT
Kullanım:
  ASTERA_LICENSE_ISSUER_PASSPHRASE='...' php tools/license_issue.php --generate-key --private-key=vendor.pem --public-key=license.pub
  ASTERA_LICENSE_ISSUER_PASSPHRASE='...' php tools/license_issue.php --private-key=vendor.pem --payload=payload.json --output=license.json

TXT);
    exit(2);
}

$privateEncoded = trim((string) @file_get_contents($privatePath));
$payload = json_decode((string) @file_get_contents($payloadPath), true, 32, JSON_THROW_ON_ERROR);
if (!is_array($payload)) {
    fwrite(STDERR, "Payload JSON nesnesi olmalı.\n");
    exit(2);
}
foreach (['license_id', 'installation_fingerprint', 'entitlements'] as $required) {
    if (!array_key_exists($required, $payload)) {
        fwrite(STDERR, "Payload alanı eksik: {$required}\n");
        exit(2);
    }
}
$payload['issued_at'] ??= (new DateTimeImmutable('now'))->format(DATE_ATOM);
$payloadBytes = json_encode(
    $payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);
$private = base64_decode($privateEncoded, true);
if ($private !== false && extension_loaded('sodium')
    && strlen($private) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    $signature = sodium_crypto_sign_detached($payloadBytes, $private);
} elseif (str_starts_with($privateEncoded, '-----BEGIN PRIVATE KEY-----')
    || str_starts_with($privateEncoded, '-----BEGIN ENCRYPTED PRIVATE KEY-----')) {
    if (str_contains($privateEncoded, 'BEGIN ENCRYPTED PRIVATE KEY') && $passphrase === '') {
        throw new RuntimeException("Şifreli PEM için {$passphraseEnv} ortam değişkeni zorunlu");
    }
    $payloadTemp = tempnam(sys_get_temp_dir(), 'license-payload-');
    $signatureTemp = tempnam(sys_get_temp_dir(), 'license-signature-');
    $passphraseTemp = $passphrase === '' ? null : license_tool_temp('passphrase', $passphrase);
    if ($payloadTemp === false || $signatureTemp === false) {
        throw new RuntimeException('Geçici imza dosyası oluşturulamadı');
    }
    try {
        file_put_contents($payloadTemp, $payloadBytes, LOCK_EX);
        @chmod($payloadTemp, 0600);
        @chmod($signatureTemp, 0600);
        license_tool_exec(
            escapeshellarg($openssl) . ' pkeyutl -sign -rawin -inkey '
            . escapeshellarg($privatePath) . ' -in ' . escapeshellarg($payloadTemp)
            . ($passphraseTemp === null ? '' : ' -passin file:' . escapeshellarg($passphraseTemp))
            . ' -out ' . escapeshellarg($signatureTemp)
        );
        $signature = (string) file_get_contents($signatureTemp);
    } finally {
        @unlink($payloadTemp);
        @unlink($signatureTemp);
        if ($passphraseTemp !== null) {
            @unlink($passphraseTemp);
        }
    }
    if (strlen($signature) !== 64) {
        throw new RuntimeException('OpenSSL geçersiz Ed25519 imzası üretti');
    }
} else {
    fwrite(STDERR, "Özel anahtar geçersiz veya imza motoru kullanılamıyor.\n");
    exit(2);
}
$base64url = static fn (string $value): string =>
    rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$envelope = json_encode([
    'payload' => $base64url($payloadBytes),
    'signature' => $base64url($signature),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
license_tool_write($outputPath, $envelope, 0600);
fwrite(STDOUT, "İmzalı lisans üretildi: {$outputPath}\n");

function license_tool_write(string $path, string $contents, int $mode): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException("Dizin oluşturulamadı: {$directory}");
    }
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException("Dosya yazılamadı: {$path}");
    }
    @chmod($path, $mode);
}

function license_tool_temp(string $label, string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'astera-license-' . $label . '-');
    if ($path === false || file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Güvenli geçici dosya oluşturulamadı');
    }
    @chmod($path, 0600);
    return $path;
}

function license_tool_exec(string $command): void
{
    $lines = [];
    $code = 0;
    exec($command . ' 2>&1', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException("OpenSSL işlemi başarısız:\n" . implode("\n", $lines));
    }
}
