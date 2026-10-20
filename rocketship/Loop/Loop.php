<?php

namespace ProjectSaturnStudios\Rocketship\Loop;

use ProjectSaturnStudios\Rocketship\Loop\Drivers\EpollDriver;
use ProjectSaturnStudios\Rocketship\Loop\Drivers\KqueueDriver;
use ProjectSaturnStudios\Rocketship\Loop\Drivers\SelectDriver;
use SplMinHeap;

class Loop implements LoopInterface
{
    /** @var array<int, callable> */
    protected array $readers = [];

    /** @var array<int, callable> */
    protected array $writers = [];

    /** @var array<int, resource> */
    protected array $streams = [];

    /** @var SplMinHeap<array{0: float, 1: int, 2: Timer}> */
    protected SplMinHeap $timers;

    protected int $sequence = 0;

    protected bool $running = false;

    /** @var list<callable> callbacks for the start of the next tick */
    protected array $futureTicks = [];

    /** True while run() is driving tick(); lets tick() honour a stop() made by a timer before it blocks. */
    protected bool $inRun = false;

    public function __construct(protected DriverInterface $driverInstance)
    {
        $this->timers = new SplMinHeap;
    }

    public static function create(?string $forced = null): LoopInterface
    {
        $name = $forced ?: match (true) {
            function_exists('epoll_create1') => 'epoll',
            function_exists('kqueue') => 'kqueue',
            default => 'select',
        };

        return new static(match ($name) {
            'epoll' => new EpollDriver,
            'kqueue' => new KqueueDriver,
            'select' => new SelectDriver,
            default => throw new \InvalidArgumentException("Unknown loop driver [{$name}]."),
        });
    }

    public function onReadable($stream, callable $fn): void
    {
        $fd = (int) $stream;
        $had = $this->mask($fd);
        $previous = $this->readers[$fd] ?? null;
        $this->readers[$fd] = $fn;
        $this->streams[$fd] = $stream;
        $this->syncOrRollBack($fd, $stream, $had, $this->readers, $previous);
    }

    public function onWritable($stream, callable $fn): void
    {
        $fd = (int) $stream;
        $had = $this->mask($fd);
        $previous = $this->writers[$fd] ?? null;
        $this->writers[$fd] = $fn;
        $this->streams[$fd] = $stream;
        $this->syncOrRollBack($fd, $stream, $had, $this->writers, $previous);
    }

    public function cancelReadable($stream): void
    {
        $fd = (int) $stream;
        $had = $this->mask($fd);
        unset($this->readers[$fd]);
        $this->sync($fd, $stream, $had);
    }

    public function cancelWritable($stream): void
    {
        $fd = (int) $stream;
        $had = $this->mask($fd);
        unset($this->writers[$fd]);
        $this->sync($fd, $stream, $had);
    }

    public function futureTick(callable $fn): void
    {
        $this->futureTicks[] = $fn;
    }

    public function addTimer(float $seconds, callable $fn): Timer
    {
        return $this->schedule(new Timer($seconds, false, $fn(...)));
    }

    public function addPeriodicTimer(float $seconds, callable $fn): Timer
    {
        return $this->schedule(new Timer($seconds, true, $fn(...)));
    }

    public function cancelTimer(Timer $timer): void
    {
        $timer->cancelled = true;
    }

    public function run(): void
    {
        $this->running = true;
        $this->inRun = true;

        try {
            while ($this->running) {
                $this->tick(null);
            }
        } finally {
            $this->inRun = false;
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function tick(?float $timeout = null): void
    {
        $ticks = $this->futureTicks;
        $this->futureTicks = [];

        foreach ($ticks as $fn) {
            $fn();
        }

        $this->fireDueTimers();

        if ($this->inRun && ! $this->running) {
            return; // a timer called stop(): don't block on the next wait
        }

        // A future tick queued by a timer or another future tick must not sit behind a blocking wait.
        $wait = $this->futureTicks === [] ? $this->secondsUntilNextTimer() : 0.0;

        if ($timeout !== null) {
            $wait = $wait === null ? $timeout : min($wait, $timeout);
        }

        if ($wait === null && $this->readers === [] && $this->writers === []) {
            // Nothing to wait for: an idle run() would spin, so stop.
            $this->running = false;

            return;
        }

        foreach ($this->driverInstance->wait($wait) as [$fd, $mask]) {
            if (($mask & DriverInterface::READ) && isset($this->readers[$fd])) {
                ($this->readers[$fd])();
            }
            if (($mask & DriverInterface::WRITE) && isset($this->writers[$fd])) {
                ($this->writers[$fd])();
            }
        }
    }

    public function driver(): string
    {
        return $this->driverInstance->name();
    }

    protected function mask(int $fd): int
    {
        return (isset($this->readers[$fd]) ? DriverInterface::READ : 0)
            | (isset($this->writers[$fd]) ? DriverInterface::WRITE : 0);
    }

    /**
     * Sync with the driver; if the driver refuses (select's descriptor ceiling), undo the map change so
     * the loop and the driver agree and a later registration of the same stream reaches the driver.
     *
     * @param  resource  $stream
     * @param  array<int, callable>  $map
     */
    protected function syncOrRollBack(int $fd, $stream, int $had, array &$map, ?callable $previous): void
    {
        try {
            $this->sync($fd, $stream, $had);
        } catch (\Throwable $e) {
            if ($previous === null) {
                unset($map[$fd]);
            } else {
                $map[$fd] = $previous;
            }

            if ($had === 0) {
                unset($this->streams[$fd]);
            }

            throw $e;
        }
    }

    /** @param resource $stream */
    protected function sync(int $fd, $stream, int $had): void
    {
        $now = $this->mask($fd);

        if ($now === $had) {
            return;
        }

        if ($had === 0) {
            $this->driverInstance->watch($fd, $stream, $now);
        } elseif ($now === 0) {
            $this->driverInstance->unwatch($fd);
            unset($this->streams[$fd]);
        } else {
            $this->driverInstance->modify($fd, $now);
        }
    }

    protected function schedule(Timer $timer): Timer
    {
        $timer->deadline = $this->now() + $timer->interval;
        $this->timers->insert([$timer->deadline, $this->sequence++, $timer]);

        return $timer;
    }

    protected function fireDueTimers(): void
    {
        $now = $this->now();

        while (! $this->timers->isEmpty() && $this->timers->top()[0] <= $now) {
            [, , $timer] = $this->timers->extract();

            if ($timer->cancelled) {
                continue;
            }

            ($timer->callback)();

            if ($timer->periodic && ! $timer->cancelled) {
                $timer->deadline = max($timer->deadline + $timer->interval, $this->now());
                $this->timers->insert([$timer->deadline, $this->sequence++, $timer]);
            }
        }
    }

    protected function secondsUntilNextTimer(): ?float
    {
        while (! $this->timers->isEmpty()) {
            [$deadline, , $timer] = $this->timers->top();

            if ($timer->cancelled) {
                $this->timers->extract();

                continue;
            }

            return max(0.0, $deadline - $this->now());
        }

        return null;
    }

    protected function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
