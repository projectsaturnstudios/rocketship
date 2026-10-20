<?php

declare(strict_types=1);

namespace ProjectSaturnStudios\Rocketship\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Facades\Octane;

/** An app's provider adding a server the way a package would. */
class FakeServerProvider extends ServiceProvider
{
    public function register(): void
    {
        Octane::registerServer('fake', FakeServer::class);
    }
}
