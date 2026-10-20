<?php

declare(strict_types=1);

namespace ProjectSaturnStudios\Rocketship\Tests\Fixtures;

use Laravel\Octane\Commands\InstallCommand;
use Laravel\Octane\Commands\ReloadCommand;
use Laravel\Octane\Commands\StartCommand;
use Laravel\Octane\Commands\StatusCommand;
use Laravel\Octane\Commands\StopCommand;
use ProjectSaturnStudios\Rocketship\Servers\ServerDriver;

/** Records every call so tests can see which command reached it. */
class OtherFakeServer implements ServerDriver
{
    /** @var list<array{string, class-string}> */
    public static array $calls = [];

    public function start(StartCommand $command): int
    {
        static::$calls[] = ['start', $command::class];

        return 7;
    }

    public function stop(StopCommand $command): int
    {
        static::$calls[] = ['stop', $command::class];

        return 7;
    }

    public function reload(ReloadCommand $command): int
    {
        static::$calls[] = ['reload', $command::class];

        return 7;
    }

    public function running(StatusCommand $command): bool
    {
        static::$calls[] = ['running', $command::class];

        return true;
    }

    public function install(InstallCommand $command): bool
    {
        static::$calls[] = ['install', $command::class];

        return true;
    }
}
