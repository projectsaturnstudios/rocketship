<?php

declare(strict_types=1);

namespace ProjectSaturnStudios\Rocketship\Tests;

use Laravel\Octane\OctaneServiceProvider;
use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    /** @var list<class-string> Providers a test needs registered ahead of Octane's, as package discovery may order them. */
    public static array $providersBeforeOctane = [];

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [...static::$providersBeforeOctane, OctaneServiceProvider::class];
    }
}
