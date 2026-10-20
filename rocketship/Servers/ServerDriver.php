<?php

declare(strict_types=1);

namespace ProjectSaturnStudios\Rocketship\Servers;

use Laravel\Octane\Commands\InstallCommand;
use Laravel\Octane\Commands\ReloadCommand;
use Laravel\Octane\Commands\StartCommand;
use Laravel\Octane\Commands\StatusCommand;
use Laravel\Octane\Commands\StopCommand;

/**
 * One application server behind Octane's commands. Each method receives the command
 * that was run so the driver reads its options and writes through its output.
 */
interface ServerDriver
{
    /** Start the server; returns the command's exit code. */
    public function start(StartCommand $command): int;

    /** Stop the running server; returns the command's exit code. */
    public function stop(StopCommand $command): int;

    /** Reload the running server's workers; returns the command's exit code. */
    public function reload(ReloadCommand $command): int;

    /** Whether the server is running. */
    public function running(StatusCommand $command): bool;

    /** Install the server's dependencies; false when installation failed. */
    public function install(InstallCommand $command): bool;
}
