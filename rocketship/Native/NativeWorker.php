<?php

namespace ProjectSaturnStudios\Rocketship\Native;

use Closure;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\DispatchesTasks;
use Laravel\Octane\SequentialTaskDispatcher;
use ProjectSaturnStudios\Rocketship\Loop\Loop;
use ProjectSaturnStudios\Rocketship\Native\Control\ControlStream;
use ProjectSaturnStudios\Rocketship\Native\Http\ParserLimits;
use Laravel\Octane\Stream;
use Laravel\Octane\Worker;
use Throwable;

/**
 * One native worker: boots the app once, serves on its listener with its own loop until it drains.
 * Runs as a forked child (processes mode) or inside a parallel\Runtime (threads mode).
 */
final class NativeWorker
{
    public const DRAINED = 'drained';

    public const BOOT_FAILED = 'boot-failed';

    /**
     * Connect to the supervisor, boot, serve until drained. The supervisor's `stop` and `reload` frames, or its
     * connection closing (killed outright), drain this worker between requests.
     *
     * @param  array<string, mixed>  $state  the server state the start command wrote, plus the control socket path
     * @param  Closure(): resource  $listen  this worker's handle on the supervisor's listener for its slot
     * @param  bool  $threaded  a worker thread: per-request time limits only where PHP keeps them per thread
     */
    public static function serve(array $state, int $slot, int $token, Closure $listen, bool $threaded): string
    {
        $control = ControlStream::connect($state['control']);

        if ($control === null) {
            fwrite(STDERR, "Worker {$slot} could not reach its supervisor.".PHP_EOL);

            return self::BOOT_FAILED;
        }

        $control->send("hello {$token} {$state['controlSecret']}");

        $_SERVER['ROCKETSHIP_WORKER_ID'] = $_ENV['ROCKETSHIP_WORKER_ID'] = $slot;
        $config = $state['octaneConfig'] ?? [];
        $client = new NativeClient;

        try {
            $worker = new Worker(new ApplicationFactory($state['basePath']), $client);
            $worker->boot();

            // No task workers until Part 4: concurrently() runs in the request, as on RoadRunner and FrankenPHP.
            // Without this, a PHP that loads ext-swoole makes Octane post the tasks to this server's own workers.
            $worker->application()->bindIf(DispatchesTasks::class, SequentialTaskDispatcher::class);
            $listener = $listen();
        } catch (Throwable $e) {
            Stream::shutdown($e);
            $control->send('failed');
            $control->close();

            return self::BOOT_FAILED;
        }

        // max_execution_time is the master's to enforce: it hears when each request starts and ends. A thread names
        // its socket so the master can answer 408 itself (same process); a process answers from its SIGUSR2 handler.
        $timeLimit = (int) ($config['max_execution_time'] ?? 0);
        $report = static function (?Connection $connection) use ($control, $threaded): void {
            $control->send(match (true) {
                $connection === null => 'idle',
                $threaded => sprintf('busy %d %d %s', $connection->fd(), (int) $connection->isSecure(), $connection->remoteAddress),
                default => 'busy',
            });
        };

        $handler = new WorkerRequestHandler($worker, $client, $state['publicPath'], $config, $timeLimit, $report);

        if (! $threaded && $timeLimit > 0) {
            // Not restarted after the handler, so a blocking call (sleep, a socket read) gives way to it at once.
            pcntl_async_signals(true);
            pcntl_signal(SIGUSR2, static function () use ($handler): void {
                $handler->timeOut();
                posix_kill(posix_getpid(), SIGKILL); // run nothing more of the request
            }, false);
        }
        $loop = Loop::create();
        $server = new Server($loop, $listener, $handler(...), self::options($state), static fn () => $control->send('spent'));

        $control->attach($loop); // frames the master was too busy to take go out when it reads again
        $loop->onReadable($control->stream, static function () use ($control, $server, $loop): void {
            $frames = $control->receive();

            if ($frames === null) {
                $loop->cancelReadable($control->stream);
            }

            if ($frames === null || array_intersect($frames, ['stop', 'reload']) !== []) {
                $server->drain();
            }
        });

        $server->start();
        $control->send('ready');

        try {
            $loop->run();
        } finally {
            $worker->terminate();
            $control->close();
        }

        return self::DRAINED;
    }

    /**
     * Entry point inside a worker thread.
     *
     * @param  array<string, mixed>  $state
     * @param  int  $listenerFd  a duplicate of the supervisor's listener for this slot, which this thread owns
     */
    public static function runThread(array $state, int $slot, int $token, int $listenerFd): string
    {
        // A parallel runtime is a fresh request without the CLI's standard stream constants.
        defined('STDOUT') || define('STDOUT', fopen('php://stdout', 'w'));
        defined('STDERR') || define('STDERR', fopen('php://stderr', 'w'));

        // The supervisor's signals are already blocked here: a thread inherits the mask it was created under
        // (ThreadSpawner::spawn). Calling pcntl_sigprocmask from inside the runtime loses the supervisor's
        // SIGTERM on macOS, so the mask is set only at creation.

        // Cancelled inside a stream's output handler, the thread's shutdown would report "Cannot use output buffering
        // in output buffering display handlers": the cancel's own trace, not an error. Every other error still shows.
        register_shutdown_function(static function (): void {
            if (ResponseWriter::$inOutputHandler) {
                error_reporting(0);
            }
        });

        $wrapped = false;

        try {
            return self::serve($state, $slot, $token, static function () use ($listenerFd, &$wrapped) {
                $wrapped = true;

                return posix_socket_fdopen($listenerFd);
            }, true);
        } finally {
            // The master duplicated the listener for this thread. A thread that never got to wrap it (no supervisor
            // reached, boot failed) closes it here, or the copy keeps the port listening after the master closes it.
            if (! $wrapped && is_resource($stream = @posix_socket_fdopen($listenerFd))) {
                fclose($stream);
            }
        }
    }

    /** @param array<string, mixed> $state */
    public static function options(array $state): ServerOptions
    {
        $native = $state['octaneConfig']['native'] ?? [];

        return new ServerOptions(
            keepAliveTimeout: (float) ($native['keepalive_timeout'] ?? 5),
            tls: isset($state['tls']['cert'])
                ? array_filter(['local_cert' => $state['tls']['cert'], 'local_pk' => $state['tls']['key'] ?? null])
                : null,
            limits: new ParserLimits(maxBody: (int) ($native['max_body'] ?? 10_485_760)),
            maxRequests: (int) ($state['maxRequests'] ?? 0),
            drainTimeout: (float) ($native['drain_timeout'] ?? 30),
            headerTimeout: (float) ($native['header_timeout'] ?? 30),
        );
    }
}
