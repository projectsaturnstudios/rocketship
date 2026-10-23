<?php

declare(strict_types=1);

use ProjectSaturnStudios\Rocketship\Native\Control\ControlSocket;
use ProjectSaturnStudios\Rocketship\Native\WorkerMode;
use ProjectSaturnStudios\Rocketship\Tests\Support\NativeServerProcess;

/** @param array<string, mixed> $state */
function nativeServer(string $mode, array $state = [], array $env = [], bool $wait = true): NativeServerProcess
{
    if ($mode === 'threads' && (! PHP_ZTS || ! extension_loaded('parallel'))) {
        test()->markTestSkipped('threads mode needs a ZTS PHP with ext-parallel');
    }

    if ($mode === 'processes' && ! WorkerMode::processesAvailable()) {
        test()->markTestSkipped('processes mode needs ext-pcntl');
    }

    return test()->server = new NativeServerProcess(['mode' => $mode, ...$state], $env, $wait);
}

afterEach(function () {
    if (isset($this->server)) {
        $this->server->stop();
    }
});

/** @param list<array{status: int, body: string}> $responses */
function bootTokens(array $responses): array
{
    return array_values(array_unique(array_map(fn (array $r) => json_decode($r['body'], true)['boot'], $responses)));
}

it('spreads connections across every worker', function (string $mode) {
    $server = nativeServer($mode, ['workers' => 3]);

    $responses = $server->hammer('/worker', 300);
    $ids = array_count_values(array_map(fn (array $r) => json_decode($r['body'], true)['id'], $responses));
    ksort($ids);

    expect(array_column($responses, 'status'))->each->toBe(200)
        ->and(array_keys($ids))->toBe([0, 1, 2]);
})->with('modes');

it('replaces a worker after max_requests without dropping a request', function (string $mode) {
    $server = nativeServer($mode, ['workers' => 1, 'maxRequests' => 5]);

    // Recycling is rolling: the spent worker keeps answering until its replacement serves, so keep asking
    // until three generations have answered.
    $responses = [];
    $deadline = microtime(true) + 20;

    do {
        $responses[] = $last = $server->get('/worker');
    } while ($last['status'] === 200 && count(bootTokens($responses)) < 3 && microtime(true) < $deadline);

    expect(array_column($responses, 'status'))->each->toBe(200)
        ->and(count(bootTokens($responses)))->toBeGreaterThanOrEqual(3);
})->with('modes');

it('replaces a worker that dies of a fatal error and keeps serving', function (string $mode) {
    $server = nativeServer($mode, ['workers' => 1]);
    $before = $server->worker()['boot'];

    $fatal = $server->get('/fatal');
    $after = $server->worker()['boot'];

    // The slot refills as the dead worker's control stream closes; the report comes with the reap after it.
    $report = $mode === 'threads' ? 'Worker 0 died of a fatal error' : 'Worker 0 exited with status 255';
    $deadline = microtime(true) + 3;

    while (! str_contains($server->output(), $report) && microtime(true) < $deadline) {
        $server->pause(0.05);
    }

    expect($fatal['status'])->not->toBe(200)
        ->and($after)->not->toBe($before)
        ->and($server->output())->toContain($report);
})->with('modes');

it('answers connections queued behind a dying worker from its replacement', function (string $mode) {
    $server = nativeServer($mode, ['workers' => 1]);
    $server->worker();

    // The only worker is busy half a second before it dies; five connections queue on its listener meanwhile.
    $responses = $server->staggered([['/fatal?wait=0.5', 0.0], ...array_fill(0, 5, ['/worker', 0.2])]);

    expect($responses[0]['status'])->not->toBe(200)
        ->and(array_column(array_slice($responses, 1), 'status'))->each->toBe(200);
})->with('modes');

it('answers 408 past max_execution_time and replaces the worker', function (string $mode) {
    // As Octane on Swoole: wall-clock time, 408 to the client, the worker replaced.
    $server = nativeServer($mode, ['workers' => 1, 'octaneConfig' => ['max_execution_time' => 1]]);
    $before = $server->worker()['boot'];

    $started = microtime(true);
    $spun = $server->get('/spin');

    expect($spun['status'])->toBe(408)
        ->and(microtime(true) - $started)->toBeLessThan(3.0)
        ->and($server->worker()['boot'])->not->toBe($before);
})->with('modes');

