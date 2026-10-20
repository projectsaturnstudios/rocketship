<?php

namespace ProjectSaturnStudios\Rocketship\Native;

use Closure;
use RuntimeException;

enum WorkerMode: string
{
    /** One parallel\Runtime per worker inside the server process; needs a ZTS PHP with ext-parallel. */
    case Threads = 'threads';

    /** One forked child per worker; needs ext-pcntl. */
    case Processes = 'processes';

    /**
     * `native.mode`: threads, processes, or auto (threads when this PHP can run them, else processes).
     * Threads receive the supervisor's listener through ext-posi's posix_socket_fdopen().
     */
    public static function resolve(string $configured): self
    {
        return match ($configured) {
            'threads' => self::threadsUnavailable() === null
                ? self::Threads
                : throw new RuntimeException('Native threads mode needs '.self::threadsUnavailable().'.'),
            'processes' => self::processesAvailable()
                ? self::Processes
                : throw new RuntimeException('Native processes mode needs ext-pcntl and ext-posix.'),
            'auto' => match (true) {
                self::threadsUnavailable() === null => self::Threads,
                self::processesAvailable() => self::Processes,
                default => throw new RuntimeException('The native server needs '.self::threadsUnavailable().', or ext-pcntl and ext-posix.'),
            },
            default => throw new RuntimeException("Unknown native mode [{$configured}]; use threads, processes or auto."),
        };
    }

    /**
     * What this PHP lacks to run worker threads, or null when it can.
     *
     * @param  ?Closure(string): bool  $exists  function_exists() unless a test stands in for it
     */
    public static function threadsUnavailable(?Closure $exists = null): ?string
    {
        $exists ??= function_exists(...);

        return match (true) {
            ! PHP_ZTS || ! extension_loaded('parallel') => 'a thread-safe (ZTS) PHP with ext-parallel',
            ! $exists('pcntl_sigprocmask') || ! $exists('pcntl_async_signals') || ! $exists('posix_kill') => 'ext-pcntl and ext-posix to supervise worker threads',
            extension_loaded('swoole') => 'ext-parallel without ext-swoole loaded (a runtime crashes the process beside Swoole)',
            ! $exists('posix_socket_fdopen') => 'ext-posi 0.10.6 or later to hand the listener to worker threads',
            default => null,
        };
    }

    public static function processesAvailable(): bool
    {
        return function_exists('pcntl_fork') && function_exists('posix_kill');
    }

    /**
     * Linux spreads connections across sockets bound with SO_REUSEPORT, so each worker binds its own.
     * macOS hands every connection to the last binder (measured 2026-10-14: 0/0/0/400), so workers
     * share one listening socket there and race to accept.
     */
    public static function kernelBalancesListeners(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }
}
