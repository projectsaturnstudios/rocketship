<?php

declare(strict_types=1);

use Laravel\Octane\Commands\InstallCommand;
use ProjectSaturnStudios\Rocketship\Servers\RoadRunnerServer;

it("treats RoadRunner's installed binary path as a successful install, as Octane does", function () {
    // Octane's installRoadRunnerServer() ends with the binary path ensureRoadRunnerBinaryIsInstalled() returns.
    $command = new class extends InstallCommand
    {
        public function installRoadRunnerServer()
        {
            return '/app/rr';
        }
    };

    expect((new RoadRunnerServer)->install($command))->toBeTrue();
});
