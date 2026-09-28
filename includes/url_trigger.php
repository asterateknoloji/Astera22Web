<?php
declare(strict_types=1);

function url_trigger_normalize_number(string $number, string $format): string
{
    $number = trim($number);
    $digits = preg_replace('/\D+/', '', $number) ?? '';
    if ($format === 'e164_tr') {
        if (str_starts_with($digits, '90')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '0')) {
            return '+90' . substr($digits, 1);
        }
        return $digits !== '' ? '+90' . $digits : '';
    }
    return $format === 'raw' ? $number : $digits;
}

function url_trigger_build_url(
    array $config,
    string $caller,
    string $extension,
    string $event,
    string $callId = ''
): string {
    $template = trim((string) ($config['url_template'] ?? ''));
    $caller = url_trigger_normalize_number(
        $caller,
        (string) ($config['number_format'] ?? 'digits')
    );
    $replacements = [
        '{caller}' => rawurlencode($caller),
        '{extension}' => rawurlencode($extension),
        '{department}' => rawurlencode((string) ($config['dept'] ?? '')),
        '{event}' => rawurlencode($event),
        '{callid}' => rawurlencode($callId),
    ];
    $url = strtr($template, $replacements);
    if (!str_contains($template, '{caller}')) {
        $url .= rawurlencode($caller);
    }
    return $url;
}

function url_trigger_valid_template(string $template): bool
{
    $probe = strtr(trim($template), [
        '{caller}' => '905551112233',
        '{extension}' => '1001',
        '{department}' => 'firma',
        '{event}' => 'ring',
        '{callid}' => '1790168846.436',
    ]);
    $parts = parse_url($probe);
    return is_array($parts)
        && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        && trim((string) ($parts['host'] ?? '')) !== '';
}

function url_trigger_request(string $url): array
{
    $parts = parse_url($url);
    if (
        !is_array($parts)
        || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host'])
    ) {
        return ['ok' => false, 'code' => 0, 'error' => 'Geçersiz tetikleme URL’si'];
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 3,
            'ignore_errors' => true,
            'header' => "User-Agent: ASTERA-URL-Trigger/1.0\r\nConnection: close\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $code = 0;
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $match)) {
        $code = (int) $match[1];
    }
    return [
        'ok' => $body !== false && $code >= 200 && $code < 400,
        'code' => $code,
        'error' => $body === false ? 'URL çağrısı başarısız' : null,
    ];
}
