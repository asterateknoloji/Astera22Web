#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/integrations.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

$states = [];
$lastSync = 0;

$closeState = static function (array &$state, string $status = 'waiting', string $error = ''): void {
    foreach (['stream', 'listener'] as $key) {
        if (isset($state[$key]) && is_resource($state[$key])) fclose($state[$key]);
        $state[$key] = null;
    }
    $state['buffer'] = '';
    $state['retry_at'] = time() + max(1, (int) ($state['config']['reconnect_seconds'] ?? 10));
    integration_connector_status((int) $state['config']['connector_id'], $status, $error);
};

$send = static function (array &$state, string $message): bool {
    if (!is_resource($state['stream'] ?? null)) return false;
    $payload = integration_fias_from_utf8($message, $state['config'])
        . integration_fias_terminator($state['config']);
    $written = @fwrite($state['stream'], $payload);
    if ($written === false) return false;
    integration_fias_log($state['config'], 'outgoing', $message, 'processed');
    $state['last_write'] = time();
    return true;
};

while ($running) {
    if (time() - $lastSync >= 10) {
        try {
            $connectors = integration_fidelio_enabled_connectors();
            $active = [];
            foreach ($connectors as $connector) {
                $id = (int) $connector['connector_id'];
                $active[$id] = true;
                $signature = implode('|', [
                    $connector['connection_mode'], $connector['bind_address'],
                    $connector['remote_host'], $connector['port'], $connector['message_terminator'],
                ]);
                if (isset($states[$id]) && $states[$id]['signature'] !== $signature) {
                    $closeState($states[$id]);
                    unset($states[$id]);
                }
                if (!isset($states[$id])) {
                    $states[$id] = [
                        'config' => $connector, 'signature' => $signature, 'stream' => null,
                        'listener' => null, 'buffer' => '', 'retry_at' => 0, 'last_write' => 0,
                    ];
                    integration_connector_status($id, 'waiting');
                } else {
                    $states[$id]['config'] = $connector;
                }
            }
            foreach (array_keys($states) as $id) {
                if (!isset($active[$id])) {
                    $closeState($states[$id], 'disabled');
                    unset($states[$id]);
                }
            }
        } catch (Throwable $exception) {
            error_log('Fidelio FIAS yapılandırması okunamadı: ' . $exception->getMessage());
        }
        $lastSync = time();
    }

    foreach ($states as $id => &$state) {
        if (is_resource($state['stream']) || time() < (int) $state['retry_at']) continue;
        $config = $state['config'];
        $mode = (string) $config['connection_mode'];
        if ($mode === 'server') {
            if (!is_resource($state['listener'])) {
                $address = 'tcp://' . (string) $config['bind_address'] . ':' . (int) $config['port'];
                $listener = @stream_socket_server($address, $errno, $error);
                if (!$listener) {
                    $state['retry_at'] = time() + (int) $config['reconnect_seconds'];
                    integration_connector_status($id, 'error', "{$error} ({$errno})");
                    continue;
                }
                stream_set_blocking($listener, false);
                $state['listener'] = $listener;
                integration_connector_status($id, 'waiting');
            }
        } else {
            $address = 'tcp://' . (string) $config['remote_host'] . ':' . (int) $config['port'];
            integration_connector_status($id, 'connecting');
            $stream = @stream_socket_client(
                $address,
                $errno,
                $error,
                (int) $config['connect_timeout_seconds']
            );
            if (!$stream) {
                $state['retry_at'] = time() + (int) $config['reconnect_seconds'];
                integration_connector_status($id, 'error', "{$error} ({$errno})");
                continue;
            }
            stream_set_blocking($stream, false);
            $state['stream'] = $stream;
            $state['buffer'] = '';
            integration_connector_status($id, 'connected');
            $send($state, integration_fias_message('LS', ['DA' => date('ymd'), 'TI' => date('His')]));
        }
    }
    unset($state);

    $read = [];
    $resourceMap = [];
    foreach ($states as $id => $state) {
        foreach (['listener', 'stream'] as $kind) {
            if (is_resource($state[$kind] ?? null)) {
                $resourceId = (int) $state[$kind];
                $read[] = $state[$kind];
                $resourceMap[$resourceId] = [$id, $kind];
            }
        }
    }
    if (!$read) {
        usleep(500000);
        continue;
    }
    $write = null;
    $except = null;
    $changed = @stream_select($read, $write, $except, 1);
    if ($changed === false) {
        usleep(200000);
        continue;
    }
    foreach ($read as $resource) {
        [$id, $kind] = $resourceMap[(int) $resource];
        if (!isset($states[$id])) continue;
        $state =& $states[$id];
        if ($kind === 'listener') {
            $client = @stream_socket_accept($resource, 0);
            if ($client) {
                if (is_resource($state['stream'])) fclose($state['stream']);
                stream_set_blocking($client, false);
                $state['stream'] = $client;
                $state['buffer'] = '';
                integration_connector_status($id, 'connected');
                $send($state, integration_fias_message('LS', ['DA' => date('ymd'), 'TI' => date('His')]));
            }
            unset($state);
            continue;
        }
        $chunk = @fread($resource, 8192);
        if ($chunk === '' && feof($resource)) {
            $closeState($state, 'waiting', 'FIAS bağlantısı kapandı.');
            unset($state);
            continue;
        }
        if ($chunk === false || $chunk === '') {
            unset($state);
            continue;
        }
        $state['buffer'] .= $chunk;
        $terminator = integration_fias_terminator($state['config']);
        while (($position = strpos($state['buffer'], $terminator)) !== false) {
            $message = integration_fias_to_utf8(
                substr($state['buffer'], 0, $position),
                $state['config']
            );
            $state['buffer'] = substr($state['buffer'], $position + strlen($terminator));
            if (trim($message) === '') continue;
            try {
                $parsed = integration_fias_process($state['config'], $message);
                callcenter_execute(
                    'UPDATE integration_connectors SET last_message_at=now(),last_error=:error WHERE connector_id=:id',
                    ['error' => '', 'id' => $id]
                );
                if (($parsed['type'] ?? '') === 'LS') {
                    $send($state, integration_fias_message('LD', [
                        'IF' => 'ASTERA-PBX',
                        'V#' => (string) ($state['config']['fias_version'] ?? '2.20'),
                    ]));
                }
            } catch (Throwable $exception) {
                error_log("FIAS mesajı işlenemedi [{$id}]: " . $exception->getMessage());
            }
        }
        unset($state);
    }

    foreach ($states as &$state) {
        if (
            is_resource($state['stream'] ?? null)
            && time() - (int) $state['last_write'] >= (int) $state['config']['keepalive_seconds']
        ) {
            if (!$send($state, integration_fias_message('LA'))) {
                $closeState($state, 'waiting', 'FIAS keepalive gönderilemedi.');
            }
        }
    }
    unset($state);
}

foreach ($states as &$state) $closeState($state, 'disabled');