it('answers 408 for a worker blocked in a call and serves the slot again', function (string $mode) {
    $server = nativeServer($mode, ['workers' => 1, 'octaneConfig' => ['max_execution_time' => 1]]);
    $server->worker();

    $started = microtime(true);
    $slept = $server->get('/sleep?s=10');
    $answeredIn = microtime(true) - $started;

    $at = microtime(true);
    $next = $server->worker();

    expect($slept['status'])->toBe(408)
        ->and($answeredIn)->toBeLessThan(3.0)
        ->and($next['pid'])->toBeInt()
        ->and(microtime(true) - $at)->toBeLessThan(3.0);
})->with('modes');

it('lets no request past max_execution_time finish its work', function (string $mode) {
    $server = nativeServer($mode, ['workers' => 1, 'octaneConfig' => ['max_execution_time' => 1]]);
    $server->worker();

    expect($server->get('/sleep-then-mark?s=3')['status'])->toBe(408);

    $server->pause(3.5); // past the sleep: a request still running would have written the marker now

    expect(is_file($server->root.'/storage/marked'))->toBeFalse();
})->with('modes');

it('answers the next request on an idle keep-alive connection through a reload, then closes it', function (string $mode) {
    // Swoole's master owns client connections, so a reload never closes one under a client. A native worker owns
    // its connections: closing an idle keep-alive at once races a request the client has just sent on it.
    $server = nativeServer($mode, ['workers' => 1]);
    $client = stream_socket_client("tcp://127.0.0.1:{$server->port}", $errno, $error, 2);
    stream_set_timeout($client, 5);
    $request = "GET /worker HTTP/1.1\r\nHost: localhost\r\n\r\n";

    fwrite($client, $request);
    $first = readHttpResponse($client);
    $server->signal(SIGUSR1);
    usleep(300_000); // the worker has the reload frame and drains

    fwrite($client, $request);
    $second = readHttpResponse($client);

    expect($first['status'])->toBe(200)
        ->and($second['status'])->toBe(200)
        ->and(strtolower($second['headers']['connection'] ?? ''))->toBe('close')
        ->and(json_decode($second['body'], true)['boot'])->toBe(json_decode($first['body'], true)['boot'])
        ->and(fread($client, 1))->toBe('')
        ->and(feof($client))->toBeTrue();
})->with('modes');

it('reloads every worker on SIGUSR1 while still answering', function (string $mode) {
    $server = nativeServer($mode, ['workers' => 2]);
    $before = bootTokens($server->hammer('/worker', 40));

    $server->signal(SIGUSR1);

    $during = $server->hammer('/worker', 200, 4);
    $deadline = microtime(true) + 10;

    do {
        $after = bootTokens($server->hammer('/worker', 40));
    } while (array_intersect($after, $before) !== [] && microtime(true) < $deadline);

    $server->signal(SIGTERM);

    expect(array_column($during, 'status'))->each->toBe(200)
        ->and(array_intersect($after, $before))->toBe([])
        ->and($server->waitForExit())->toBe(0); // the replacements still answer SIGTERM
})->with('modes');

it('keeps the running workers when a reload cannot boot the application', function (string $mode) {
    $server = nativeServer($mode, ['workers' => 2]);
    $before = bootTokens($server->hammer('/worker', 40));

    touch($server->root.'/storage/break-boot'); // the fixture refuses to boot while this exists
    $server->signal(SIGUSR1);
    $deadline = microtime(true) + 15;

    while (substr_count($server->output(), 'could not boot the reloaded application') < 2 && microtime(true) < $deadline) {
        $server->pause(0.05);
    }

    $during = $server->hammer('/worker', 40);

    unlink($server->root.'/storage/break-boot');
    $server->signal(SIGUSR1);
    $deadline = microtime(true) + 10;

    do {
        $after = bootTokens($server->hammer('/worker', 40));
    } while (array_intersect($after, $before) !== [] && microtime(true) < $deadline);

    expect(substr_count($server->output(), 'could not boot the reloaded application'))->toBe(2)
        ->and(array_column($during, 'status'))->each->toBe(200)
        ->and(array_diff(bootTokens($during), $before))->toBe([])
        ->and(array_intersect($after, $before))->toBe([]);
})->with('modes');

