<?php

namespace ProjectSaturnStudios\Rocketship\Native;

use ProjectSaturnStudios\Rocketship\Loop\Loop;
use ProjectSaturnStudios\Rocketship\Loop\LoopInterface;
use ProjectSaturnStudios\Rocketship\Native\Control\ControlSocket;
use ProjectSaturnStudios\Rocketship\Native\Control\ControlStream;

/**
 * The native server's master, in both worker modes. It alone handles SIGTERM, SIGINT, SIGHUP and SIGUSR1,
 * and runs a loop with a control listener every worker connects to at boot. Stop and reload go to the
 * workers as frames on those connections; a worker's death closes its connection and its slot is refilled.
 * Reloads and max_requests recycling are rolling: the replacement serves before the old worker drains.
 */
final class Supervisor
{
    /** The signals the master handles; workers keep them blocked. */
    public const SIGNALS = [SIGTERM, SIGINT, SIGHUP, SIGUSR1];

    /** Seconds past the drain timeout before a worker still running is killed. */
    private const KILL_GRACE = 5.0;

    /** How often ended workers are collected: a thread has no descriptor to wake the loop when it returns. */
    private const REAP_INTERVAL = 0.1;

    /** Seconds a timed-out process gets to answer 408 and exit before it is killed. */
    private const TIME_OUT_GRACE = 1.0;

    /** Seconds before a slot whose worker could not boot is tried again, while other slots serve. */
    private const RESPAWN_DELAY = 1.0;

    /** Seconds a control connection has to say hello before it is dropped. */
    private const HELLO_TIMEOUT = 3.0;

    private LoopInterface $loop;

    private WorkerSpawner $spawner;

    private string $controlPath;

    /** @var resource */
    private $controlListener;

    /** @var resource|null the listener every worker shares where the kernel does not balance per-worker sockets */
    private $listener = null;

    /**
     * Where the kernel balances SO_REUSEPORT sockets (Linux): slot => that slot's socket, held here for the server's
     * life so replacing a worker never closes it. Closing a socket in the group resets the connections queued on it.
     *
     * @var array<int, resource>
     */
    private array $slotListeners = [];

    /** @var array<int, SupervisedWorker> token => worker, until it has ended and been reaped */
    private array $workers = [];

    /** @var array<int, int> slot => token of the worker that should be serving it */
    private array $current = [];

    /** @var array<int, true> slots that were asked to reload while a replacement was already booting */
    private array $reloadPending = [];

    /** @var array<int, ControlStream> accepted control connections, by descriptor */
    private array $connections = [];

    /** @var array<int, int> descriptor => token, once the connection has said hello */
    private array $introduced = [];

    private int $nextToken = 1;

    private bool $stopping = false;

    private bool $failed = false;

    /** @param array<string, mixed> $state */
    public function __construct(private array $state, private readonly WorkerMode $mode)
    {
    }

    public function run(): int
    {
        $this->loop = Loop::create();
        ControlSocket::removeStale($this->state['basePath']);
        $this->controlPath = ControlSocket::path($this->state['basePath'], getmypid());
        $this->controlListener = ControlSocket::listen($this->controlPath);
        $this->state['control'] = $this->controlPath;
        // Proves a hello comes from a worker this master started: held in memory, inherited by fork, copied to threads.
        $this->state['controlSecret'] = bin2hex(random_bytes(16));

        if (WorkerMode::kernelBalancesListeners()) {
            // A slot's socket joins the port when its worker has booted and calls posix_listen, so the kernel never
            // queues a connection on a slot nobody serves. Slot 0 listens now: the port queues connections from the
            // start, as Swoole's does, and its first worker answers them once booted.
            for ($slot = 0; $slot < (int) $this->state['workers']; $slot++) {
                $this->slotListeners[$slot] = Server::listen($this->state['host'], (int) $this->state['port'], reusePort: true, listen: $slot === 0);
            }
        } else {
            $this->listener = Server::listen($this->state['host'], (int) $this->state['port']);
        }

        $this->spawner = match ($this->mode) {
            // Each thread owns a duplicate taken now, so closing the master's handle can never pull it from under one.
            WorkerMode::Threads => new ThreadSpawner($this->state, fn (int $slot): int => posix_dup(posix_fileno($this->listenerFor($slot)))),
            WorkerMode::Processes => new ProcessSpawner($this->state, $this->listenerFor(...), $this->streamsToClose(...)),
        };

        // No syscall restart, so a signal ends the loop's wait. The work happens on the loop, never inside a
        // handler: a child forked from inside a handler never handles another signal.
        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
            pcntl_signal($signal, fn () => $this->loop->futureTick($this->stop(...)), false);
        }

