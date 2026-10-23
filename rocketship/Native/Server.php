<?php

namespace ProjectSaturnStudios\Rocketship\Native;

use Closure;
use ProjectSaturnStudios\Rocketship\Loop\LoopInterface;
use ProjectSaturnStudios\Rocketship\Loop\Timer;
use ProjectSaturnStudios\Rocketship\Native\Http\ParsedRequest;
use ProjectSaturnStudios\Rocketship\Native\Http\PhpHttpParser;
use RuntimeException;

/**
 * One worker's HTTP server: accepts on its listener, owns its connections, recycles itself after
 * max_requests, drains on request. Several workers may accept on the same listening socket.
 */
final class Server
{
    /** Listen backlog, as nginx and React use; PHP's default of 32 refuses a burst. */
    public const BACKLOG = 511;

    /** Seconds the listener goes unwatched after an accept fails for lack of descriptors. */
    private const ACCEPT_BACKOFF = 0.1;

    /** @var array<int, Connection> */
    private array $connections = [];

    private bool $draining = false;

    private int $served = 0;

    private bool $spent = false;

    private ?Timer $sweep = null;

    private ?Timer $drainDeadline = null;

    /**
     * @param  resource  $listener  a listening socket; this server closes its own handle when it drains
     * @param  Closure(ParsedRequest, Connection): void  $onRequest
     * @param  ?(Closure(): void)  $onSpent  called once max_requests is reached, instead of draining
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private $listener,
        private readonly Closure $onRequest,
        private readonly ServerOptions $options = new ServerOptions,
        private readonly ?Closure $onSpent = null,
    ) {
    }

    /**
     * Listen on host:port. With $reusePort every worker binds its own socket on the same port and the
     * kernel balances connections across them (Linux SO_REUSEPORT). Without $listen the socket is only bound:
     * it takes no connections, and is no member of its port's SO_REUSEPORT group, until posix_listen().
     *
     * @return resource
     */
    public static function listen(string $host, int $port, bool $reusePort = false, bool $listen = true)
    {
        $context = stream_context_create(['socket' => [
            'backlog' => self::BACKLOG,
            'so_reuseport' => $reusePort,
            'tcp_nodelay' => true,
        ]]);

        $address = str_contains($host, ':') && ! str_starts_with($host, '[') ? "[{$host}]" : $host;
        $flags = $listen ? STREAM_SERVER_BIND | STREAM_SERVER_LISTEN : STREAM_SERVER_BIND;
        $listener = @stream_socket_server("tcp://{$address}:{$port}", $errno, $error, $flags, $context);

        if ($listener === false) {
            throw new RuntimeException("Could not listen on {$host}:{$port}: {$error} ({$errno})");
        }

        stream_set_blocking($listener, false);

        return $listener;
    }

    public function start(): void
    {
        stream_set_blocking($this->listener, false);
        $this->loop->onReadable($this->listener, $this->accept(...));
        $this->sweep = $this->loop->addPeriodicTimer(1.0, $this->sweepConnections(...));
    }

    /** Start and run the loop until the server drains. */
    public function run(): void
    {
        $this->start();
        $this->loop->run();
    }

    /** host:port of the listener; with port 0 this is the port the kernel picked. */
    public function address(): string
    {
        return (string) stream_socket_get_name($this->listener, false);
    }

    public function loop(): LoopInterface
    {
        return $this->loop;
    }

    public function served(): int
    {
        return $this->served;
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }

    /** @return array<int, Connection> */
    public function connections(): array
    {
        return $this->connections;
    }

    /**
     * Stop accepting, let in-flight requests finish, then stop the loop. Connections still busy after
     * drainTimeout are closed.
     */
    /** Stop accepting and finish every connection; $keepIdle on a reload, see Connection::drain(). */
    public function drain(bool $keepIdle = false): void
    {
        if ($this->draining) {
            return;
        }

        if (is_resource($this->listener)) {
            // Take what already sits in the accept queue first, so a stopping server serves it. On a reload the
            // replacement serves the same socket (the supervisor holds it), so nothing queued is lost either way.
            $this->accept();
            $this->loop->cancelReadable($this->listener);
            fclose($this->listener);
        }

        $this->draining = true;

        foreach ($this->connections as $connection) {
            $connection->drain($keepIdle);
        }

        $this->drainDeadline = $this->loop->addTimer($this->options->drainTimeout, function (): void {
            foreach ($this->connections as $connection) {
                $connection->terminate();
            }
        });

        $this->stopWhenDrained();
    }

    private function accept(): void
    {
        // Another worker sharing the listener may win the race: accept fails with EAGAIN and we go back to the loop.
        while (! $this->draining && is_resource($this->listener) && ($socket = $this->acceptOne($peer)) !== false) {
            stream_set_blocking($socket, false);

            try {
                $connection = new Connection(
                    $socket,
                    $this->loop,
                    new PhpHttpParser($this->options->limits),
                    $this->serve(...),
                    $this->forget(...),
                    $this->options,
                    (string) $peer,
                    (string) stream_socket_get_name($socket, false),
                );
            } catch (RuntimeException) {
                // The loop cannot take another descriptor (select's ceiling): refuse this one, keep serving.
                fclose($socket);

                continue;
            }

            $this->connections[$connection->id()] = $connection;
        }
    }

    /**
     * One non-blocking accept. Out of descriptors, Linux leaves the connection queued and the listener readable,
     * so a level-triggered loop would spin on it: stop watching the listener for a moment instead.
     *
     * @return resource|false
     */
    private function acceptOne(?string &$peer)
    {
        $error = '';
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $socket = stream_socket_accept($this->listener, 0, $peer);
        } finally {
            restore_error_handler();
        }

        if ($socket === false && str_contains($error, 'Too many open files')) {
            $this->loop->cancelReadable($this->listener);
            $this->loop->addTimer(self::ACCEPT_BACKOFF, function (): void {
                if (! $this->draining && is_resource($this->listener)) {
                    $this->loop->onReadable($this->listener, $this->accept(...));
                }
            });
        }

        return $socket;
    }

    private function serve(ParsedRequest $request, Connection $connection): void
    {
        $this->served++;

        if (! $this->spent && $this->options->maxRequests > 0 && $this->served >= $this->options->maxRequests) {
            $this->spent = true;

            if ($this->onSpent !== null) {
                // The supervisor starts a replacement and drains this worker once it serves: no gap on any listener.
                ($this->onSpent)();
            } else {
                // On its own: answer this request with Connection: close, then drain.
                $connection->closeAfterResponse();
                $this->loop->futureTick($this->drain(...));
            }
        }

        ($this->onRequest)($request, $connection);
    }

    private function forget(Connection $connection): void
    {
        unset($this->connections[$connection->id()]);

        if ($this->draining) {
            $this->stopWhenDrained();
        }
    }

    private function sweepConnections(): void
    {
        $now = hrtime(true) / 1e9;

        foreach ($this->connections as $connection) {
            $connection->sweep($now);
        }
    }

    private function stopWhenDrained(): void
    {
        if ($this->connections !== []) {
            return;
        }

        if ($this->sweep !== null) {
            $this->loop->cancelTimer($this->sweep);
        }

        if ($this->drainDeadline !== null) {
            $this->loop->cancelTimer($this->drainDeadline);
        }

        $this->loop->stop();
    }
}
