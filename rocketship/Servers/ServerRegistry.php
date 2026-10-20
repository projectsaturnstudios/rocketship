<?php

declare(strict_types=1);

namespace ProjectSaturnStudios\Rocketship\Servers;

use InvalidArgumentException;

/** Server name → driver class, in registration order. A later registration replaces an earlier one. */
final class ServerRegistry
{
    /** @var array<string, class-string<ServerDriver>> */
    private array $drivers = [];

    /** @var array<string, class-string<ServerDriver>> Registered before Octane's provider bound the registry. */
    private static array $pending = [];

    /** @param class-string<ServerDriver> $driver */
    public function register(string $name, string $driver): void
    {
        self::ensureDriver($driver);

        $this->drivers[$name] = $driver;
    }

    /**
     * Hold a registration made before the registry exists (a package provider that runs ahead of Octane's).
     *
     * @param  class-string<ServerDriver>  $driver
     */
    public static function defer(string $name, string $driver): void
    {
        self::ensureDriver($driver);

        self::$pending[$name] = $driver;
    }

    /** Register everything deferred, after the built-ins, so a deferred name replaces a built-in one. */
    public function registerDeferred(): void
    {
        foreach (self::$pending as $name => $driver) {
            $this->register($name, $driver);
        }

        self::$pending = [];
    }

    public function has(string $name): bool
    {
        return isset($this->drivers[$name]);
    }

    public function driver(string $name): ServerDriver
    {
        if (! $this->has($name)) {
            throw new InvalidArgumentException("Server [{$name}] is not registered.");
        }

        return app($this->drivers[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->drivers);
    }

    private static function ensureDriver(string $driver): void
    {
        if (! is_subclass_of($driver, ServerDriver::class)) {
            throw new InvalidArgumentException("Server driver [{$driver}] must implement ".ServerDriver::class.'.');
        }
    }
}