it('drains and exits cleanly on SIGTERM, removing its control socket', function (string $mode) {
    $server = nativeServer($mode);
    $server->worker();
    $socket = ControlSocket::path($server->root, $server->supervisorPid());
    $existed = file_exists($socket) ? filetype($socket) : false;

    $server->signal(SIGTERM);

    expect($server->waitForExit())->toBe(0)
        ->and($server->isListening())->toBeFalse()
        ->and($existed)->toBe('socket')
        ->and(file_exists($socket))->toBeFalse();
})->with('modes');

it('stops its workers when the supervisor is killed outright', function () {
    $server = nativeServer('processes', ['workers' => 2]);
    $server->worker();
    $supervisor = $server->supervisorPid();

    expect($supervisor)->toBeGreaterThan(0);

    posix_kill($supervisor, SIGKILL); // no stop frames: the workers see their control streams close
    $deadline = microtime(true) + 10;

    while ($server->isListening() && microtime(true) < $deadline) {
        $server->pause(0.05);
    }

    expect($server->isListening())->toBeFalse();
});

it('exits with an error when the application cannot boot', function (string $mode) {
    $server = nativeServer($mode, env: ['ROCKETSHIP_FIXTURE_BREAK_BOOT' => '1'], wait: false);

    expect($server->waitForExit(15))->toBe(1)
        ->and($server->output())->toContain('could not boot');
})->with('modes');

