<?php

declare(strict_types=1);

namespace ProjectSaturnStudios\Rocketship\Servers;

use Laravel\Octane\Commands\InstallCommand;
use Laravel\Octane\Commands\ReloadCommand;
use Laravel\Octane\Commands\StartCommand;
use Laravel\Octane\Commands\StatusCommand;
use Laravel\Octane\Commands\StopCommand;

/** Octane's Swoole server: every operation is Octane's own command method, unchanged. */
final class SwooleServer implements ServerDriver
{
    public function start(StartCommand $command): int
    {
        return $command->startSwooleServer();
    }

    public function stop(StopCommand $command): int
    {
        return $command->stopSwooleServer();
    }

    public function reload(ReloadCommand $command): int
    {
        return $command->reloadSwooleServer();
    }

    public function running(StatusCommand $command): bool
    {
        return $command->isSwooleServerRunning();
    }

    public function install(InstallCommand $command): bool
    {
        // Octane only checks the result's truthiness (RoadRunner's is the binary path).
        return (bool) $command->installSwooleServer();
    }
}
