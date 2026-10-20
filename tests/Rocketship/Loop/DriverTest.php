<?php

use ProjectSaturnStudios\Rocketship\Loop\DriverInterface;
use ProjectSaturnStudios\Rocketship\Loop\Drivers\EpollDriver;
use ProjectSaturnStudios\Rocketship\Loop\Drivers\KqueueDriver;
use ProjectSaturnStudios\Rocketship\Loop\Drivers\SelectDriver;

dataset('drivers', [
    'select' => [fn () => new SelectDriver],
    'epoll' => [fn () => function_exists('epoll_create1') ? new EpollDriver : null],
    'kqueue' => [fn () => function_exists('kqueue') ? new KqueueDriver : null],
]);

function readyMap(iterable $ready): array
{
    $map = [];
    foreach ($ready as [$fd, $mask]) {
        $map[$fd] = $mask;
    }

    return $map;
}

it('reports a stream readable after the peer writes', function (callable $make) {
    $driver = $make();
    if ($driver === null) {
        $this->markTestSkipped('driver not available on this platform');
    }
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $driver->watch((int) $a, $a, DriverInterface::READ);

    expect(readyMap($driver->wait(0.0)))->toBe([]);

    fwrite($b, 'x');

    expect(readyMap($driver->wait(1.0))[(int) $a] & DriverInterface::READ)->toBe(DriverInterface::READ);
})->with('drivers');

it('reports an idle socket writable and stops after unwatch', function (callable $make) {
    $driver = $make();
    if ($driver === null) {
        $this->markTestSkipped('driver not available on this platform');
    }
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $driver->watch((int) $a, $a, DriverInterface::WRITE);

    expect(readyMap($driver->wait(0.0))[(int) $a] & DriverInterface::WRITE)->toBe(DriverInterface::WRITE);

    $driver->unwatch((int) $a);

    expect(readyMap($driver->wait(0.0)))->toBe([]);
})->with('drivers');

it('switches masks with modify', function (callable $make) {
    $driver = $make();
    if ($driver === null) {
        $this->markTestSkipped('driver not available on this platform');
    }
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $driver->watch((int) $a, $a, DriverInterface::WRITE);
    $driver->modify((int) $a, DriverInterface::READ);

    expect(readyMap($driver->wait(0.0)))->toBe([]);

    fwrite($b, 'x');

    expect(readyMap($driver->wait(1.0))[(int) $a] & DriverInterface::READ)->toBe(DriverInterface::READ);
})->with('drivers');

it('reports a peer close as readable', function (callable $make) {
    $driver = $make();
    if ($driver === null) {
        $this->markTestSkipped('driver not available on this platform');
    }
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $driver->watch((int) $a, $a, DriverInterface::READ);
    fclose($b);

    expect(readyMap($driver->wait(1.0))[(int) $a] & DriverInterface::READ)->toBe(DriverInterface::READ);
    expect(fread($a, 1))->toBe('');
    expect(feof($a))->toBeTrue();
})->with('drivers');

it('times out and returns nothing', function (callable $make) {
    $driver = $make();
    if ($driver === null) {
        $this->markTestSkipped('driver not available on this platform');
    }
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    $driver->watch((int) $a, $a, DriverInterface::READ);
    $start = hrtime(true);

    expect(readyMap($driver->wait(0.02)))->toBe([]);
    expect((hrtime(true) - $start) / 1e9)->toBeGreaterThanOrEqual(0.02);
})->with('drivers');

/**
 * Run $test with @-suppressed warnings swallowed. These tests trip stream_select's FD_SETSIZE warning on
 * purpose; the driver suppresses it, but PHPUnit's handler still records suppressed warnings. Unsuppressed
 * ones fall through to PHPUnit. The held pairs are closed afterwards so later tests start below the ceiling.
 */
function pastTheCeiling(callable $test): void
{
    set_error_handler(static fn (int $level): bool => (error_reporting() & $level) === 0, E_WARNING);

    try {
        $held = descriptorsUpTo(SelectDriver::MAX_DESCRIPTORS);
        $test($held);
    } finally {
        restore_error_handler();

        foreach ($held ?? [] as [$a, $b]) {
            fclose($a);
            fclose($b);
        }
    }
}

/** Socket pairs held open, unwatched, until the next descriptor the process opens is numbered at least $number. */
function descriptorsUpTo(int $number): array
{
    $limit = function_exists('posix_getrlimit') ? (posix_getrlimit()['soft openfiles'] ?? 0) : 0;
    if ($limit !== 'unlimited' && (int) $limit < $number + 64) {
        test()->markTestSkipped("needs ulimit -n >= ".($number + 64).", have {$limit}");
    }

    $held = [];
    do {
        $held[] = $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $probe = [$pair[1]];
        $none = null;
    } while (@stream_select($probe, $none, $none, 0) !== false);

    return $held;
}

it('select refuses a descriptor numbered past FD_SETSIZE, however few it watches', function () {
    pastTheCeiling(function (array $held) {
        $driver = new SelectDriver;
        [$low, $lowPeer] = $held[0];
        $high = end($held)[1];

        expect(fn () => $driver->watch((int) $high, $high, DriverInterface::READ))
            ->toThrow(RuntimeException::class, 'numbered past');

        // The refusal leaves the driver serving what it already watches.
        $driver->watch((int) $low, $low, DriverInterface::READ);
        fwrite($lowPeer, 'x');

        expect(iterator_to_array($driver->wait(0.0), false))->toBe([[(int) $low, DriverInterface::READ]]);
    });
});

it('select throws instead of spinning when stream_select itself fails', function () {
    pastTheCeiling(function (array $held) {
        $driver = new SelectDriver;
        $high = end($held)[1];
        // A descriptor that got past watch() some other way: wait() must not report "nothing ready" forever.
        (new ReflectionProperty($driver, 'read'))->setValue($driver, [(int) $high => $high]);

        expect(fn () => iterator_to_array($driver->wait(0.0)))->toThrow(RuntimeException::class, 'stream_select failed');
    });
});

it('select counts a descriptor watched both ways once against the ceiling', function () {
    $driver = new SelectDriver(maxDescriptors: 2);
    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    [$c, $d] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

    $driver->watch((int) $a, $a, DriverInterface::READ | DriverInterface::WRITE);
    $driver->watch((int) $c, $c, DriverInterface::READ);

    expect(true)->toBeTrue();
});
