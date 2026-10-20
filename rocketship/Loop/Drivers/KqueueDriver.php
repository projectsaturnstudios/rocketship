<?php

namespace ProjectSaturnStudios\Rocketship\Loop\Drivers;

use kevent;
use ProjectSaturnStudios\Rocketship\Loop\DriverInterface;
use RuntimeException;
use timespec;

class KqueueDriver implements DriverInterface
{
    protected int $kq;

    /** @var array<int, int> fd => current mask, so modify() knows which filters to add or delete */
    protected array $masks = [];

    /** @var array<int, resource> */
    protected array $streams = [];

    /** @var list<kevent> changes submitted with the next kevent() call */
    protected array $changes = [];

    public function __construct(protected int $maxEvents = 1024)
    {
        $this->kq = kqueue();

        if ($this->kq < 0) {
            throw new RuntimeException('kqueue() failed with errno '.kqueue_errno());
        }
    }

    public function watch(int $fd, $stream, int $mask): void
    {
        $this->streams[$fd] = $stream;
        $this->masks[$fd] = 0;
        $this->modify($fd, $mask);
    }

    public function modify(int $fd, int $mask): void
    {
        $had = $this->masks[$fd] ?? 0;

        foreach ([self::READ => EVFILT_READ, self::WRITE => EVFILT_WRITE] as $bit => $filter) {
            if (($mask & $bit) && ! ($had & $bit)) {
                $this->change($fd, $filter, EV_ADD);
            } elseif (! ($mask & $bit) && ($had & $bit)) {
                $this->change($fd, $filter, EV_DELETE);
            }
        }

        $this->masks[$fd] = $mask;
    }

    public function unwatch(int $fd): void
    {
        $this->modify($fd, 0);
        unset($this->masks[$fd], $this->streams[$fd]);
    }

    public function wait(?float $timeout): iterable
    {
        $ts = null;
        if ($timeout !== null) {
            $ts = new timespec;
            $ts->tv_sec = (int) floor($timeout);
            $ts->tv_nsec = (int) (($timeout - floor($timeout)) * 1e9);
        }

        $changes = $this->changes;
        $this->changes = [];

        $n = kevent($this->kq, $changes, count($changes), $events, $this->maxEvents, $ts);

        if ($n < 0) {
            // EINTR: nothing to report this pass.
            return [];
        }

        $out = [];
        foreach ($events as $event) {
            if ($event->flags & EV_ERROR) {
                // EV_RECEIPT answers for queued deletes, including ENOENT for an fd the kernel already dropped at close.
                continue;
            }
            $fd = $event->udata;
            $out[$fd] = ($out[$fd] ?? 0) | ($event->filter === EVFILT_READ ? self::READ : self::WRITE);
        }

        $ready = [];
        foreach ($out as $fd => $mask) {
            $ready[] = [$fd, $mask];
        }

        return $ready;
    }

    public function name(): string
    {
        return 'kqueue';
    }

    /**
     * Queue a change for the next kevent() call. EV_SET resolves the stream to its descriptor now;
     * udata carries the loop's key back on wake. A stream already closed has left the queue with its
     * descriptor, so its delete is dropped rather than sent.
     */
    protected function change(int $key, int $filter, int $flags): void
    {
        $stream = $this->streams[$key] ?? null;

        if (! is_resource($stream)) {
            return;
        }

        EV_SET($this->changes[] = new kevent, $stream, $filter, $flags | ($flags === EV_DELETE ? EV_RECEIPT : 0), 0, 0, $key);
    }
}
