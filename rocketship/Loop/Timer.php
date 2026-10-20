<?php

namespace ProjectSaturnStudios\Rocketship\Loop;

use Closure;

final class Timer
{
    public float $deadline = 0.0;

    /** Set by Loop::cancelTimer(); a cancelled timer is skipped and never re-armed. */
    public bool $cancelled = false;

    public function __construct(
        public readonly float $interval,
        public readonly bool $periodic,
        public readonly Closure $callback,
    ) {
    }
}
