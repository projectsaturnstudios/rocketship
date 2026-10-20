<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use ProjectSaturnStudios\Rocketship\Tests\Support\HttpResponse;
use ProjectSaturnStudios\Rocketship\Tests\Support\NativeHarness;

function streaming(): NativeHarness
{
    $big = str_repeat('0123456789abcdef', 5 * 65536); // 5 MB
    $file = sys_get_temp_dir().'/rocketship-stream-'.bin2hex(random_bytes(4)).'.bin';
    file_put_contents($file, $big);
    test()->files = [$file, $once = $file.'.once'];

    return test()->harness = NativeHarness::boot(function (Application $app) use ($big, $file, $once): void {
        $router = $app['router'];

        $router->get('/small', fn () => 'small');
        $router->get('/big', fn () => $big);
        $router->get('/download', fn () => response()->download($file));
        $router->get('/sized/{bytes}', fn (string $bytes) => str_repeat('s', (int) $bytes));
        $router->get('/once', function () use ($file, $once) {
            copy($file, $once);

            return response()->download($once)->deleteFileAfterSend();
        });
        $router->get('/stream', fn () => response()->stream(function (): void {
            echo 'alpha,';
            echo str_repeat('b', 100_000);
            echo ',omega';
        }));
        $router->get('/stream-fails', fn () => response()->stream(function (): void {
            echo 'partial';

            throw new RuntimeException('mid-stream');
        }));
    });
}

afterEach(function () {
    if (isset($this->harness)) {
        $this->harness->shutdown();
        // PHPUnit keeps every test case for the whole run, and a Laravel app is all reference cycles:
        // drop the harness and collect, or each test's app and buffers stay until the collector runs.
        unset($this->harness);
        gc_collect_cycles();
    }

    foreach ($this->files ?? [] as $file) {
        @unlink($file);
    }
});

/**
 * Read from $client in small bites while driving the loop, as a slow client would, until $bytes arrived
 * or $done says the buffer is complete.
 *
 * @param  ?Closure(string): bool  $done
 */
function slowRead(NativeHarness $harness, $client, int $bytes, ?Closure $done = null, int $bite = 65536): string
{
    $buffer = '';
    $deadline = microtime(true) + 10;

    while (strlen($buffer) < $bytes && ! ($done && $done($buffer)) && microtime(true) < $deadline) {
        $harness->loop->tick(0.0);
        $chunk = @fread($client, $bite);
        $buffer .= is_string($chunk) ? $chunk : '';
    }

    return $buffer;
}

it('streams a file download from the loop in chunks', function () {
    $response = streaming()->get('/download');

    expect($response->status)->toBe(200)
        ->and($response->header('Content-Length'))->toBe((string) (5 * 1_048_576))
        ->and($response->body)->toBe(file_get_contents(test()->files[0]));
});

it('serves a byte range of a download with 206', function () {
    $response = streaming()->get('/download', ['Range' => 'bytes=100-199']);

    expect($response->status)->toBe(206)
        ->and($response->header('Content-Range'))->toBe('bytes 100-199/'.(5 * 1_048_576))
        ->and($response->body)->toBe(substr(file_get_contents(test()->files[0]), 100, 100));
});

it('deletes a download marked deleteFileAfterSend once it is sent', function () {
    $response = streaming()->get('/once');

    expect(strlen($response->body))->toBe(5 * 1_048_576)
        ->and(file_exists(test()->files[1]))->toBeFalse();
});

it('keeps serving other connections while a slow reader owes megabytes', function (string $path) {
    $harness = streaming();
    $slow = $harness->connect();
    $harness->write($slow, "GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n");
    $harness->run(0.05); // the big response is queued; the slow client has read nothing

    $quick = $harness->get('/small');
    $rest = slowRead($harness, $slow, PHP_INT_MAX, fn (string $buffer) => strlen($buffer) > 5 * 1_048_576 && count(HttpResponse::parseAll($buffer)[0]) === 1);
    [[$big]] = HttpResponse::parseAll($rest);

    expect($quick->body)->toBe('small')
        ->and($big->body)->toBe(file_get_contents(test()->files[0]));
})->with(['string body' => ['/big'], 'file body' => ['/download']]);

