<?php

declare(strict_types=1);

use ProjectSaturnStudios\Rocketship\Tests\Support\NativeServerProcess;

/**
 * Headers each server sets for its own framing or identity; the body comparison covers the length.
 * Static files: native also sends ETag and Last-Modified and answers ranges, as FrankenPHP's Caddy does;
 * Swoole's static file response sends neither.
 */
const PARITY_IGNORED_HEADERS = ['date', 'server', 'x-powered-by', 'connection', 'keep-alive', 'transfer-encoding', 'content-length', 'etag', 'last-modified', 'accept-ranges'];

/** @return list<array{string, string, array<string, string>, string}> method, path, headers, body */
function parityRequests(): array
{
    $boundary = 'ParityBoundary';
    $upload = "--{$boundary}\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nTwo files\r\n"
        ."--{$boundary}\r\nContent-Disposition: form-data; name=\"first\"; filename=\"first.txt\"\r\nContent-Type: text/plain\r\n\r\n".str_repeat('1', 5000)."\r\n"
        ."--{$boundary}\r\nContent-Disposition: form-data; name=\"second\"; filename=\"second.bin\"\r\nContent-Type: application/octet-stream\r\n\r\n".str_repeat("\x02", 70_000)."\r\n"
        ."--{$boundary}--\r\n";

    return [
        ['GET', '/parity/plain', [], ''],
        ['GET', '/parity/json', [], ''],
        ['GET', '/parity/view', [], ''],
        ['GET', '/parity/cookie', [], ''],
        ['GET', '/parity/redirect', [], ''],
        ['GET', '/parity/stream', [], ''],
        ['GET', '/parity/download', [], ''],
        ['POST', '/parity/upload', ['Content-Type' => "multipart/form-data; boundary={$boundary}"], $upload],
        ['GET', '/parity/dd', [], ''],
        ['GET', '/parity/throw', [], ''],
        ['POST', '/parity/large-body', ['Content-Type' => 'application/octet-stream'], str_repeat('L', 2 * 1_048_576)],
        ['GET', '/robots.txt', [], ''],
        ['GET', '/missing', [], ''],
    ];
}

/**
 * Send one request on a fresh connection and read the whole response.
 *
 * @param  array<string, string>  $headers
 * @return array{status: int, headers: array<string, list<string>>, body: string}
 */
function parityExchange(int $port, string $method, string $path, array $headers, string $body): array
{
    $socket = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
    stream_set_timeout($socket, 15);

    $head = "{$method} {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n";

    foreach ($headers as $name => $value) {
        $head .= "{$name}: {$value}\r\n";
    }

    fwrite($socket, $head."\r\n".$body);
    $raw = (string) stream_get_contents($socket);
    fclose($socket);

    [$top, $payload] = explode("\r\n\r\n", $raw, 2) + ['', ''];
    $lines = explode("\r\n", $top);
    $status = (int) (explode(' ', array_shift($lines), 3)[1] ?? 0);
    $parsed = [];

    foreach ($lines as $line) {
        [$name, $value] = array_map(trim(...), explode(':', $line, 2)) + ['', ''];
        $parsed[strtolower($name)][] = $value;
    }

    if (strtolower($parsed['transfer-encoding'][0] ?? '') === 'chunked') {
        $payload = parityDechunk($payload);
    }

    return ['status' => $status, 'headers' => $parsed, 'body' => $payload];
}

function parityDechunk(string $payload): string
{
    $body = '';

    while (($end = strpos($payload, "\r\n")) !== false) {
        $size = hexdec(trim(explode(';', substr($payload, 0, $end))[0]));

        if ($size === 0) {
            break;
        }

        $body .= substr($payload, $end + 2, $size);
        $payload = substr($payload, $end + 2 + $size + 2);
    }

    return $body;
}

/**
 * What must match between servers: status, headers without the ignored ones (cookies by name), body with
 * var-dumper's random ids made stable.
 *
 * @param  array{status: int, headers: array<string, list<string>>, body: string}  $response
 * @return array<string, mixed>
 */
function parityComparable(array $response, int $port, string $root): array
{
    // Each server listens on its own port and runs its own copy of the app: URLs and error pages carry both.
    $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $json = str_replace(["127.0.0.1:{$port}", '/private'.$root, $root], ['127.0.0.1:PORT', 'ROOT', 'ROOT'], $json);
    $response = json_decode($json, true);
    $headers = array_diff_key($response['headers'], array_flip(PARITY_IGNORED_HEADERS));

    if (isset($headers['set-cookie'])) {
        $headers['set-cookie'] = array_map(fn (string $cookie) => strtok($cookie, '='), $headers['set-cookie']);
        sort($headers['set-cookie']);
    }

    ksort($headers);

    return [
        'status' => $response['status'],
        'headers' => $headers,
        'body' => preg_replace('/sf-dump-\d+/', 'sf-dump-N', $response['body']),
    ];
}

/**
 * Run every parity request against a server and record what each answered and which Octane events it fired.
 *
 * @return array<string, array{response: array<string, mixed>, events: list<string>}>
 */
function parityRun(NativeServerProcess $server): array
{
    $log = $server->root.'/storage/parity-events.log';
    $results = [];

    foreach (parityRequests() as [$method, $path, $headers, $body]) {
        @unlink($log);
        $response = parityExchange($server->port, $method, $path, $headers, $body);

        // RequestTerminated fires after the response is out: wait for it before reading the list.
        $deadline = microtime(true) + 3;

        while (! str_contains((string) @file_get_contents($log), 'RequestTerminated') && microtime(true) < $deadline) {
            usleep(20_000);
        }

        $results["{$method} {$path}"] = [
            'response' => parityComparable($response, $server->port, $server->root),
            // Request events only. A worker booting (Swoole's task worker boots lazily) or Swoole's once-a-second tick
            // (ticks come with Part 4) lands in whatever request it overlaps.
            'events' => array_values(array_filter(
                explode(PHP_EOL, (string) @file_get_contents($log)),
                fn (string $event) => $event !== ''
                    && ! preg_match('/\\\\(Tick\\w+|WorkerStarting|WorkerStopping)$/', $event),
            )),
        ];
    }

    return $results;
}

it('answers like Swoole and fires the same Octane events', function (string $mode) {
    if (! extension_loaded('swoole')) {
        test()->markTestSkipped('parity needs ext-swoole');
    }

    if ($mode === 'threads' && (! PHP_ZTS || ! extension_loaded('parallel'))) {
        test()->markTestSkipped('threads mode needs a ZTS PHP with ext-parallel');
    }

    // Debug off on both: a debug error page lists the server's own stack frames, which can never match.
    $swoole = new NativeServerProcess(env: ['APP_DEBUG' => 'false'], artisan: ['octane:start', '--server=swoole', '--workers=1', '--task-workers=1'], swoole: true);

    try {
        $expected = parityRun($swoole);
    } finally {
        $swoole->stop();
    }

    $native = new NativeServerProcess(env: ['APP_DEBUG' => 'false', 'OCTANE_NATIVE_MODE' => $mode], artisan: ['octane:start', '--server=native', '--workers=1']);

    try {
        $actual = parityRun($native);
    } finally {
        $native->stop();
    }

    foreach ($expected as $request => $swooleResult) {
        expect($actual[$request])->toBe($swooleResult, "{$request} differs from Swoole");
    }
})->with('modes');
