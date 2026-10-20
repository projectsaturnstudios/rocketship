<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use ProjectSaturnStudios\Rocketship\Tests\Support\NativeServerProcess;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * octane:start --server=native in the fixture app, in processes mode.
 *
 * @param  list<string>  $options
 * @param  array<string, string>  $files
 */
function startedByArtisan(array $options = [], array $files = []): NativeServerProcess
{
    return test()->server = new NativeServerProcess(
        env: ['APP_ENV' => 'local', 'OCTANE_NATIVE_MODE' => 'processes'],
        artisan: ['octane:start', '--server=native', '--workers=2', ...$options],
        files: $files,
    );
}

afterEach(function () {
    if (isset($this->server)) {
        $this->server->stop();
    }
});

it('starts, reports, reloads and stops the native server through artisan', function () {
    $server = startedByArtisan();
    $before = $server->worker()['boot'];

    $status = $server->artisan(['octane:status', '--server=native']);
    $reload = $server->artisan(['octane:reload', '--server=native']);

    $deadline = microtime(true) + 10;

    do {
        $after = $server->worker()['boot'];
    } while ($after === $before && microtime(true) < $deadline);

    $stop = $server->artisan(['octane:stop', '--server=native']);

    expect($status->getOutput())->toContain('Octane server is running.')
        ->and($reload->getOutput())->toContain('Reloading workers')
        ->and($after)->not->toBe($before)
        ->and($stop->isSuccessful())->toBeTrue()
        ->and($server->waitForExit())->not->toBeNull()
        ->and($server->isListening())->toBeFalse()
        ->and($server->output())->toContain('/worker'); // request lines print in local
});

it('reloads the workers when a watched file changes', function () {
    // The watcher is Octane's file-watcher.cjs: node, with chokidar resolvable from bin/ or NODE_PATH.
    $node = (new ExecutableFinder)->find('node');

    if ($node === null || (new Process([$node, '-e', "require.resolve('chokidar')"], dirname(__DIR__, 3).'/bin'))->run() !== 0) {
        test()->markTestSkipped('--watch needs node with chokidar resolvable from bin/ or NODE_PATH');
    }

    // Recycling would replace the workers without any reload.
    $server = startedByArtisan(['--watch', '--max-requests=1000000']);
    $tokens = fn (): array => array_unique(array_map(
        fn (array $response) => json_decode($response['body'], true)['boot'],
        $server->hammer('/worker', 40),
    ));
    $before = $tokens();
    $deadline = microtime(true) + 20;

    do {
        touch($server->root.'/routes/web.php'); // routes is in the default watch list
        usleep(250_000);
        $after = $tokens();
    } while (array_intersect($after, $before) !== [] && microtime(true) < $deadline);

    expect(array_intersect($after, $before))->toBe([])
        ->and($server->output())->toContain('Application change detected');
});

it('starts native for an app that published Octane\'s config before native existed', function () {
    $server = startedByArtisan(files: ['config/octane.php' => (string) file_get_contents(dirname(__DIR__).'/Fixtures/published-octane-config.php')]);

    expect($server->worker()['pid'])->toBeInt();
});

it('prints a failed request and logs its exception, as Octane does for Swoole', function () {
    // A route exception goes to Laravel's handler (logged, rendered as 500), not to WorkerErrorOccurred.
    $server = startedByArtisan();

    expect($server->get('/throw')['status'])->toBe(500);

    $log = $server->root.'/storage/logs/laravel.log';
    $deadline = microtime(true) + 5;

    while (! (str_contains($server->output(), '/throw') && is_file($log)) && microtime(true) < $deadline) {
        usleep(100_000);
    }

    expect($server->output())->toMatch('/500\s+GET\s+\/throw/')
        ->and((string) @file_get_contents($log))->toContain('boom from the fixture');
});

it('refuses a TLS file it cannot read', function () {
    $server = new NativeServerProcess(
        env: ['OCTANE_NATIVE_TLS_CERT' => '/nonexistent/cert.pem'],
        artisan: ['octane:start', '--server=native'],
        wait: false,
    );
    test()->server = $server;

    expect($server->waitForExit())->toBe(1)
        ->and($server->output())->toContain('Native TLS file [/nonexistent/cert.pem] is not readable.');
});

it('reports a stopped native server as not running', function () {
    $server = new NativeServerProcess(artisan: ['octane:status', '--server=native'], wait: false);
    test()->server = $server;

    $server->waitForExit();

    expect($server->output())->toContain('Octane server is not running.');
});

it('reports a dead native master as not running and clears its state', function () {
    $stateFile = storage_path('logs/octane-server-state.json');
    File::ensureDirectoryExists(dirname($stateFile));
    // A PID no process has: the state of a master that died without cleaning up.
    file_put_contents($stateFile, json_encode(['masterProcessId' => 999_999_999, 'state' => []]));

    try {
        $this->artisan('octane:stop', ['--server' => 'native'])
            ->expectsOutputToContain('Native server is not running.')
            ->assertExitCode(1);

        expect(File::exists($stateFile))->toBeFalse();
    } finally {
        File::delete($stateFile);
    }
});

it('offers native at install', function () {
    $published = config_path('octane.php');
    $hadConfig = File::exists($published);

    try {
        $this->artisan('octane:install')
            ->expectsChoice('Which application server you would like to use?', 'native', ['frankenphp', 'roadrunner', 'swoole', 'native'])
            ->assertExitCode(0);
    } finally {
        $hadConfig || File::delete($published);
    }
});
