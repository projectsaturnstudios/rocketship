<?php

namespace ProjectSaturnStudios\Rocketship\Native;

/** How the supervisor starts, collects and kills workers: forked processes or parallel threads. */
interface WorkerSpawner
{
    /** Start a worker for $slot that introduces itself on the control socket as `hello <token>`. */
    public function spawn(int $slot, int $token): void;

    /**
     * Workers that have ended since the last call.
     *
     * @return array<int, ?string> token => how it ended badly, or null for a clean exit
     */
    public function reap(): array;

    /** End a worker that outlived the drain deadline; false when this spawner cannot force it out. */
    public function kill(int $token): bool;

    /**
     * The worker's request ran past max_execution_time: stop it without blocking the master. A process answers
     * 408 itself and exits; a thread is cancelled and stops at the next opcode once any blocking call returns.
     */
    public function interrupt(int $token): void;
}
