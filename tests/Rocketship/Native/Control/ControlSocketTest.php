<?php

declare(strict_types=1);

use ProjectSaturnStudios\Rocketship\Native\Control\ControlSocket;
use ProjectSaturnStudios\Rocketship\Native\Control\ControlStream;

beforeEach(function () {
    $this->scratch = sys_get_temp_dir().'/rs-ctl-'.bin2hex(random_bytes(3));
    mkdir($this->scratch);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->scratch));
});

it('puts the socket in storage/rocketship named for the supervisor pid', function () {
    expect(ControlSocket::path('/srv/app', 4242))->toBe('/srv/app/storage/rocketship/control-4242.sock');
});

it('moves the socket to the temp dir when the app path would overflow sun_path', function () {
    $deep = '/srv/'.str_repeat('nested/', 16).'app';

    $path = ControlSocket::path($deep, 4242, '/var/tmp');

    expect($path)->toStartWith('/var/tmp/rocketship-')
        ->toEndWith('-4242.sock')
        ->and(strlen($path))->toBeLessThanOrEqual(ControlSocket::maxPathLength())
        ->and(ControlSocket::path($deep, 4242, '/var/tmp'))->toBe($path) // stable per app
        ->and(ControlSocket::path($deep.'2', 4242, '/var/tmp'))->not->toBe($path); // distinct per app
});

it('falls back to /tmp when the temp dir is too deep as well', function () {
    $deep = '/srv/'.str_repeat('nested/', 16).'app';

    expect(ControlSocket::path($deep, 4242, '/'.str_repeat('t', 120)))->toStartWith('/tmp/rocketship-');
});

it('listens owner-only, creating the directory and replacing a stale socket file', function () {
    $path = $this->scratch.'/storage/rocketship/control-1.sock';
    mkdir(dirname($path), recursive: true);
    touch($path); // left by a supervisor that died with this pid

    $listener = ControlSocket::listen($path);
    clearstatcache();

    expect(filetype($path))->toBe('socket')
        ->and(fileperms($path) & 0777)->toBe(0600);

    fclose($listener);
});

it('carries newline frames both ways and reports the peer closing', function () {
    $path = $this->scratch.'/c.sock';
    $listener = ControlSocket::listen($path);

    $worker = ControlStream::connect($path);
    $master = new ControlStream(stream_socket_accept($listener, 1));

    $worker->send('hello 7');
    $worker->send('ready');
    usleep(10_000);
    $first = $master->receive();

    fwrite($worker->stream, 'rel'); // a frame split across reads waits for its newline
    usleep(10_000);
    $partial = $master->receive();
    fwrite($worker->stream, "oad\n");
    usleep(10_000);
    $rest = $master->receive();

    $worker->close();
    usleep(10_000);

    expect($first)->toBe(['hello 7', 'ready'])
        ->and($partial)->toBe([])
        ->and($rest)->toBe(['reload'])
        ->and($master->receive())->toBeNull();

    $master->close();
    fclose($listener);
});

it('returns null when nothing listens at the path', function () {
    expect(ControlStream::connect($this->scratch.'/nobody.sock', timeout: 0.3))->toBeNull();
});

it('removes sockets left by this app\'s dead supervisors and keeps live ones', function () {
    $app = $this->scratch.'/app';
    $temp = $this->scratch.'/tmp';
    mkdir($app.'/storage/rocketship', recursive: true);
    mkdir($temp);

    $exited = proc_open([PHP_BINARY, '-r', ''], [], $pipes);
    $dead = proc_get_status($exited)['pid'];
    while (proc_get_status($exited)['running']) {
        usleep(1000);
    }
    proc_close($exited);

    $hash = substr(hash('xxh3', $app), 0, 8);
    $stale = [$app."/storage/rocketship/control-{$dead}.sock", $temp."/rocketship-{$hash}-{$dead}.sock"];
    $live = [$app.'/storage/rocketship/control-'.getmypid().'.sock', $temp."/rocketship-{$hash}-".getmypid().'.sock'];
    $otherApp = $temp."/rocketship-00000000-{$dead}.sock";

    foreach ([...$stale, ...$live, $otherApp] as $file) {
        touch($file);
    }

    ControlSocket::removeStale($app, $temp);

    expect(array_filter($stale, 'file_exists'))->toBe([])
        ->and(array_filter($live, 'file_exists'))->toBe($live)
        ->and(file_exists($otherApp))->toBeTrue();
});

it('gives up on a peer that sends past the frame limit without a newline', function () {
    $path = $this->scratch.'/f.sock';
    $listener = ControlSocket::listen($path);
    $peer = ControlStream::connect($path);
    $master = new ControlStream(stream_socket_accept($listener, 1));

    fwrite($peer->stream, str_repeat('x', ControlStream::MAX_FRAME + 1));
    usleep(10_000);

    expect($master->receive())->toBeNull();

    $peer->close();
    $master->close();
    fclose($listener);
});

it('keeps trying to connect until the timeout while nothing accepts yet', function () {
    $path = $this->scratch.'/late.sock';
    $listen = new Symfony\Component\Process\Process([PHP_BINARY, '-r', '
        require $argv[1];
        usleep(400_000); // the supervisor is not listening yet
        $listener = ProjectSaturnStudios\Rocketship\Native\Control\ControlSocket::listen($argv[2]);
        $peer = stream_socket_accept($listener, 5);
        stream_set_blocking($peer, true); // on macOS an accepted socket inherits non-blocking mode from the listener
        echo fgets($peer);
    ', dirname(__DIR__, 4).'/vendor/autoload.php', $path]);
    $listen->start();

    $stream = ControlStream::connect($path, timeout: 5.0);
    $stream?->send('hello 1 secret');
    $listen->wait();

    expect($stream)->not->toBeNull()
        ->and(trim($listen->getOutput()))->toBe('hello 1 secret');
});
