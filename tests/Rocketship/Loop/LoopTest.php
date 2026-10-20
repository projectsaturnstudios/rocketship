<?php

use ProjectSaturnStudios\Rocketship\Loop\Drivers\SelectDriver;
use ProjectSaturnStudios\Rocketship\Loop\Loop;

function selectLoop(): Loop
{
    return new Loop(new SelectDriver);
}

it('fires timers in deadline order', function () {
    $loop = selectLoop();
    $order = [];
    $loop->addTimer(0.02, function () use (&$order) { $order[] = 'second'; });
    $loop->addTimer(0.01, function () use (&$order, $loop) { $order[] = 'first'; });
    $loop->addTimer(0.03, function () use (&$order, $loop) { $order[] = 'third'; $loop->stop(); });

    $loop->run();

    expect($order)->toBe(['first', 'second', 'third']);
});

it('re-arms periodic timers and cancels them', function () {
    $loop = selectLoop();
    $count = 0;
    $timer = $loop->addPeriodicTimer(0.005, function () use (&$count) { $count++; });
    $loop->addTimer(0.03, function () use ($loop, $timer) { $loop->cancelTimer($timer); });
    $loop->addTimer(0.05, fn () => $loop->stop());

    $loop->run();

    expect($count)->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(7);
});

it('dispatches a readable stream and honours cancellation mid-dispatch', function () {
    $loop = selectLoop();
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $reads = 0;
    $loop->onReadable($a, function () use (&$reads, $loop, $a) {
        $reads++;
        fread($a, 1);
        $loop->cancelReadable($a);
    });
    fwrite($b, 'xy');

    $loop->tick(0.0);
    $loop->tick(0.0);

    expect($reads)->toBe(1);
});

it('dispatches writable once and then stops reporting it', function () {
    $loop = selectLoop();
    [$a] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $writes = 0;
    $loop->onWritable($a, function () use (&$writes, $loop, $a) {
        $writes++;
        $loop->cancelWritable($a);
    });

    $loop->tick(0.0);
    $loop->tick(0.0);

    expect($writes)->toBe(1);
});

it('tick with a timeout returns after that long when nothing happens', function () {
    $loop = selectLoop();
    $start = hrtime(true);

    $loop->tick(0.02);

    expect((hrtime(true) - $start) / 1e9)->toBeGreaterThanOrEqual(0.02)->toBeLessThan(0.2);
});

it('reports the driver name', function () {
    expect(selectLoop()->driver())->toBe('select');
});

it('returns from run() promptly when a timer calls stop(), without waiting for the next timer', function () {
    $loop = selectLoop();
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $loop->onReadable($a, fn () => null);
    $loop->addPeriodicTimer(2.0, fn () => null);
    $loop->addTimer(0.01, fn () => $loop->stop());
    $start = hrtime(true);

    $loop->run();

    expect((hrtime(true) - $start) / 1e9)->toBeLessThan(0.5);
});

it('fires a new timer even when it reuses the object id of a timer cancelled after firing', function () {
    $loop = selectLoop();
    $old = $loop->addTimer(0.0, fn () => null);
    $loop->tick(0.0);
    $loop->cancelTimer($old); // cancelling after it fired, as a finally() cleanup does
    $oldId = spl_object_id($old);
    $fired = false;
    $callback = function () use (&$fired) { $fired = true; };
    unset($old);

    $new = $loop->addTimer(0.0, $callback);
    expect(spl_object_id($new))->toBe($oldId); // PHP recycled the handle

    $loop->tick(0.0);

    expect($fired)->toBeTrue();
});

it('stops re-arming a periodic timer that cancels itself and leaves no residue', function () {
    $loop = selectLoop();
    $count = 0;
    $timer = null;
    $timer = $loop->addPeriodicTimer(0.0, function () use (&$count, &$timer, $loop) {
        $count++;
        $loop->cancelTimer($timer);
    });

    $loop->tick(0.0);
    $loop->tick(0.0);

    expect($count)->toBe(1);
});

it('leaves no trace of a watch the driver refused', function () {
    $loop = new Loop(new SelectDriver(maxDescriptors: 1));
    [$a, $a2] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    [$b, $b2] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $loop->onReadable($a, fn () => null);

    expect(fn () => $loop->onReadable($b, fn () => null))->toThrow(RuntimeException::class);

    // Capacity frees up; registering $b again must reach the driver this time.
    $loop->cancelReadable($a);
    $fired = false;
    $loop->onReadable($b, function () use (&$fired) { $fired = true; });
    fwrite($b2, 'x');
    $loop->tick(0.1);

    expect($fired)->toBeTrue();
});

it('runs future ticks on the next tick, in the order queued', function () {
    $loop = selectLoop();
    $order = [];
    $loop->futureTick(function () use (&$order) { $order[] = 'a'; });
    $loop->futureTick(function () use (&$order) { $order[] = 'b'; });

    expect($order)->toBe([]);

    $loop->tick(0.0);

    expect($order)->toBe(['a', 'b']);
});

it('runs a future tick queued by a future tick on the tick after', function () {
    $loop = selectLoop();
    $runs = 0;
    $loop->futureTick(function () use ($loop, &$runs) {
        $runs++;
        $loop->futureTick(function () use (&$runs) { $runs++; });
    });

    $loop->tick(0.0);
    expect($runs)->toBe(1);

    $loop->tick(0.0);
    expect($runs)->toBe(2);
});

it('does not wait on streams while a future tick is queued', function () {
    $loop = selectLoop();
    [$a] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $loop->onReadable($a, fn () => null); // never readable: a blocking wait would sit the whole timeout
    $loop->addTimer(0, fn () => $loop->futureTick(fn () => null));
    usleep(1000);

    $started = microtime(true);
    $loop->tick(2.0);

    expect(microtime(true) - $started)->toBeLessThan(0.5);
});

it('keeps run() going while a future tick is queued', function () {
    $loop = selectLoop();
    $ran = false;
    $loop->futureTick(function () use (&$ran, $loop) {
        $ran = true;
        $loop->stop();
    });

    $loop->run();

    expect($ran)->toBeTrue();
});