        pcntl_signal(SIGUSR1, fn () => $this->loop->futureTick($this->reload(...)), false);

        $this->loop->onReadable($this->controlListener, $this->acceptControl(...));
        $this->loop->addPeriodicTimer(self::REAP_INTERVAL, $this->reap(...));

        for ($slot = 0; $slot < (int) $this->state['workers']; $slot++) {
            $this->spawn($slot);
        }

        $this->loop->run();

        foreach ($this->connections as $connection) {
            $connection->close();
        }

        fclose($this->controlListener);
        @unlink($this->controlPath);
        $this->closeListeners();

        return $this->failed ? 1 : 0;
    }

    /** @return resource the listener the worker in $slot serves */
    private function listenerFor(int $slot)
    {
        return $this->slotListeners[$slot] ?? $this->listener;
    }

    private function closeListeners(): void
    {
        foreach ([$this->listener, ...$this->slotListeners] as $listener) {
            if (is_resource($listener)) {
                fclose($listener);
            }
        }
    }

    /**
     * A slot whose replacement cannot boot leaves the port: the kernel would keep queueing connections on its
     * listening socket, and nobody would answer them. Those already queued there are reset. The slot gets a
     * socket that is only bound, which its next worker opens once it has booted.
     */
    private function unlistenSlot(int $slot): void
    {
        if (isset($this->slotListeners[$slot])) {
            fclose($this->slotListeners[$slot]);
            $this->slotListeners[$slot] = Server::listen($this->state['host'], (int) $this->state['port'], reusePort: true, listen: false);
        }
    }

    private function spawn(int $slot): void
    {
        $token = $this->nextToken++;
        $this->workers[$token] = new SupervisedWorker($slot, $token);
        $this->current[$slot] = $token;

        $this->spawner->spawn($slot, $token);
    }

    /**
     * The master's streams a child forked for $slot closes: everything but the listener it serves.
     *
     * @return list<resource>
     */
    private function streamsToClose(int $slot): array
    {
        return [
            $this->controlListener,
            ...array_map(fn (ControlStream $connection) => $connection->stream, array_values($this->connections)),
            ...array_values(array_diff_key($this->slotListeners, [$slot => true])),
        ];
    }

    private function acceptControl(): void
    {
        while (($socket = @stream_socket_accept($this->controlListener, 0)) !== false) {
            $connection = new ControlStream($socket);
            $connection->attach($this->loop);
            $this->connections[(int) $socket] = $connection;
            $this->loop->onReadable($socket, fn () => $this->readControl($connection));
            $this->loop->addTimer(self::HELLO_TIMEOUT, function () use ($connection): void {
                if (is_resource($connection->stream) && ! isset($this->introduced[(int) $connection->stream])) {
                    $this->drop($connection);
                }
            });
        }
    }

    private function readControl(ControlStream $connection): void
    {
        $id = (int) $connection->stream;
        $frames = $connection->receive();
        $worker = isset($this->introduced[$id]) ? $this->workers[$this->introduced[$id]] ?? null : null;

        if ($frames === null) {
            $worker !== null ? $this->ended($worker) : $this->drop($connection);

            return;
        }

        foreach ($frames as $frame) {
            if ($worker === null) {
                $worker = $this->introduce($connection, $frame);

                if ($worker === null) {
                    return;
                }
            } elseif ($frame === 'ready') {
                $this->ready($worker);
            } elseif ($frame === 'spent') {
                $this->replace($worker);
            } elseif (str_starts_with($frame, 'busy')) {
                $this->busy($worker, $frame);
            } elseif ($frame === 'idle') {
                $worker->busySince = null;
            }
            // `failed` needs nothing here: the worker closes its end next, and a worker that never got ready
            // is handled as a boot failure when it ends.
        }
    }

    private function introduce(ControlStream $connection, string $frame): ?SupervisedWorker
    {
        [$token, $secret] = sscanf($frame, 'hello %d %s') + [null, null];
        $worker = $token === null ? null : $this->workers[$token] ?? null;

        if ($worker === null || $worker->control !== null || $worker->ended
            || ! is_string($secret) || ! hash_equals($this->state['controlSecret'], $secret)) {
            $this->drop($connection);

            return null;
        }

        $worker->control = $connection;
        $this->introduced[(int) $connection->stream] = $token;

        if ($this->stopping) {
            $connection->send('stop');
        }

        return $worker;
    }

    private function ready(SupervisedWorker $worker): void
    {
        $worker->ready = true;

        if ($this->stopping) {
            return;
        }

        if ($this->current[$worker->slot] !== $worker->token) {
            // A later reload superseded it while it booted.
            $this->retire($worker);

            return;
        }

        // The slot's replacement serves: the workers it replaces finish what they hold and exit.
        foreach ($this->workers as $other) {
            if ($other->slot === $worker->slot && $other !== $worker && ! $other->ended && ! $other->retiring) {
                $this->retire($other);
            }
        }

        $this->spawnIfPending($worker->slot);
    }

    /** A reload that arrived while this slot's replacement booted gets one more replacement, with the latest code. */
    private function spawnIfPending(int $slot): void
    {
        if (isset($this->reloadPending[$slot])) {
            unset($this->reloadPending[$slot]);
            $this->spawn($slot);
        }
    }

    /** A worker reached max_requests: start its replacement; it is retired once the replacement serves. */
    private function replace(SupervisedWorker $worker): void
    {
        if (! $this->stopping && ! $worker->retiring && $this->current[$worker->slot] === $worker->token) {
            $this->spawn($worker->slot);
        }
    }

    private function retire(SupervisedWorker $worker): void
    {
        $worker->retiring = true;
        $worker->control?->send('reload');

        // It drains within the drain timeout; one still running past the grace period after it is killed.
        $this->loop->addTimer($this->killDeadline(), function () use ($worker): void {
            if (($this->workers[$worker->token] ?? null) === $worker && ! $this->spawner->kill($worker->token)) {
                fwrite(STDERR, "Worker {$worker->slot} is stuck in a request past the drain deadline; its thread ends when that call returns.".PHP_EOL);
            }
        });
    }

    /** A worker started a request: `busy` from a process, `busy <fd> <tls> <peer>` from a thread. */
    private function busy(SupervisedWorker $worker, string $frame): void
    {
        [, $fd, $tls, $peer] = explode(' ', $frame, 4) + [null, null, null, ''];
        $worker->busySince = hrtime(true) / 1e9;
        $worker->busyFd = $fd === null ? null : (int) $fd;
        $worker->busyTls = $tls === '1';
        $worker->busyPeer = (string) $peer;
    }

    /** max_execution_time on the wall clock, as Octane's Swoole server measures it: past it, the request gets 408. */
    private function enforceExecutionTime(): void
    {
        $limit = (int) ($this->state['octaneConfig']['max_execution_time'] ?? 0);

        if ($limit <= 0) {
            return;
        }

        $now = hrtime(true) / 1e9;

        foreach ($this->workers as $worker) {
            if ($worker->busySince !== null && ! $worker->ended && $now - $worker->busySince > $limit) {
                $this->timeOut($worker);
            }
        }
    }

    /**
     * The client gets 408 and the slot a fresh worker at once; the timed-out worker never finishes the request. A
     * thread is answered from here (same process) through its socket, which is then shut so nothing more reaches
     * the client; a process answers from its own SIGUSR2 handler and dies, or is killed after a grace second.
     */
    private function timeOut(SupervisedWorker $worker): void
    {
        $worker->busySince = null;

        if ($this->mode === WorkerMode::Threads && $worker->busyFd !== null) {
            Connection::answerTimedOut($worker->busyFd, $worker->busyTls, $worker->busyPeer);
        }

        $this->spawner->interrupt($worker->token);

        if (! $worker->retiring) {
            $worker->retiring = true;
            $worker->control?->send('stop');

            if (! $this->stopping && $this->current[$worker->slot] === $worker->token) {
                $this->spawn($worker->slot);
            }
        }

        $this->loop->addTimer(self::TIME_OUT_GRACE, function () use ($worker): void {
            if (($this->workers[$worker->token] ?? null) === $worker) {
                $this->spawner->kill($worker->token);
            }
        });
    }

    private function killDeadline(): float
    {
        return NativeWorker::options($this->state)->drainTimeout + self::KILL_GRACE;
    }

    /** A worker's control stream closed or the spawner reaped it; whichever comes first refills its slot. */
    private function ended(SupervisedWorker $worker): void
    {
        if ($worker->ended) {
            return;
        }

        $worker->ended = true;

        if ($worker->control !== null) {
            $this->drop($worker->control);
        }

        if ($this->stopping || $worker->retiring) {
            return;
        }

        if (! $worker->ready) {
            $this->bootFailed($worker);

            return;
        }

        if ($this->current[$worker->slot] === $worker->token) {
            $this->spawn($worker->slot);
        }
    }

    /** A worker ended before it served: its application cannot boot. */
    private function bootFailed(SupervisedWorker $worker): void
    {
        $serving = $this->servingIn($worker->slot);

        if ($serving === null) {
            if (! $this->anyServing()) {
                $this->fail("Worker {$worker->slot} could not boot the application.");

                return;
            }

            // Other slots still serve: keep them, and retry this one, as Swoole's manager respawns a worker that
            // dies. A second apart, so an app that stays broken does not spin.
            fwrite(STDERR, "Worker {$worker->slot} could not boot the application; retrying while the other workers serve.".PHP_EOL);
            $this->unlistenSlot($worker->slot);
            $this->loop->addTimer(self::RESPAWN_DELAY, function () use ($worker): void {
                if (! $this->stopping && $this->current[$worker->slot] === $worker->token) {
                    $this->spawn($worker->slot);
                }
            });

            return;
        }

        fwrite(STDERR, "Worker {$worker->slot} could not boot the reloaded application; the running worker keeps serving.".PHP_EOL);

        if ($this->current[$worker->slot] === $worker->token) {
            $this->current[$worker->slot] = $serving->token;
            $this->spawnIfPending($worker->slot);
        }
    }

    private function anyServing(): bool
    {
        foreach ($this->workers as $worker) {
            if ($worker->ready && ! $worker->ended && ! $worker->retiring) {
                return true;
            }
        }

        return false;
    }

    private function servingIn(int $slot): ?SupervisedWorker
    {
        foreach ($this->workers as $worker) {
            if ($worker->slot === $slot && $worker->ready && ! $worker->ended && ! $worker->retiring) {
                return $worker;
            }
        }

        return null;
    }

    private function reap(): void
    {
        $this->enforceExecutionTime();

        foreach ($this->spawner->reap() as $token => $reason) {
            if (! isset($this->workers[$token])) {
                continue;
            }

            $worker = $this->workers[$token];

            if ($reason !== null && $worker->ready && ! $worker->retiring && ! $this->stopping) {
                fwrite(STDERR, "Worker {$worker->slot} {$reason}; starting another.".PHP_EOL);
            }

            $this->ended($worker);
            unset($this->workers[$token]);
        }

        if ($this->stopping && $this->workers === []) {
            $this->loop->stop();
        }
    }

    private function stop(): void
    {
        if ($this->stopping) {
            return;
        }

        $this->stopping = true;

        foreach ($this->workers as $worker) {
            $worker->control?->send('stop');
        }

        // Once the draining workers close their handles too, new connections are refused instead of queued.
        $this->closeListeners();

        // Workers drain within the drain timeout; past it and a grace period, they are killed.
        $this->loop->addTimer($this->killDeadline(), function (): void {
            $stuck = array_filter($this->workers, fn (SupervisedWorker $worker): bool => ! $this->spawner->kill($worker->token));

            if ($stuck === []) {
                return; // killed processes are reaped and the loop ends
            }

            // A thread blocked inside a call (sleep, a socket read) only ends when the call returns, and a normal
            // exit would wait for it, so the master ends itself outright.
            foreach ($stuck as $worker) {
                fwrite(STDERR, "Worker {$worker->slot} did not stop; ending the server.".PHP_EOL);
            }

            @unlink($this->controlPath);
            posix_kill(getmypid(), SIGKILL);
        });
    }

    private function reload(): void
    {
        if ($this->stopping) {
            return;
        }

        foreach ($this->current as $slot => $token) {
            $current = $this->workers[$token] ?? null;

            if ($current !== null && ! $current->ready && ! $current->ended) {
                // A replacement is booting already: one more follows it once it serves, however many reloads arrive.
                $this->reloadPending[$slot] = true;

                continue;
            }

            $this->spawn($slot);
        }
    }

    private function fail(string $message): void
    {
        fwrite(STDERR, $message.PHP_EOL);
        $this->failed = true;
        $this->stop();
    }

    private function drop(ControlStream $connection): void
    {
        $id = (int) $connection->stream;

        if (is_resource($connection->stream)) {
            $this->loop->cancelReadable($connection->stream);
        }

        $connection->close();
        unset($this->connections[$id], $this->introduced[$id]);
    }
}
