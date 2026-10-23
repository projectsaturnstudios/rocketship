<?php

declare(strict_types=1);

use ProjectSaturnStudios\Rocketship\Tests\Support\NativeServerProcess;

it('leaves no process of the server it started behind', function (string $server, array $env) {
    if ($server === 'swoole' && ! extension_loaded('swoole')) {
        test()->markTestSkipped('needs ext-swoole');
    }

    if (($env['OCTANE_NATIVE_MODE'] ?? null) === 'threads' && (! PHP_ZTS || ! extension_loaded('parallel'))) {
        test()->markTestSkipped('threads mode needs a ZTS PHP with ext-parallel');
    }

    $process = new NativeServerProcess(env: $env, artisan: ['octane:start', "--server={$server}", '--workers=1', ...($server === 'swoole' ? ['--task-workers=1'] : [])], swoole: $server === 'swoole');
    $root = $process->root;

    expect($process->processGroup())->toContain($process->group);

    $process->stop();

    $deadline = microtime(true) + 5;

    while ($process->processGroup() !== [] && microtime(true) < $deadline) {
        usleep(100_000);
    }

    usleep(1_500_000); // a process left behind would write logs into the copy again

    expect($process->processGroup())->toBe([])
        ->and(is_dir($root))->toBeFalse();
})->with([
    'swoole' => ['swoole', []],
    'native threads' => ['native', ['OCTANE_NATIVE_MODE' => 'threads']],
    'native processes' => ['native', ['OCTANE_NATIVE_MODE' => 'processes']],
]);
