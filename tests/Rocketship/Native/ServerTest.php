<?php

declare(strict_types=1);

use ProjectSaturnStudios\Rocketship\Loop\Loop;
use ProjectSaturnStudios\Rocketship\Native\Connection;
use ProjectSaturnStudios\Rocketship\Native\Http\ParsedRequest;
use ProjectSaturnStudios\Rocketship\Native\ResponseWriter;
use ProjectSaturnStudios\Rocketship\Native\Server;
use ProjectSaturnStudios\Rocketship\Native\ServerOptions;
use Symfony\Component\HttpFoundation\Response;

/** @var list<int> PIDs of the server children the current test forked. */
$GLOBALS['rocketshipServerPids'] = [];

/**
 * Run one Server in a forked child. Its handler answers with the request path, or "ok" for "/".
 *
 * @return array{int, int} the child's PID and the port it listens on
 */
function forkServer(ServerOptions $options = new ServerOptions): array
{
    $listener = Server::listen('127.0.0.1', 0);
    $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);

    $pid = pcntl_fork();

    if ($pid === 0) {
        $writer = new ResponseWriter;
        $handler = function (ParsedRequest $request, Connection $connection) use ($writer): void {
            $writer->write($connection, $request, new Response(ltrim($request->path, '/') ?: 'ok'));
        };

        try {
            (new Server(Loop::create(), $listener, $handler, $options))->run();
        } catch (Throwable $e) {
            fwrite(STDERR, $e.PHP_EOL);
        } finally {
            // Never return into the test runner, and skip its shutdown handlers.
            posix_kill(posix_getpid(), SIGKILL);
        }
    }

    fclose($listener);
    $GLOBALS['rocketshipServerPids'][] = $pid;

    return [$pid, $port];
}

/** @return resource */
function clientTo(int $port, string $scheme = 'tcp', array $ssl = [])
{
    $context = stream_context_create(['ssl' => $ssl + ['verify_peer' => false, 'verify_peer_name' => false]]);
    $socket = stream_socket_client("{$scheme}://127.0.0.1:{$port}", $errno, $error, 2, STREAM_CLIENT_CONNECT, $context);
    stream_set_timeout($socket, 3);

    return $socket;
}

/** Read until $count responses arrived or the socket closed. */
function readResponses($socket, int $count = 1): string
{
    $data = '';

    while (! feof($socket) && substr_count($data, 'HTTP/1.1 ') - substr_count($data, 'HTTP/1.1 100 Continue') < $count) {
        $chunk = fread($socket, 65536);

        if ($chunk === '' || $chunk === false) {
            if (stream_get_meta_data($socket)['timed_out']) {
                break;
            }

            continue;
        }

        $data .= $chunk;
    }

    // Take the body of the last response too.
    usleep(20_000);
    stream_set_blocking($socket, false);
    $data .= (string) fread($socket, 65536);
    stream_set_blocking($socket, true);

    return $data;
}

afterEach(function () {
    foreach ($GLOBALS['rocketshipServerPids'] as $pid) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }

    $GLOBALS['rocketshipServerPids'] = [];
});

it('answers a request and keeps the connection open', function () {
    [, $port] = forkServer();
    $socket = clientTo($port);

    fwrite($socket, "GET /one HTTP/1.1\r\nHost: h\r\n\r\n");
    $first = readResponses($socket);
    fwrite($socket, "GET /two HTTP/1.1\r\nHost: h\r\n\r\n");
    $second = readResponses($socket);

    expect($first)->toStartWith('HTTP/1.1 200')->toEndWith('one')
        ->and($second)->toStartWith('HTTP/1.1 200')->toEndWith('two');
});

it('answers pipelined requests in order', function () {
    [, $port] = forkServer();
    $socket = clientTo($port);

    fwrite($socket, "GET /a HTTP/1.1\r\nHost: h\r\n\r\nGET /b HTTP/1.1\r\nHost: h\r\n\r\n");
    $both = readResponses($socket, 2);

    expect(strpos($both, "\r\n\r\na"))->toBeLessThan(strpos($both, "\r\n\r\nb"))
        ->and(substr_count($both, 'HTTP/1.1 200'))->toBe(2);
});

