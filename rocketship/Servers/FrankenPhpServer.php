<?php

declare(strict_types=1);

namespace ProjectSaturnStudios\Rocketship\Servers;

use Laravel\Octane\Commands\InstallCommand;
use Laravel\Octane\Commands\ReloadCommand;
use Laravel\Octane\Commands\StartCommand;
use Laravel\Octane\Commands\StatusCommand;
use Laravel\Octane\Commands\StopCommand;

/** Octane's FrankenPhp server: every operation is Octane's own command method, unchanged. */
final class FrankenPhpServer implements ServerDriver
{
    public function start(StartCommand $command): int
    {
        return $command->startFrankenPhpServer();
    }

    public function stop(StopCommand $command): int
    {
        return $command->stopFrankenPhpServer();
    }

    public function reload(ReloadCommand $command): int
    {
        return $command->reloadFrankenPhpServer();
    }

    public function running(StatusCommand $command): bool
    {
        return $command->isFrankenPhpServerRunning();
    }

    public function install(InstallCommand $command): bool
    {
        // Octane only checks the result's truthiness (RoadRunner's is the binary path).
        return (bool) $command->installFrankenPhpServer();
    }
}
