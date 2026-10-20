<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Laravel\Octane\Commands\InstallCommand;
use Laravel\Octane\Commands\ReloadCommand;
use Laravel\Octane\Commands\StartCommand;
use Laravel\Octane\Commands\StatusCommand;
use Laravel\Octane\Commands\StopCommand;
use Laravel\Octane\Facades\Octane;
use ProjectSaturnStudios\Rocketship\Tests\Fixtures\FakeServer;
use ProjectSaturnStudios\Rocketship\Tests\Fixtures\FakeServerProvider;

beforeEach(function () {
    FakeServer::$calls = [];

    // octane:install publishes config/octane.php: keep it out of the Testbench skeleton.
    $this->publishedConfig = config_path('octane.php');
    $this->hadConfig = File::exists($this->publishedConfig);
});

afterEach(function () {
    if (! $this->hadConfig) {
        File::delete($this->publishedConfig);
    }
});

it('runs every command through a registered server', function () {
    Octane::registerServer('fake', FakeServer::class);

    $this->artisan('octane:start', ['--server' => 'fake'])->assertExitCode(7);
    $this->artisan('octane:stop', ['--server' => 'fake'])->assertExitCode(7);
    $this->artisan('octane:reload', ['--server' => 'fake'])->assertExitCode(7);
    $this->artisan('octane:status', ['--server' => 'fake'])
        ->expectsOutputToContain('Octane server is running.')
        ->assertExitCode(0);
    $this->artisan('octane:install', ['--server' => 'fake'])
        ->expectsOutputToContain('Octane installed successfully.')
        ->assertExitCode(0);

    expect(FakeServer::$calls)->toBe([
        ['start', StartCommand::class],
        ['stop', StopCommand::class],
        ['reload', ReloadCommand::class],
        ['running', StatusCommand::class],
        ['install', InstallCommand::class],
    ]);
});

it('registers a server from an app provider', function () {
    $this->app->register(FakeServerProvider::class);

    $this->artisan('octane:stop', ['--server' => 'fake'])->assertExitCode(7);
});

it('rejects an unknown server on every command', function (string $command, array $options) {
    config(['octane.server' => 'nope']);

    $this->artisan($command, $options)
        ->expectsOutputToContain('Invalid server: nope.')
        ->assertExitCode(1);
})->with([
    ['octane:start', []],
    ['octane:stop', []],
    ['octane:reload', []],
    ['octane:status', []],
    // Octane's install never reads octane.server: it takes --server or prompts.
    ['octane:install', ['--server' => 'nope']],
]);

it("keeps Octane's messages for the built-in servers", function (string $server, string $stopped) {
    $this->artisan('octane:stop', ['--server' => $server])
        ->expectsOutputToContain($stopped)
        ->assertExitCode(1);
    $this->artisan('octane:reload', ['--server' => $server])
        ->expectsOutputToContain('Octane server is not running.')
        ->assertExitCode(1);
    $this->artisan('octane:status', ['--server' => $server])
        ->expectsOutputToContain('Octane server is not running.')
        ->assertExitCode(1);
})->with([
    ['swoole', 'Swoole server is not running.'],
    ['roadrunner', 'RoadRunner server is not running.'],
    ['frankenphp', 'FrankenPHP server is not running.'],
    ['native', 'Native server is not running.'],
]);

it('offers registered servers at install', function () {
    Octane::registerServer('fake', FakeServer::class);

    $this->artisan('octane:install')
        ->expectsChoice('Which application server you would like to use?', 'fake', ['frankenphp', 'roadrunner', 'swoole', 'native', 'fake'])
        ->assertExitCode(0);
});

it("registers a server from a package provider that runs before Octane's", function () {
    // Discovery registers package providers alphabetically, so acme/* runs before projectsaturnstudios/rocketship.
    ProjectSaturnStudios\Rocketship\Tests\TestCase::$providersBeforeOctane = [FakeServerProvider::class];

    try {
        $this->refreshApplication();

        $this->artisan('octane:stop', ['--server' => 'fake'])->assertExitCode(7);
        $this->artisan('octane:stop', ['--server' => 'swoole'])->assertExitCode(1);
    } finally {
        ProjectSaturnStudios\Rocketship\Tests\TestCase::$providersBeforeOctane = [];
    }
});

it("fails on a missing server name the way Octane does", function (string $command) {
    config(['octane.server' => null]);

    // Octane hands the null name to invalidServer(string), which throws.
    expect(fn () => $this->artisan($command)->run())
        ->toThrow(TypeError::class, 'invalidServer(): Argument #1 ($server) must be of type string, null given');
})->with(['octane:start', 'octane:stop', 'octane:reload', 'octane:status']);