it('closes an idle keep-alive connection after keepalive_timeout', function () {
    [, $port] = forkServer(new ServerOptions(keepAliveTimeout: 0.2));
    $socket = clientTo($port);

    fwrite($socket, "GET / HTTP/1.1\r\nHost: h\r\n\r\n");
    readResponses($socket);
    $started = microtime(true);

    while (! feof($socket) && microtime(true) - $started < 3) {
        fread($socket, 1024);
    }

    expect(feof($socket))->toBeTrue()
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
});

it('answers 408 when the head is not complete in time', function () {
    [, $port] = forkServer(new ServerOptions(headerTimeout: 0.2));
    $slow = clientTo($port);
    fwrite($slow, "GET / HTTP/1.1\r\n");

    $other = clientTo($port);
    fwrite($other, "GET / HTTP/1.1\r\nHost: h\r\n\r\n");

    expect(readResponses($other))->toStartWith('HTTP/1.1 200')
        ->and(readResponses($slow))->toStartWith('HTTP/1.1 408');
});

it('answers parser errors with their status', function () {
    [, $port] = forkServer();
    $socket = clientTo($port);

    fwrite($socket, 'GET /'.str_repeat('a', 9 * 1024)." HTTP/1.1\r\nHost: h\r\n\r\n");

    expect(readResponses($socket))->toStartWith('HTTP/1.1 414');

    while (! feof($socket) && fread($socket, 1024) !== false) {
        // Read to EOF: the server closes after an error.
    }

    expect(feof($socket))->toBeTrue();
});

it('sends 100 Continue before reading the body', function () {
    [, $port] = forkServer();
    $socket = clientTo($port);

    fwrite($socket, "POST /posted HTTP/1.1\r\nHost: h\r\nContent-Length: 5\r\nExpect: 100-continue\r\n\r\n");
    $interim = fread($socket, 1024);
    fwrite($socket, 'hello');

    expect($interim)->toStartWith('HTTP/1.1 100 Continue')
        ->and(readResponses($socket))->toContain('HTTP/1.1 200')->toEndWith('posted');
});

it('serves TLS on a plain listener', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => '127.0.0.1'], $key), null, $key, 1);
    $pem = tempnam(sys_get_temp_dir(), 'rocketship-tls');
    openssl_x509_export($certificate, $cert);
    openssl_pkey_export($key, $privateKey);
    file_put_contents($pem, $cert.$privateKey);

    try {
        [, $port] = forkServer(new ServerOptions(tls: ['local_cert' => $pem]));
        $socket = clientTo($port, 'tls');

        fwrite($socket, "GET /secure HTTP/1.1\r\nHost: h\r\n\r\n");

        expect(readResponses($socket))->toStartWith('HTTP/1.1 200')->toEndWith('secure');
    } finally {
        unlink($pem);
    }
});

it('answers 408 at header_timeout even when keepalive_timeout is shorter, as with the defaults', function () {
    // The defaults: keep-alive 5 s, head 30 s. Half a head is not an idle connection.
    [, $port] = forkServer(new ServerOptions(keepAliveTimeout: 0.3, headerTimeout: 3.0)); // sweeps run once a second
    $slow = clientTo($port);
    stream_set_timeout($slow, 6); // the 408 comes between 3 and 4 s
    fwrite($slow, "GET / HTTP/1.1\r\n");

    $started = microtime(true);
    $answer = readResponses($slow);

    expect($answer)->toStartWith('HTTP/1.1 408')
        ->and(microtime(true) - $started)->toBeGreaterThan(2.5);
});

it('answers a timed-out thread\'s client without blocking on a full send buffer', function () {
    $listener = Server::listen('127.0.0.1', 0);
    $client = stream_socket_client('tcp://'.stream_socket_get_name($listener, false));
    $accepted = stream_socket_accept($listener);
    stream_set_blocking($accepted, false);

    // The client reads nothing: fill the send buffer to the last byte.
    while (@fwrite($accepted, str_repeat('x', 65536)) > 0) {
        //
    }

    while (@fwrite($accepted, 'x') > 0) {
        //
    }

    // A blocking 408 write would wait for the client to read; the alarm interrupts it.
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, fn () => null, false);
    pcntl_alarm(2);
    $started = microtime(true);
    Connection::answerTimedOut(posix_fileno($accepted), false, stream_socket_get_name($accepted, true));
    $took = microtime(true) - $started;
    pcntl_alarm(0);

    expect($took)->toBeLessThan(1.0);

    fclose($client);
    fclose($listener);
});
