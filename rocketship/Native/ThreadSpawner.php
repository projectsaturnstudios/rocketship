<?php

namespace ProjectSaturnStudios\Rocketship\Native;

use Closure;
use parallel\Channel;
use parallel\Future;
use parallel\Runtime;
use Throwable;

/**
 * Threads mode: each worker is a parallel\Runtime in this process that boots its own application.
 *
 * Each worker runs inside a keeper thread. Cancelling a task waits until a blocking call (sleep, a socket read)
 * returns, so the master never cancels a worker itself: it tells the keeper, and only the keeper waits.
 */
final class ThreadSpawner implements WorkerSpawner
{
    /** What a keeper returns when it cancelled its worker. */
    private const CANCELLED = 'cancelled';

    /** What a keeper returns when its worker died of a fatal error. */
    private const FATAL = 'fatal';

    /** @var array<int, array{runtime: Runtime, future: Future, commands: Channel, cancelled: bool}> token => keeper */
    private array $threads = [];

    /**
     * @param  array<string, mixed>  $state
     * @param  Closure(int): int  $listenerFd  a new duplicate of the supervisor's listener for a slot, which the thread owns
     */
    public function __construct(
        private readonly array $state,
        private readonly Closure $listenerFd,
    ) {
        // Each worker runtime bootstraps through bin/native-thread.php, which reads the app's autoloader from the environment.
        putenv('ROCKETSHIP_AUTOLOAD='.$state['autoload']);
    }

    public function spawn(int $slot, int $token): void
    {
        // Threads inherit the creating thread's signal mask: block now so only the supervisor ever handles them.
        // The keeper creates the worker, so the worker inherits the same mask.
        pcntl_sigprocmask(SIG_BLOCK, Supervisor::SIGNALS, $previous);

        try {
            $runtime = new Runtime;
        } finally {
            pcntl_sigprocmask(SIG_SETMASK, $previous);
        }

        $commands = new Channel(Channel::Infinite);

        $future = $runtime->run(static function (string $bootstrap, array $state, int $slot, int $token, int $fd, Channel $commands): string {
            $worker = new \parallel\Runtime($bootstrap);
            $task = $worker->run(static function (array $state, int $slot, int $token, int $fd): string {
                return \ProjectSaturnStudios\Rocketship\Native\NativeWorker::runThread($state, $slot, $token, $fd);
            }, [$state, $slot, $token, $fd]);

            $events = new \parallel\Events;
            $events->addFuture('worker', $task);
            $events->addChannel($commands);

            while (true) {
                $event = $events->poll();

                if ($event->source === 'worker') {
                    return match ($event->type) {
                        \parallel\Events\Event\Type::Read => (string) $event->value,
                        \parallel\Events\Event\Type::Cancel => 'cancelled',
                        \parallel\Events\Event\Type::Kill => 'fatal',
                        default => 'error',
                    };
                }

                // `cancel`: waits here, never in the master, until a blocking call returns; the task stops there.
                $task->cancel();

                return 'cancelled';
            }
        }, [dirname(__DIR__, 2).'/bin/native-thread.php', $this->state, $slot, $token, ($this->listenerFd)($slot), $commands]);

        $this->threads[$token] = ['runtime' => $runtime, 'future' => $future, 'commands' => $commands, 'cancelled' => false];
    }

    public function reap(): array
    {
        $ended = [];

        foreach ($this->threads as $token => ['runtime' => $runtime, 'future' => $future]) {
            if (! $future->done()) {
                continue;
            }

            try {
                $ended[$token] = match ($future->value()) {
                    NativeWorker::DRAINED, self::CANCELLED => null,
                    NativeWorker::BOOT_FAILED => 'could not boot',
                    self::FATAL => 'died of a fatal error',
                    default => 'ended',
                };
            } catch (Throwable $e) {
                $ended[$token] = $e->getMessage();
            }

            // Closing the runtime ends its request, which releases whatever a fatal error left open.
            $runtime->close();
            $this->threads[$token]['commands']->close();
            unset($this->threads[$token]);
        }

        return $ended;
    }

    public function interrupt(int $token): void
    {
        if (isset($this->threads[$token]) && ! $this->threads[$token]['cancelled']) {
            $this->threads[$token]['cancelled'] = true;
            $this->threads[$token]['commands']->send('cancel');
        }
    }

    /**
     * Cancel through the keeper, never blocking: a worker in PHP code stops at once, one blocked inside a call stops
     * when that call returns. False while it still runs.
     */
    public function kill(int $token): bool
    {
        $this->interrupt($token);

        return ! isset($this->threads[$token]);
    }
}
