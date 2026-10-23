<?php

// Field-test routes, stateless (no session or cookies), the same in both apps.

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/plain', fn () => response('ok', 200, ['Content-Type' => 'text/plain']));

Route::get('/json', fn () => response()->json(array_combine(
    array_map(fn (int $i) => "key{$i}", range(1, 20)),
    array_map(fn (int $i) => ['id' => $i, 'name' => "item {$i}", 'active' => $i % 2 === 0], range(1, 20)),
)));

Route::get('/view', fn () => view('bench', ['items' => range(1, 50)]));

Route::get('/db', fn () => response()->json(DB::table('items')->where('id', random_int(1, 10000))->first()));

Route::get('/slow', function () {
    sleep(35);

    return 'late';
});

Route::get('/stream', fn () => response()->stream(function () {
    $chunk = str_repeat('x', 65536);

    for ($i = 0; $i < 16; $i++) {
        echo $chunk;
        flush();
    }
}, 200, ['Content-Type' => 'application/octet-stream']));

Route::post('/upload', fn (Request $request) => response((string) $request->file('file')?->getSize(), 200, ['Content-Type' => 'text/plain']));

Route::get('/pid', fn () => response((string) getmypid(), 200, ['Content-Type' => 'text/plain']));
