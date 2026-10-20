<?php

namespace ProjectSaturnStudios\Rocketship\Native;

use Closure;
use RuntimeException;

/** Processes mode: each worker is a forked child that boots the application after the fork. */
final class ProcessSpawner implements WorkerSpawner
{
    /** @var array<int, int> pid => token */
    private array $children = [];

    /**
     * @param  array<string, mixed>  $state
     * @param  Closure(int): resource  $listener  the supervisor's listener for a slot, which that slot's child inherits
     * @param  Closure(int): list<resource>  $inherited  the supervisor's other streams, which a slot's child closes
     */
    public function __construct(
        private readonly array $state,
        private readonly Closure $listener,
        private readonly Closure $inherited,
    ) {
    }

    public function spawn(int $slot, int $token): void
    {
        // The child starts with the supervisor's signals blocked and keeps them so: it drains on control frames.
        pcntl_sigprocmask(SIG_BLOCK, Supervisor::SIGNALS, $previous);

        $pid = pcntl_fork();

        if ($pid === -1) {
            pcntl_sigprocmask(SIG_SETMASK, $previous);

            throw new RuntimeException('Could not fork a worker: '.pcntl_strerror(pcntl_get_last_error()));
        }

        if ($pid > 0) {
            pcntl_sigprocmask(SIG_SETMASK, $previous);
            $this->children[$pid] = $token;

            return;
        }

        // Child. A copy of the supervisor's end of a sibling's control connection would keep that sibling
        // from seeing the supervisor die, so every supervisor stream goes but this slot's listener.
        foreach (($this->inherited)($slot) as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $listener = ($this->listener)($slot);
        $result = NativeWorker::serve($this->state, $slot, $token, fn () => $listener, false);

        exit($result === NativeWorker::DRAINED ? 0 : 1);
    }

    public function reap(): array
    {
        $ended = [];

        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            if (! isset($this->children[$pid])) {
                continue;
            }

            $ended[$this->children[$pid]] = match (true) {
                pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0 => null,
                pcntl_wifexited($status) => 'exited with status '.pcntl_wexitstatus($status),
                pcntl_wifsignaled($status) => 'killed by signal '.pcntl_wtermsig($status),
                default => 'ended',
            };

            unset($this->children[$pid]);
        }

        return $ended;
    }

    public function interrupt(int $token): void
    {
        if (($pid = array_search($token, $this->children, true)) !== false) {
            posix_kill($pid, SIGUSR2);
        }
    }

    public function kill(int $token): bool
    {
        if (($pid = array_search($token, $this->children, true)) !== false) {
            posix_kill($pid, SIGKILL);
        }

        return true;
    }
}