/** Start a request and leave it running: the worker stays inside it until it returns. */
function startRequest(NativeServerProcess $server, string $path)
{
    $client = stream_socket_client("tcp://127.0.0.1:{$server->port}", $errno, $error, 2);
    fwrite($client, "GET {$path} HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

    return $client;
}

it('exits on SIGTERM even when a worker thread is blocked inside a request', function () {
    $server = nativeServer('threads', ['workers' => 1, 'octaneConfig' => ['native' => ['drain_timeout' => 1]]]);
    $server->worker();
    $client = startRequest($server, '/sleep?s=60');
    $server->pause(0.3);

    $server->signal(SIGTERM);
    $started = microtime(true);
    $exit = $server->waitForExit(20);

    // A runtime kill waits for the blocked call to return; past the deadline the master ends itself.
    expect($exit)->not->toBeNull()
        ->and(microtime(true) - $started)->toBeLessThan(15)
        ->and($server->output())->toContain('Worker 0 did not stop');

    fclose($client);
});

it('kills a retired worker that outlives the drain deadline', function () {
    $server = nativeServer('processes', ['workers' => 1, 'octaneConfig' => ['native' => ['drain_timeout' => 1]]]);
    $old = $server->worker()['pid'];
    $client = startRequest($server, '/sleep?s=60');
    $server->pause(0.3);

    $server->signal(SIGUSR1);
    $deadline = microtime(true) + 12;

    while (posix_kill($old, 0) && microtime(true) < $deadline) {
        $server->pause(0.1);
    }

    expect(posix_kill($old, 0))->toBeFalse()
        ->and($server->worker()['pid'])->not->toBe($old);

    fclose($client);
});

it('keeps one replacement per slot in flight however many reloads arrive', function (string $mode) {
    $server = nativeServer($mode, ['workers' => 2]);
    $before = bootTokens($server->hammer('/worker', 40));
    $boots = fn (): int => count(file($server->root.'/storage/boots.log'));
    $initial = $boots();
    touch($server->root.'/storage/slow-boot'); // every reload below lands while the first replacements boot

    for ($i = 0; $i < 5; $i++) {
        $server->signal(SIGUSR1);
        $server->pause(0.05);
    }

    $deadline = microtime(true) + 15;

    do {
        $server->pause(0.2);
    } while ($boots() - $initial < 4 && microtime(true) < $deadline);

    $server->pause(2.5); // time for any further generation to show up
    $after = bootTokens($server->hammer('/worker', 40));

    // The first reload starts one replacement per slot; the four after it fold into one more each.
    expect($boots() - $initial)->toBe(4)
        ->and(array_intersect($after, $before))->toBe([]);
})->with('modes');

it('ignores strangers on the control socket', function (string $mode) {
    $server = nativeServer($mode, ['workers' => 1]);
    $before = $server->worker()['boot'];
    $path = ControlSocket::path($server->root, $server->supervisorPid());

    $flood = stream_socket_client("unix://{$path}");
    fwrite($flood, str_repeat('x', 4096)); // no newline, ever
    $silent = stream_socket_client("unix://{$path}");
    stream_set_blocking($flood, false);
    stream_set_blocking($silent, false);

    $server->signal(SIGUSR1);

    // Race the replacement to its hello: tokens are small integers anyone could guess.
    $forged = [];
    $until = microtime(true) + 1.5;
    set_error_handler(static fn (): bool => true); // a refused connect is part of the race, not an issue

    try {
        while (microtime(true) < $until && count($forged) < 300) {
            foreach ([2, 3] as $token) {
                if ($stream = stream_socket_client("unix://{$path}", $errno, $error, 0.1)) {
                    fwrite($stream, "hello {$token}\nready\n");
                    $forged[] = $stream;
                }
            }
        }
    } finally {
        restore_error_handler();
    }

    $deadline = microtime(true) + 10;

    do {
        $after = $server->worker()['boot'];
    } while ($after === $before && microtime(true) < $deadline);

    $closed = function ($stream): bool {
        $deadline = microtime(true) + 6;

        while (microtime(true) < $deadline) {
            $chunk = @fread($stream, 8192);

            if ($chunk === false || ($chunk === '' && feof($stream))) {
                return true;
            }

            usleep(50_000);
        }

        return false;
    };

    expect($after)->not->toBe($before) // the real replacement took over
        ->and($closed($flood))->toBeTrue()
        ->and($closed($silent))->toBeTrue();

    array_map(fclose(...), [...$forged, $flood, $silent]);
})->with('modes');

it('refuses a mode this PHP cannot run, naming what is missing', function () {
    $server = new NativeServerProcess(['mode' => 'fibers'], wait: false);
    test()->server = $server;

    expect($server->waitForExit())->toBe(1)
        ->and($server->output())->toContain('Unknown native mode [fibers]');
});


it('starts without APCu', function (string $mode) {
    if ($mode === 'threads' && (! PHP_ZTS || ! extension_loaded('parallel'))) {
        test()->markTestSkipped('threads mode needs a ZTS PHP with ext-parallel');
    }

    // Octane's default config declares tables and the cache; native must still start on a PHP without APCu.
    $server = test()->server = new NativeServerProcess(['mode' => $mode], without: ['apcu']);

    expect($server->worker()['pid'])->toBeInt();
})->with('modes');

it('loses no control frame while the master is not reading', function () {
    // max_execution_time on: every request adds busy and idle frames to the worker's control channel.
    $server = nativeServer('processes', ['workers' => 1, 'maxRequests' => 1000]);
    $before = $server->worker()['boot'];

    posix_kill($server->supervisorPid(), SIGSTOP); // a master starved of CPU reads nothing

    try {
        $server->hammer('/worker', 1100, 8); // the `spent` frame at request 1000 sits behind ~2000 busy/idle frames
    } finally {
        posix_kill($server->supervisorPid(), SIGCONT);
    }

    $deadline = microtime(true) + 15;

    do {
        $after = $server->worker()['boot'];
    } while ($after === $before && microtime(true) < $deadline);

    expect($after)->not->toBe($before); // the master saw `spent` and recycled the worker
});

it('closes the listener copy of a thread that could not boot', function () {
    $server = nativeServer('threads', ['workers' => 1]);
    $server->worker();
    $listening = fn (): int => (int) trim((string) shell_exec(sprintf(
        'lsof -nP -a -p %d -iTCP:%d -sTCP:LISTEN 2>/dev/null | tail -n +2 | wc -l', $server->supervisorPid(), $server->port,
    )));
    $before = $listening();

    touch($server->root.'/storage/break-boot'); // every replacement now fails to boot

    for ($i = 0; $i < 3; $i++) {
        $server->signal(SIGUSR1);
        $server->pause(1.5);
    }

    expect($server->worker()['pid'])->toBeInt() // the running worker still serves
        ->and($listening())->toBe($before);
});

it('ends a thread timed out inside a streamed response without a shutdown fatal', function () {
    $server = nativeServer('threads', ['workers' => 1, 'octaneConfig' => ['max_execution_time' => 1]]);
    $server->worker();

    // A client that stops reading: the worker waits for room inside the stream's output handler when it is cancelled.
    $client = stream_socket_client("tcp://127.0.0.1:{$server->port}");
    fwrite($client, "GET /stream-large HTTP/1.1\r\nHost: localhost\r\n\r\n");
    $server->pause(4.0); // timed out at 1 s; the cancelled thread shut down
    $server->worker();
    fclose($client);

    expect($server->output())->not->toContain('Fatal error')->not->toContain('output buffering');
});

it('keeps a threads-mode server asleep while it has no requests', function () {
    if (PHP_OS_FAMILY !== 'Linux') {
        test()->markTestSkipped('counts context switches from /proc, which is Linux only');
    }

    $server = nativeServer('threads', ['workers' => 2]);
    $server->worker();
    $switches = function () use ($server): int {
        $total = 0;

        foreach (glob("/proc/{$server->supervisorPid()}/task/*/status") ?: [] as $status) {
            preg_match_all('/^(?:non)?voluntary_ctxt_switches:\s+(\d+)/m', (string) @file_get_contents($status), $counts);
            $total += array_sum(array_map('intval', $counts[1]));
        }

        return $total;
    };

    $before = $switches();
    $server->pause(1.0);

    // Each worker's keeper waits for its worker or a command; a keeper that spins wakes tens of thousands of times.
    expect($switches() - $before)->toBeLessThan(1000);
});

it('runs concurrently() in the request until native has task workers, even with ext-swoole loaded', function () {
    if (! extension_loaded('swoole')) {
        test()->markTestSkipped('the broken path needs ext-swoole loaded');
    }

    // Processes mode runs beside Swoole; Octane's tasks() would pick its Swoole HTTP dispatcher there.
    $server = test()->server = new NativeServerProcess(['mode' => 'processes', 'workers' => 1], swoole: true);

    $server->worker();
    $started = microtime(true);
    $response = $server->get('/concurrently');

    // Octane's Swoole HTTP dispatcher would post to this server's own busy worker and fall back only after 8 s.
    expect($response['status'])->toBe(200)
        ->and(json_decode($response['body'], true))->toBe([1, 2])
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
});

it('keeps serving from healthy slots when a crashed worker cannot boot, and refills the slot once it can', function (string $mode) {
    $server = nativeServer($mode, ['workers' => 2]);
    expect(count(bootTokens($server->hammer('/worker', 30))))->toBe(2);

    touch($server->root.'/storage/break-boot');
    $server->get('/fatal'); // one worker dies; its replacement cannot boot
    $server->pause(2.0);

    // The healthy slot answers every new connection. On Linux each slot has its own socket and the kernel spreads
    // connections over the group: a broken slot's socket must leave it, or the connections it gets wait unanswered.
    expect($server->process->isRunning())->toBeTrue()
        ->and(array_unique(array_column($server->hammer('/worker', 20), 'status')))->toBe([200]);

    unlink($server->root.'/storage/break-boot');
    $deadline = microtime(true) + 10;

    do {
        $tokens = bootTokens($server->hammer('/worker', 30));
    } while (count($tokens) < 2 && microtime(true) < $deadline);

    expect(count($tokens))->toBe(2); // the slot came back
})->with('modes');

/**
 * Read one HTTP/1.1 response with a Content-Length body from a blocking socket.
 *
 * @param  resource  $socket
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function readHttpResponse($socket): array
{
    $head = '';

    while (! str_contains($head, "\r\n\r\n") && ($line = fgets($socket)) !== false) {
        $head .= $line;
    }

    if ($head === '') {
        return ['status' => 0, 'headers' => [], 'body' => ''];
    }

    $lines = explode("\r\n", trim($head));
    $headers = [];

    foreach (array_slice($lines, 1) as $line) {
        [$name, $value] = array_map(trim(...), explode(':', $line, 2)) + [1 => ''];
        $headers[strtolower($name)] = $value;
    }

    $length = (int) ($headers['content-length'] ?? 0);
    $body = '';

    while (strlen($body) < $length && ($chunk = fread($socket, $length - strlen($body))) !== false && $chunk !== '') {
        $body .= $chunk;
    }

    return ['status' => (int) explode(' ', $lines[0])[1], 'headers' => $headers, 'body' => $body];
}
