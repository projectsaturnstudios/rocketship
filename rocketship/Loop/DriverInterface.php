<?php

namespace ProjectSaturnStudios\Rocketship\Loop;

interface DriverInterface
{
    public const READ = 1;

    public const WRITE = 2;

    /**
     * $fd is the loop's key for the stream: (int) $stream, PHP's resource id, which is NOT the OS
     * descriptor. Drivers pass the stream itself to the kernel API and return the key from wait().
     *
     * @param  resource  $stream
     */
    public function watch(int $fd, $stream, int $mask): void;

    public function modify(int $fd, int $mask): void;

    public function unwatch(int $fd): void;

    /**
     * Block up to $timeout seconds (null = forever, 0.0 = poll).
     *
     * @return iterable<array{0: int, 1: int}> [fd, mask] for each ready descriptor
     */
    public function wait(?float $timeout): iterable;

    public function name(): string;
}
