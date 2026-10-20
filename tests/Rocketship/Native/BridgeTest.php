<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use ProjectSaturnStudios\Rocketship\Tests\Support\NativeHarness;
use Symfony\Component\HttpFoundation\Cookie;

function bridge(): NativeHarness
{
    return test()->harness = NativeHarness::boot(function (Application $app): void {
        $router = $app['router'];

        $router->any('/echo/{id?}', fn (Request $request) => [
            'method' => $request->method(),
            'path' => $request->path(),
            'fullUrl' => $request->fullUrl(),
            'query' => $request->query(),
            'input' => $request->input(),
            'json' => $request->isJson() ? $request->json()->all() : null,
            'cookies' => $request->cookies->all(),
            'agent' => $request->header('User-Agent'),
            'trace' => $request->header('X-Trace'),
            'ip' => $request->ip(),
            'secure' => $request->secure(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
            'content' => $request->getContent(),
        ]);

        $router->post('/upload', function (Request $request) {
            $photo = $request->file('photo');
            $docs = $request->file('docs', []);

            return [
                'valid' => $photo instanceof UploadedFile && $photo->isValid(),
                'name' => $photo?->getClientOriginalName(),
                'mime' => $photo?->getClientMimeType(),
                'content' => $photo?->getContent(),
                'tmp' => $photo?->getPathname(),
                'docs' => array_map(fn (UploadedFile $doc) => $doc->getClientOriginalName(), $docs),
                'title' => $request->input('title'),
            ];
        });

        $router->get('/cookies', fn () => response('ok', 201, ['X-Rocket' => 'saturn'])
            ->withCookie(new Cookie('flavor', 'oat meal', 0, '/', null, false, true))
            ->withCookie(new Cookie('size', 'large')));

        $router->get('/expired', fn () => abort(419));
        $router->get('/empty', fn () => response()->noContent());
        $router->get('/echoes', function () {
            echo 'printed ';

            return 'returned';
        });
        $router->get('/fails', fn () => throw new RuntimeException('secret detail'));
        $router->get('/closes', fn () => response('bye', 200, ['Connection' => 'close']));
    });
}

afterEach(function () {
    if (isset($this->harness)) {
        $this->harness->shutdown();
        // PHPUnit keeps every test case for the whole run, and a Laravel app is all reference cycles:
        // drop the harness and collect, or each test's app and buffers stay until the collector runs.
        unset($this->harness);
        gc_collect_cycles();
    }
});

it('carries method, path, query, headers, cookies and peer into the Laravel request', function () {
    $response = bridge()->get('/echo/7?sort=name&tags[]=a&tags[]=b', [
        'User-Agent' => 'RocketshipTest/1.0',
        'X-Trace' => 'abc',
        'Cookie' => 'theme=dark%20mode; a[b]=nested; theme=ignored',
    ]);

    expect($response->status)->toBe(200)
        ->and($response->json())->toMatchArray([
            'method' => 'GET',
            'path' => 'echo/7',
            'fullUrl' => 'http://localhost/echo/7?sort=name&tags%5B0%5D=a&tags%5B1%5D=b',
            'query' => ['sort' => 'name', 'tags' => ['a', 'b']],
            'cookies' => ['theme' => 'dark mode', 'a' => ['b' => 'nested']],
            'agent' => 'RocketshipTest/1.0',
            'trace' => 'abc',
            'ip' => '127.0.0.1',
            'secure' => false,
            'host' => 'localhost',
        ]);
});

it('fills input from url-encoded and JSON bodies on any method', function (string $method) {
    $form = 'name=Angel&rocket[stage]=2';
    $json = '{"name":"Angel","stages":[1,2,3]}';

    [$formResponse] = bridge()->exchange("{$method} /echo HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: ".strlen($form)."\r\n\r\n{$form}");
    [$jsonResponse] = test()->harness->exchange("{$method} /echo HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: ".strlen($json)."\r\n\r\n{$json}");

    expect($formResponse->json()['input'])->toBe(['name' => 'Angel', 'rocket' => ['stage' => '2']])
        ->and($formResponse->json()['content'])->toBe($form)
        ->and($jsonResponse->json()['json'])->toBe(['name' => 'Angel', 'stages' => [1, 2, 3]]);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('hands uploads to the app as valid UploadedFiles and removes the temp files afterwards', function () {
    $boundary = 'RocketshipBoundary';
    $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nLaunch\r\n"
        ."--{$boundary}\r\nContent-Disposition: form-data; name=\"photo\"; filename=\"rocket.png\"\r\nContent-Type: image/png\r\n\r\nPNGDATA\r\n"
        ."--{$boundary}\r\nContent-Disposition: form-data; name=\"docs[]\"; filename=\"a.txt\"\r\n\r\nA\r\n"
        ."--{$boundary}\r\nContent-Disposition: form-data; name=\"docs[]\"; filename=\"b.txt\"\r\n\r\nB\r\n"
        ."--{$boundary}--\r\n";

    [$response] = bridge()->exchange("POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Type: multipart/form-data; boundary={$boundary}\r\nContent-Length: ".strlen($body)."\r\n\r\n{$body}");

    expect($response->json())->toMatchArray([
        'valid' => true,
        'name' => 'rocket.png',
        'mime' => 'image/png',
        'content' => 'PNGDATA',
        'docs' => ['a.txt', 'b.txt'],
        'title' => 'Launch',
    ])->and(file_exists($response->json()['tmp']))->toBeFalse();
});

it('writes status, reason, headers, cookies, date and length', function () {
    $response = bridge()->get('/cookies');

    expect($response->status)->toBe(201)
        ->and($response->reason)->toBe('Created')
        ->and($response->header('X-Rocket'))->toBe('saturn')
        ->and($response->headers['set-cookie'])->toHaveCount(2)
        ->and($response->headers['set-cookie'][0])->toStartWith('flavor=oat%20meal; path=/; httponly')
        ->and($response->headers['set-cookie'][1])->toStartWith('size=large')
        ->and($response->header('Date'))->toMatch('/^\w{3}, \d{2} \w{3} \d{4} \d{2}:\d{2}:\d{2} GMT$/')
        ->and($response->header('Content-Length'))->toBe('2')
        ->and($response->body)->toBe('ok');
});

it('names statuses Symfony has no reason for', function () {
    expect(bridge()->get('/expired')->head)->toStartWith("HTTP/1.1 419 Page Expired\r\n");
});

it('sends no body for HEAD, 204 and keeps the connection for the next request', function () {
    $harness = bridge();
    $client = $harness->connect();

    [$head] = $harness->exchange("HEAD /cookies HTTP/1.1\r\nHost: localhost\r\n\r\n", 1, $client, head: true);
    $empty = $harness->get('/empty', [], $client);
    $after = $harness->get('/cookies', [], $client);

    expect($head->status)->toBe(201)
        ->and($head->body)->toBe('')
        ->and($empty->status)->toBe(204)
        ->and($empty->header('Content-Length'))->toBeNull()
        ->and($empty->body)->toBe('')
        ->and($after->body)->toBe('ok');
});

it('puts output echoed during the request ahead of the response body', function () {
    $response = bridge()->get('/echoes');

    expect($response->body)->toBe('printed returned')
        ->and($response->header('Content-Length'))->toBe('16');
});

it('renders a route exception through the app handler, hiding the message outside debug', function () {
    $harness = NativeHarness::boot(fn (Application $app) => $app['router']->get('/fails', fn () => throw new RuntimeException('secret detail')), config: ['app.debug' => false]);
    test()->harness = $harness;

    $response = $harness->get('/fails');

    expect($response->status)->toBe(500)
        ->and($response->body)->not->toContain('secret detail');
});

it('answers an exception that escapes the kernel with a plain 500', function () {
    $harness = NativeHarness::boot(fn (Application $app) => $app['router']->get('/unwritable', fn () => new class extends Illuminate\Http\Response
    {
        public function getContent(): string|false
        {
            throw new RuntimeException('secret detail');
        }
    }), config: ['app.debug' => false]);
    test()->harness = $harness;

    $response = $harness->get('/unwritable');

    expect($response->status)->toBe(500)
        ->and($response->header('Content-Type'))->toStartWith('text/plain')
        ->and($response->body)->not->toContain('secret detail');
});

it('closes after a response when the client or the app asks', function (string $path, string $extra) {
    $harness = bridge();
    $client = $harness->connect();

    $response = $harness->get($path, $extra === '' ? [] : ['Connection' => $extra], $client);

    expect($response->header('Connection'))->toBe('close')
        ->and($harness->closedByServer($client))->toBeTrue();
})->with([
    'client sends close' => ['/cookies', 'close'],
    'app sends close' => ['/closes', ''],
]);

it('closes an HTTP/1.0 connection unless it asked to keep alive', function () {
    $harness = bridge();
    $plain = $harness->connect();
    $kept = $harness->connect();

    [$closed] = $harness->exchange("GET /cookies HTTP/1.0\r\n\r\n", 1, $plain);
    [$alive] = $harness->exchange("GET /cookies HTTP/1.0\r\nConnection: keep-alive\r\n\r\n", 1, $kept);
    [$again] = $harness->exchange("GET /cookies HTTP/1.0\r\nConnection: keep-alive\r\n\r\n", 1, $kept);

    expect($closed->header('Connection'))->toBe('close')
        ->and($harness->closedByServer($plain))->toBeTrue()
        ->and($alive->header('Connection'))->toBe('keep-alive')
        ->and($again->body)->toBe('ok');
});

it('answers a malformed request with 400 and closes', function () {
    $harness = bridge();
    $client = $harness->connect();

    [$response] = $harness->exchange("GET / HTTP/1.1\r\nHost : bad\r\n\r\n", 1, $client);

    expect($response->status)->toBe(400)
        ->and($response->header('Connection'))->toBe('close')
        ->and($harness->closedByServer($client))->toBeTrue();
});

it('answers 100 Continue before reading the body it was promised', function () {
    $harness = bridge();
    $client = $harness->connect();

    [$continue] = $harness->exchange("PUT /echo HTTP/1.1\r\nHost: localhost\r\nExpect: 100-continue\r\nContent-Length: 5\r\n\r\n", 1, $client);
    [$response] = $harness->exchange('hello', 1, $client);

    expect($continue->status)->toBe(100)
        ->and($response->json()['content'])->toBe('hello');
});

it('closes a keep-alive connection left idle past the timeout', function () {
    $harness = NativeHarness::boot(fn (Application $app) => $app['router']->get('/', fn () => 'ok'), new ProjectSaturnStudios\Rocketship\Native\ServerOptions(keepAliveTimeout: 0.5));
    test()->harness = $harness;
    $client = $harness->connect();

    $harness->get('/', [], $client);

    expect($harness->closedByServer($client, 3.0))->toBeTrue();
});

it('answers 408 and closes a connection whose request head trickles past the header timeout', function () {
    $harness = NativeHarness::boot(
        fn (Application $app) => $app['router']->get('/', fn () => 'ok'),
        new ProjectSaturnStudios\Rocketship\Native\ServerOptions(keepAliveTimeout: 0.4, headerTimeout: 0.8),
    );
    test()->harness = $harness;
    $client = $harness->connect();

    // Each byte lands inside the keep-alive window, so only a bound on the whole head can end this.
    $harness->write($client, "GET / HTTP/1.1\r\nHost: x\r\nX-Slow: ");

    for ($i = 0; $i < 12; $i++) {
        $harness->write($client, 'a');
        $harness->run(0.2);
    }

    [$response] = $harness->receive($client);

    expect($response->status)->toBe(408)
        ->and($harness->closedByServer($client, 1.0))->toBeTrue();
});

it('lets the server stop at once when draining idle keep-alive connections', function () {
    $harness = NativeHarness::boot(fn (Application $app) => $app['router']->get('/', fn () => 'ok'));
    test()->harness = $harness;
    $client = $harness->connect();
    $harness->get('/', [], $client);

    $harness->server->drain();
    $started = microtime(true);

    while ($harness->server->connections() !== [] && microtime(true) - $started < 3) {
        $harness->loop->tick(0.01);
    }

    expect($harness->server->connections())->toBe([])
        ->and(microtime(true) - $started)->toBeLessThan(0.5);
});

it('closes a connection whose response finishes writing after draining began', function () {
    $harness = NativeHarness::boot(fn (Application $app) => $app['router']->get('/big', fn () => str_repeat('b', 4_000_000)));
    test()->harness = $harness;
    $client = $harness->connect();

    $harness->write($client, "GET /big HTTP/1.1\r\nHost: x\r\n\r\n");
    $harness->run(0.2); // served; most of the body is still owed to the socket

    $harness->server->drain();
    [$response] = $harness->receive($client);
    $started = microtime(true);

    while ($harness->server->connections() !== [] && microtime(true) - $started < 5) {
        $harness->loop->tick(0.01);
    }

    expect(strlen($response->body))->toBe(4_000_000)
        ->and($harness->server->connections())->toBe([]);
});

it('backs off accepting while the process is out of descriptors', function () {
    $harness = NativeHarness::boot(fn (Application $app) => $app['router']->get('/', fn () => 'ok'));
    test()->harness = $harness;
    $clients = array_map(fn () => $harness->connect(), range(1, 3));

    foreach ($clients as $client) {
        $harness->write($client, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
    }

    // Hold every descriptor this process may open: the server's next accept fails with EMFILE.
    $held = [];
    set_error_handler(static fn (): bool => true);

    try {
        while (count($held) < 1_000_000 && ($handle = fopen('/dev/null', 'r')) !== false) {
            $held[] = $handle;
        }

        $ticks = 0;
        $until = microtime(true) + 0.3;

        while (microtime(true) < $until) {
            $harness->loop->tick(0.05);
            $ticks++;
        }
    } finally {
        restore_error_handler();
        array_map(fclose(...), $held);
    }

    // A readable listener whose accept keeps failing would return from every wait at once.
    expect($ticks)->toBeLessThan(30);

    // Linux leaves a connection whose accept failed in the queue; macOS's kernel drops it.
    if (PHP_OS_FAMILY === 'Linux') {
        expect(array_map(fn ($client) => $harness->receive($client)[0]->status, $clients))->toBe([200, 200, 200]);
    }
});

it('keeps line breaks in a reason phrase from starting a header', function () {
    $harness = NativeHarness::boot(fn (Application $app) => $app['router']->get('/', fn () => response('ok')->setStatusCode(200, "Fine\r\nX-Injected: yes")));
    test()->harness = $harness;

    $response = $harness->get('/');

    expect($response->head)->not->toContain("\r\nX-Injected")
        ->and($response->status)->toBe(200);
});

it('hands a spooled large body to the application as its content', function () {
    $harness = NativeHarness::boot(fn (Application $app) => $app['router']->post('/json', fn () => ['items' => count(request()->json('items')), 'bytes' => strlen(request()->getContent())]));
    test()->harness = $harness;
    $payload = json_encode(['items' => array_fill(0, 200_000, 'abcdefgh')]);

    [$response] = $harness->exchange("POST /json HTTP/1.1\r\nHost: x\r\nContent-Type: application/json\r\nContent-Length: ".strlen($payload)."\r\n\r\n".$payload);

    expect($response->json())->toBe(['items' => 200_000, 'bytes' => strlen($payload)]);
});
