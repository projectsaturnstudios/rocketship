<?php

declare(strict_types=1);

use ProjectSaturnStudios\Rocketship\Native\WorkerMode;

it('resolves auto to threads when this PHP can run them, else processes', function () {
    $expected = WorkerMode::threadsUnavailable() === null ? WorkerMode::Threads : WorkerMode::Processes;

    expect(WorkerMode::resolve('auto'))->toBe($expected);
});

it('refuses threads on a PHP that cannot run them, naming what is missing', function () {
    if (($missing = WorkerMode::threadsUnavailable()) === null) {
        test()->markTestSkipped('this PHP can run worker threads');
    }

    expect(fn () => WorkerMode::resolve('threads'))
        ->toThrow(RuntimeException::class, "Native threads mode needs {$missing}.");
});

it('never asks for APCu', function () {
    expect((string) WorkerMode::threadsUnavailable())->not->toContain('apcu')->not->toContain('APCu');
});

it('refuses an unknown mode', function () {
    WorkerMode::resolve('fibers');
})->throws(RuntimeException::class, 'Unknown native mode [fibers]; use threads, processes or auto.');

it('refuses threads on a PHP without pcntl or posix, which supervise them', function (string $missing) {
    if (! PHP_ZTS || ! extension_loaded('parallel')) {
        test()->markTestSkipped('needs a ZTS PHP with ext-parallel to reach the check');
    }

    expect(WorkerMode::threadsUnavailable(fn (string $function) => $function !== $missing && function_exists($function)))
        ->toBe('ext-pcntl and ext-posix to supervise worker threads');
})->with(['pcntl_sigprocmask', 'pcntl_async_signals', 'posix_kill']);
