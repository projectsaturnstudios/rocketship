<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Octane\OctaneServiceProvider;

if (getenv('ROCKETSHIP_FIXTURE_BREAK_BOOT') || is_file(__DIR__.'/../storage/break-boot')) {
    throw new RuntimeException('The fixture app was told not to boot.');
}

// A slow boot while this exists, so tests can land signals inside a replacement's boot.
if (is_file(__DIR__.'/../storage/slow-boot')) {
    usleep(1_000_000);
}

// One line per application boot, so tests can count boots: HTTP workers set their slot before booting.
file_put_contents(
    __DIR__.'/../storage/'.(isset($_SERVER['ROCKETSHIP_WORKER_ID']) ? 'boots.log' : 'other-boots.log'),
    microtime(true).PHP_EOL,
    FILE_APPEND | LOCK_EX,
);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: [__DIR__.'/../routes/web.php', __DIR__.'/../routes/parity.php'])
    ->withProviders([OctaneServiceProvider::class])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