it('frames a streamed response with chunked encoding on HTTP/1.1', function () {
    $response = streaming()->get('/stream');

    expect($response->header('Transfer-Encoding'))->toBe('chunked')
        ->and($response->header('Content-Length'))->toBeNull()
        ->and($response->body)->toBe('alpha,'.str_repeat('b', 100_000).',omega');
});

it('ends a streamed response to HTTP/1.0 by closing', function () {
    $harness = streaming();
    $client = $harness->connect();
    $harness->write($client, "GET /stream HTTP/1.0\r\n\r\n");

    $raw = slowRead($harness, $client, 100_000 + 100);

    expect($harness->closedByServer($client))->toBeTrue();

    [[$response]] = HttpResponse::parseAll($raw, closed: true);

    expect($response->header('Transfer-Encoding'))->toBeNull()
        ->and($response->header('Connection'))->toBe('close')
        ->and($response->body)->toBe('alpha,'.str_repeat('b', 100_000).',omega');
});

it('closes instead of appending a 500 when a streamed response fails midway', function () {
    $harness = streaming();
    $client = $harness->connect();
    $harness->write($client, "GET /stream-fails HTTP/1.1\r\nHost: localhost\r\n\r\n");

    $raw = slowRead($harness, $client, 30);

    expect($harness->closedByServer($client))->toBeTrue()
        ->and($raw)->toContain("partial")
        ->and($raw)->not->toContain('500');
});

it('answers pipelined requests in order, a file between strings', function () {
    $harness = streaming();
    $responses = $harness->exchange(
        "GET /small HTTP/1.1\r\nHost: localhost\r\n\r\n"
        ."GET /download HTTP/1.1\r\nHost: localhost\r\nRange: bytes=0-9\r\n\r\n"
        ."GET /sized/3 HTTP/1.1\r\nHost: localhost\r\n\r\n",
        3,
    );

    expect(array_map(fn (HttpResponse $r) => $r->body, $responses))->toBe(['small', '0123456789', 'sss']);
});

it('stops serving pipelined requests while too much is owed and resumes as the client reads', function () {
    $harness = streaming();
    $client = $harness->connect();
    // 4 MB each: past what loopback socket buffers absorb (macOS autotunes them to several MB).
    $request = "GET /sized/4000000 HTTP/1.1\r\nHost: localhost\r\n\r\n";
    $harness->write($client, str_repeat($request, 5)."GET /small HTTP/1.1\r\nHost: localhost\r\n\r\n");
    $harness->run(0.1);

    // The client has read nothing: with a 1 MB cap the worker stops once the kernel buffers are full.
    expect($harness->server->served())->toBeLessThan(6);

    $raw = slowRead($harness, $client, PHP_INT_MAX, fn (string $buffer) => strlen($buffer) > 5 * 4_000_000 && count(HttpResponse::parseAll($buffer)[0]) === 6);
    [$responses] = HttpResponse::parseAll($raw);

    expect($responses)->toHaveCount(6)
        ->and(strlen($responses[4]->body))->toBe(4_000_000)
        ->and($responses[5]->body)->toBe('small');
});

it('streams a large download without holding it in memory', function () {
    $size = 64 * 1_048_576;
    $file = sys_get_temp_dir().'/rocketship-huge-'.bin2hex(random_bytes(4)).'.bin';
    $handle = fopen($file, 'w');
    ftruncate($handle, $size);
    fclose($handle);
    test()->files = [$file];

    $harness = test()->harness = NativeHarness::boot(function (Application $app) use ($file): void {
        $app['router']->get('/huge', fn () => response()->download($file));
    });
    $client = $harness->connect();
    $harness->write($client, "GET /huge HTTP/1.1\r\nHost: h\r\n\r\n");

    memory_reset_peak_usage();
    $before = memory_get_usage();
    $received = 0;
    $deadline = microtime(true) + 30;

    // A slow reader: 64 KB at a time, counted and dropped, so only the server's buffers count.
    while ($received <= $size && microtime(true) < $deadline) {
        $harness->loop->tick(0.0);
        $chunk = @fread($client, 65536);
        $received += is_string($chunk) ? strlen($chunk) : 0;
        usleep(1000);
    }

    expect($received)->toBeGreaterThan($size)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(32 * 1_048_576);
});
