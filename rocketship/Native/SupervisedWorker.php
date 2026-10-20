<?php

namespace ProjectSaturnStudios\Rocketship\Native;

use ProjectSaturnStudios\Rocketship\Native\Control\ControlStream;

/** The supervisor's record of one worker, from spawn until it has ended and been reaped. */
final class SupervisedWorker
{
    /** Its control connection, once it has said hello. */
    public ?ControlStream $control = null;

    /** Booted and serving on its listener. */
    public bool $ready = false;

    /** Told to drain because a reload replaced it. */
    public bool $retiring = false;

    /** Its control stream closed or the spawner reaped it: its slot has been dealt with. */
    public bool $ended = false;

    /** When its running request started (master clock), or null while it waits for one. */
    public ?float $busySince = null;

    /** Threads mode: the running request's socket fd, whether it is TLS, and its peer, from the `busy` frame. */
    public ?int $busyFd = null;

    public bool $busyTls = false;

    public string $busyPeer = '';

    public function __construct(
        public readonly int $slot,
        public readonly int $token,
    ) {
    }
}
