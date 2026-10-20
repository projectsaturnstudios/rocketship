<?php

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

// Every Octane event a request fires, in order, one class per line: the parity harness compares the lists.
Event::listen('Laravel\Octane\Events\*', function (string $event): void {
    file_put_contents(storage_path('parity-events.log'), $event.PHP_EOL, FILE_APPEND | LOCK_EX);
});

$noCsrf = [Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class];

Route::prefix('parity')->group(function () use ($noCsrf): void {
    Route::get('/plain', fn () => response('plain text', 200, ['Content-Type' => 'text/plain']));
    Route::get('/json', fn () => ['ok' => true, 'items' => [1, 2, 3]]);
    Route::get('/view', fn () => view('parity', ['title' => 'Parity', 'items' => ['a', 'b']]));
    Route::get('/cookie', function (Request $request) {
        $request->session()->put('visited', true);

        return response('cookie')->cookie('parity', 'value', 10);
    });
    Route::get('/redirect', fn () => redirect('/parity/plain'));
    Route::get('/stream', fn () => response()->stream(function (): void {
        echo 'alpha,';
        echo str_repeat('b', 100_000);
        echo ',omega';
    }));
    Route::get('/download', fn () => response()->download(base_path('public/robots.txt'), 'robots-copy.txt'));
    Route::post('/upload', fn (Request $request) => [
        'files' => collect($request->allFiles())->map(fn (UploadedFile $file) => [
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'md5' => md5_file($file->getPathname()),
        ])->all(),
        'title' => $request->input('title'),
    ])->withoutMiddleware($noCsrf);
    Route::get('/dd', fn () => dd(['dumped' => 'value']));
    Route::get('/throw', fn () => throw new RuntimeException('parity failure'));
    Route::post('/large-body', fn (Request $request) => [
        'length' => strlen($request->getContent()),
        'md5' => md5($request->getContent()),
    ])->withoutMiddleware($noCsrf);
});
