<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use ProjectSaturnStudios\Rocketship\Tests\Support\NativeHarness;

/** @param array<string, mixed> $config */
function staticHarness(array $config = []): NativeHarness
{
    $root = sys_get_temp_dir().'/rocketship-static-'.bin2hex(random_bytes(4));
    $public = "{$root}/public";
    mkdir("{$public}/css", recursive: true);
    mkdir("{$root}/shared");
    file_put_contents("{$public}/css/app.css", 'body{color:red}');
    file_put_contents("{$public}/robots.txt", "User-agent: *\n");
    file_put_contents("{$public}/index.php", '<?php echo 1;');
    file_put_contents("{$public}/.htaccess", 'deny');
    file_put_contents("{$root}/secret.txt", 'secret');
    file_put_contents("{$root}/shared/logo.svg", '<svg/>');
    symlink("{$root}/shared", "{$public}/storage");
    $GLOBALS['rocketshipStaticRoots'][] = $root;

    return test()->harness = NativeHarness::boot(function (Application $app): void {
        $app['router']->fallback(fn () => response('from the app', 404));
        $app['router']->post('/robots.txt', fn () => 'posted to the app');
    }, publicPath: $public, config: $config);
}

afterEach(function () {
    if (isset($this->harness)) {
        $this->harness->shutdown();
        // PHPUnit keeps every test case for the whole run, and a Laravel app is all reference cycles:
        // drop the harness and collect, or each test's app and buffers stay until the collector runs.
        unset($this->harness);
        gc_collect_cycles();
    }

    foreach ($GLOBALS['rocketshipStaticRoots'] ?? [] as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }

    $GLOBALS['rocketshipStaticRoots'] = [];
});

it('serves a public file with its type, length, ETag and Last-Modified', function () {
    $response = staticHarness()->get('/css/app.css');
    $mtime = filemtime(end($GLOBALS['rocketshipStaticRoots']).'/public/css/app.css');

    expect($response->status)->toBe(200)
        ->and($response->body)->toBe('body{color:red}')
        ->and($response->header('Content-Type'))->toBe('text/css')
        ->and($response->header('Cache-Control'))->toBeNull()
        ->and($response->header('Content-Length'))->toBe('15')
        ->and($response->header('ETag'))->toBe('"'.dechex($mtime).'-f"')
        ->and($response->header('Last-Modified'))->toBe(gmdate('D, d M Y H:i:s', $mtime).' GMT');
});

it('answers 304 to a matching If-None-Match or a fresh If-Modified-Since', function () {
    $harness = staticHarness();
    $first = $harness->get('/robots.txt');

    $byTag = $harness->get('/robots.txt', ['If-None-Match' => $first->header('ETag')]);
    $byDate = $harness->get('/robots.txt', ['If-Modified-Since' => $first->header('Last-Modified')]);
    $stale = $harness->get('/robots.txt', ['If-None-Match' => '"other"']);

    expect($byTag->status)->toBe(304)
        ->and($byTag->body)->toBe('')
        ->and($byDate->status)->toBe(304)
        ->and($stale->status)->toBe(200);
});

it('serves a byte range of a static file', function () {
    $response = staticHarness()->get('/css/app.css', ['Range' => 'bytes=0-3']);

    expect($response->status)->toBe(206)
        ->and($response->body)->toBe('body');
});

it('leaves scripts, dotfiles, directories and paths outside public to the app', function (string $path) {
    $response = staticHarness()->get($path);

    expect($response->status)->toBe(404)
        ->and($response->body)->toBe('from the app');
})->with([
    'php' => ['/index.php'],
    'htaccess' => ['/.htaccess'],
    'directory' => ['/css'],
    'root' => ['/'],
    'traversal' => ['/../secret.txt'],
    'missing' => ['/nope.css'],
]);

it('serves files inside a symlinked directory', function () {
    $response = staticHarness()->get('/storage/logo.svg');

    expect($response->status)->toBe(200)
        ->and($response->body)->toBe('<svg/>')
        ->and($response->header('Content-Type'))->toBe('image/svg+xml');
});

it('adds static_file_headers for matching paths', function () {
    $response = staticHarness(['octane.static_file_headers' => ['css/*' => ['Cache-Control' => 'max-age=31536000']]])->get('/css/app.css');

    expect($response->header('Cache-Control'))->toBe('max-age=31536000');
});

it('hands static paths to the app when serve_static_files is off', function (mixed $off) {
    // SwooleClient turns static files off for any falsy value of a present key.
    expect(staticHarness(['octane.serve_static_files' => $off])->get('/robots.txt')->body)->toBe('from the app');
})->with([false, 0, null, '']);

it('serves a static file for any method, as Swoole does', function () {
    // SwooleClient::canServeRequestAsStaticFile() has no method check, and Octane's Worker asks it for every request.
    [$posted] = staticHarness()->exchange("POST /robots.txt HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n");

    expect($posted->status)->toBe(200)
        ->and($posted->body)->toBe(file_get_contents(end($GLOBALS['rocketshipStaticRoots']).'/public/robots.txt'));
});
