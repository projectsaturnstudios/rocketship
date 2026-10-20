<?php

use Illuminate\Support\Facades\Route;

// One token per booted worker: a new process or thread answers with a new one.
Route::get('/worker', fn () => [
    'id' => $_SERVER['ROCKETSHIP_WORKER_ID'] ?? null,
    'pid' => getmypid(),
    'boot' => $_SERVER['ROCKETSHIP_FIXTURE_BOOT'] ??= bin2hex(random_bytes(8)),
]);

// A fatal error no handler can catch: the worker running this request dies, after ?wait seconds.
Route::get('/fatal', function () {
    usleep((int) ((float) request('wait', 0) * 1_000_000));

    // A limit below current usage is refused, so set it just above.
    ini_set('memory_limit', (string) (memory_get_usage(true) + 4 * 1024 * 1024));

    return strlen(str_repeat('x', 64 * 1024 * 1024));
});

// Blocks in sleep(), which no time limit or runtime kill interrupts, for ?s seconds.
Route::get('/sleep', function () {
    sleep((int) request('s', 1));

    return 'slept';
});

// Burns CPU past max_execution_time (which counts CPU time on macOS).
Route::get('/spin', function () {
    while (true) {
        //
    }
});

// An exception the app does not handle: Octane reports it and the start command prints it.
Route::get('/throw', fn () => throw new RuntimeException('boom from the fixture'));

// Sleeps ?s seconds, then leaves a marker: a request ended at max_execution_time must never write it.
Route::get('/sleep-then-mark', function () {
    sleep((int) request('s', 3));
    file_put_contents(storage_path('marked'), 'written after the sleep');

    return 'marked';
});

// 125 MB in flushed 64 KB chunks: against a client that stops reading, the worker waits inside the stream's
// output handler when max_execution_time ends the request.
Route::get('/stream-large', fn () => response()->stream(function (): void {
    for ($i = 0; $i < 2000; $i++) {
        echo str_repeat('x', 65536);
        flush();
    }
}));

// concurrently() on the native server.
Route::get('/concurrently', fn () => Laravel\Octane\Facades\Octane::concurrently([fn () => 1, fn () => 2]));
