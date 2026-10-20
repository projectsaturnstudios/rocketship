<?php

declare(strict_types=1);

namespace ProjectSaturnStudios\Rocketship\Servers;

use Laravel\Octane\Commands\InstallCommand;
use Laravel\Octane\Commands\ReloadCommand;
use Laravel\Octane\Commands\StartCommand;
use Laravel\Octane\Commands\StatusCommand;
use Laravel\Octane\Commands\StopCommand;

/** Octane's RoadRunner server: every operation is Octane's own command method, unchanged. */
final class RoadRunnerServer implements ServerDriver
{
    public function start(StartCommand $command): int
    {
        return $command->startRoadRunnerServer();
    }

    public function stop(StopCommand $command): int
    {
        return $command->stopRoadRunnerServer();
    }

    public function reload(ReloadCommand $command): int
    {
        return $command->reloadRoadRunnerServer();
    }

    public function running(StatusCommand $command): bool
    {
        return $command->isRoadRunnerServerRunning();
    }

    public function install(InstallCommand $command): bool
    {
        // Octane only checks the result's truthiness (RoadRunner's is the binary path).
        return (bool) $command->installRoadRunnerServer();
    }
}
