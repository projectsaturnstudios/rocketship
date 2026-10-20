<?php

namespace Laravel\Octane\Facades;

use Illuminate\Support\Facades\Facade;
use ProjectSaturnStudios\Rocketship\Servers\ServerDriver;
use ProjectSaturnStudios\Rocketship\Servers\ServerRegistry;

/**
 * @method static \Laravel\Octane\Swoole\InvokeTickCallable tick(string $key, callable $callback, int $seconds = 1, bool $immediate = true)
 * @method static \Swoole\Table table(string $name)
 * @method static \Symfony\Component\HttpFoundation\Response invokeRoute(\Illuminate\Http\Request $request, string $method, string $uri)
 * @method static array concurrently(array $tasks, int $waitMilliseconds = 3000)
 * @method static bool hasRouteFor(string $method, string $uri)
 * @method static void route(string $method, string $uri, \Closure $callback)
 *
 * @see \Laravel\Octane\Octane
 */
class Octane extends Facade
{
    /**
     * Register an application server. Safe from any provider's register(), even one that runs before Octane's.
     *
     * @param  class-string<ServerDriver>  $driver
     */
    public static function registerServer(string $name, string $driver): void
    {
        $app = static::getFacadeApplication();

        if ($app?->bound(ServerRegistry::class)) {
            $app->make(ServerRegistry::class)->register($name, $driver);

            return;
        }

        ServerRegistry::defer($name, $driver);
    }

    protected static function getFacadeAccessor()
    {
        return 'octane';
    }
}
