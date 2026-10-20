<?php

declare(strict_types=1);

namespace ProjectSaturnStudios\Rocketship\Tests\Support;

use Carbon\Laravel\ServiceProvider as CarbonServiceProvider;
use Closure;
use Illuminate\Foundation\Application;
use Mockery;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\Client;
use ProjectSaturnStudios\Rocketship\Loop\Loop;
use ProjectSaturnStudios\Rocketship\Loop\LoopInterface;
use ProjectSaturnStudios\Rocketship\Native\NativeClient;
use ProjectSaturnStudios\Rocketship\Native\Server;
use ProjectSaturnStudios\Rocketship\Native\ServerOptions;
use ProjectSaturnStudios\Rocketship\Native\WorkerRequestHandler;
use Laravel\Octane\Octane;
use Laravel\Octane\OctaneServiceProvider;
use Laravel\Octane\Worker;
use RuntimeException;

use function Orchestra\Testbench\default_skeleton_path;

/**
 * A native server and a booted Laravel worker in the test process. The test drives the loop while it
 * talks to the server over real sockets, so every byte crosses the same parser, connection and writer
 * a running worker uses.
 */
final class NativeHarness
{
    public readonly LoopInterface $loop;

    public readonly Server $server;

    public readonly string $address;

    /** @var list<resource> */
    private array $clients = [];

    private function __construct(public readonly Application $app, public readonly Worker $worker, ServerOptions $options, string $publicPath)
    {
        $this->loop = Loop::create();
        $listener = Server::listen('127.0.0.1', 0);

        $handler = new WorkerRequestHandler($worker, $app->make(Client::class), $publicPath, $app['config']->get('octane', []));

        $this->server = new Server($this->loop, $listener, $handler(...), $options);

        $this->server->start();
        $this->address = $this->server->address();
    }

    /**
     * @param  Closure(Application): void  $routes
     * @param  array<string, mixed>  $config
     */
    public static function boot(Closure $routes, ServerOptions $options = new ServerOptions, ?string $publicPath = null, array $config = []): self
    {
        $app = (new ApplicationFactory(default_skeleton_path()))->createApplication();
        (new ApplicationFactory(default_skeleton_path()))->warm($app, Octane::defaultServicesToWarm());

        foreach ($config as $key => $value) {
            $app['config']->set($key, $value);
        }

        $app->register(new CarbonServiceProvider($app));
        $app->register(new OctaneServiceProvider($app));
        $app->instance(Client::class, $client = new NativeClient);

        $routes($app);

        $factory = Mockery::mock(ApplicationFactory::class);
        $factory->shouldReceive('createApplication')->andReturn($app);

        $worker = new Worker($factory, $client);
        $worker->boot();

        return new self($app, $worker, $options, $publicPath ?? $app->publicPath());
    }

    /** @return resource a connected, non-blocking client socket */
    public function connect()
    {
        $client = stream_socket_client("tcp://{$this->address}", $errno, $error, 2);

        if ($client === false) {
            throw new RuntimeException("connect failed: {$error}");
        }

        stream_set_blocking($client, false);

        return $this->clients[] = $client;
    }

    /**
     * Send raw bytes on a fresh connection (or $client) and drive the loop until $count responses arrive.
     *
     * @param  resource|null  $client
     * @return list<HttpResponse>
     */
    public function exchange(string $raw, int $count = 1, $client = null, bool $head = false, float $timeout = 5.0): array
    {
        $client ??= $this->connect();
        $this->write($client, $raw);

        return $this->receive($client, $count, $head, $timeout);
    }

    /** @param resource|null $client */
    public function get(string $path, array $headers = [], $client = null): HttpResponse
    {
        $lines = '';

        foreach ($headers as $name => $value) {
            $lines .= "{$name}: {$value}\r\n";
        }

        return $this->exchange("GET {$path} HTTP/1.1\r\nHost: localhost\r\n{$lines}\r\n", 1, $client)[0];
    }

    /** @param resource $client */
    public function write($client, string $bytes): void
    {
        while ($bytes !== '') {
            $written = @fwrite($client, $bytes);

            if ($written === false) {
                throw new RuntimeException('client write failed');
            }

            $bytes = substr($bytes, $written);

            if ($bytes !== '') {
                $this->loop->tick(0.001);
            }
        }
    }

    /**
     * @param  resource  $client
     * @return list<HttpResponse>
     */
    public function receive($client, int $count = 1, bool $head = false, float $timeout = 5.0): array
    {
        $buffer = '';
        $deadline = microtime(true) + $timeout;
        $flowing = false;

        while (microtime(true) < $deadline) {
            // While bytes flow, poll; a timed wait here would idle the server between every read.
            $this->loop->tick($flowing ? 0.0 : 0.002);

            $chunk = @fread($client, 1_048_576);
            $flowing = $chunk !== false && $chunk !== '';

            if ($flowing) {
                $buffer .= $chunk;
            }

            $closed = $chunk === '' && feof($client);
            [$responses] = HttpResponse::parseAll($buffer, $head, $closed);

            if (count($responses) >= $count) {
                return $responses;
            }

            if ($closed) {
                throw new RuntimeException('connection closed after '.count($responses)." of {$count} responses:\n".$buffer);
            }
        }

        throw new RuntimeException("timed out waiting for {$count} responses; got:\n".substr($buffer, 0, 2000));
    }

    /**
     * Drive the loop until the server closes $client.
     *
     * @param  resource  $client
     */
    public function closedByServer($client, float $timeout = 5.0): bool
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $this->loop->tick(0.005);
            $chunk = @fread($client, 65536);

            if ($chunk === false || ($chunk === '' && feof($client))) {
                return true;
            }
        }

        return false;
    }

    /** Drive the loop for $seconds. */
    public function run(float $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            $this->loop->tick(0.005);
        }
    }

    public function shutdown(): void
    {
        foreach ($this->clients as $client) {
            if (is_resource($client)) {
                fclose($client);
            }
        }

        $this->server->drain();

        $deadline = microtime(true) + 3;

        while ($this->server->connections() !== [] && microtime(true) < $deadline) {
            $this->loop->tick(0.005);
        }

        $this->worker->terminate();
    }
}
