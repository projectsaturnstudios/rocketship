<?php

namespace ProjectSaturnStudios\Rocketship\Loop\Drivers;

use ProjectSaturnStudios\Rocketship\Loop\DriverInterface;
use RuntimeException;

class EpollDriver implements DriverInterface
{
    protected int $epfd;

    /**
     * The loop's key ((int) $stream, PHP's resource id) => stream. epoll_ctl() resolves the stream
     * to its descriptor; the key rides in the data word so wait() hands it back unchanged.
     *
     * @var array<int, resource>
     */
    protected array $streams = [];

    public function __construct(protected int $maxEvents = 1024)
    {
        $this->epfd = epoll_create1(EPOLL_CLOEXEC);

        if ($this->epfd < 0) {
            throw new RuntimeException('epoll_create1 failed with errno '.epoll_errno());
        }
    }

    public function watch(int $fd, $stream, int $mask): void
    {
        $this->streams[$fd] = $stream;

        if (epoll_ctl($this->epfd, EPOLL_CTL_ADD, $stream, $this->events($mask), $fd) !== 0) {
            throw new RuntimeException("epoll_ctl ADD failed for fd {$fd} with errno ".epoll_errno());
        }
    }

    public function modify(int $fd, int $mask): void
    {
        if (epoll_ctl($this->epfd, EPOLL_CTL_MOD, $this->streams[$fd], $this->events($mask), $fd) !== 0) {
            throw new RuntimeException("epoll_ctl MOD failed for fd {$fd} with errno ".epoll_errno());
        }
    }

    public function unwatch(int $fd): void
    {
        // A stream already closed has left the epoll set with its descriptor; only a live one needs DEL.
        if (is_resource($this->streams[$fd] ?? null)) {
            epoll_ctl($this->epfd, EPOLL_CTL_DEL, $this->streams[$fd]);
        }
        unset($this->streams[$fd]);
    }

    public function wait(?float $timeout): iterable
    {
        $ms = $timeout === null ? -1 : (int) ceil($timeout * 1000);
        $ready = epoll_wait($this->epfd, $this->maxEvents, $ms);

        if ($ready === false) {
            // EINTR (a signal) or a transient error: report nothing and let the loop come round again.
            return [];
        }

        $out = [];
        foreach ($ready as ['events' => $events, 'data' => $fd]) {
            $mask = 0;
            if ($events & (EPOLLIN | EPOLLRDHUP | EPOLLHUP | EPOLLERR)) {
                $mask |= self::READ;
            }
            if ($events & EPOLLOUT) {
                $mask |= self::WRITE;
            }
            $out[] = [(int) $fd, $mask];
        }

        return $out;
    }

    public function name(): string
    {
        return 'epoll';
    }

    protected function events(int $mask): int
    {
        return (($mask & self::READ) ? EPOLLIN : 0)
            | (($mask & self::WRITE) ? EPOLLOUT : 0)
            | EPOLLRDHUP;
    }
}
