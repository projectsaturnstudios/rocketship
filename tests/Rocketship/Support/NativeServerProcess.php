<?php

declare(strict_types=1);

namespace ProjectSaturnStudios\Rocketship\Tests\Support;

use ProjectSaturnStudios\Rocketship\Native\Control\ControlSocket;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The real bin/native-server against a copy of the fixture app, launched the way octane:start
 * launches it, or through the fixture's own `artisan octane:start`.
 */
final class NativeServerProcess
{
    public readonly Process $process;

    public readonly int $port;

    public readonly string $root;

    public readonly string $stateFile;

    /**
     * @param  array<string, mixed>  $state  merged over the defaults the start command would write
     * @param  array<string, string>  $env
     * @param  ?list<string>  $artisan  run `php artisan` with these arguments (and --port) instead of bin/native-server
     * @param  list<string>  $without  more extensions whose ini the server's PHP leaves out, beside Swoole
     * @param  array<string, string>  $files  files written into the app copy before the server starts, by relative path
     * @param  bool  $swoole  keep ext-swoole loaded, for an `octane:start --server=swoole` to compare against
     */
    public function __construct(array $state = [], array $env = [], bool $wait = true, ?array $artisan = null, array $without = [], array $files = [], bool $swoole = false)
    {
        $package = dirname(__DIR__, 3);
        $this->root = sys_get_temp_dir().'/rocketship-native-app-'.bin2hex(random_bytes(4));
        exec('cp -R '.escapeshellarg($package.'/tests/Rocketship/Fixtures/native-app').' '.escapeshellarg($this->root));
        // An app has its vendor dir in its base path, where Octane's bin/bootstrap.php looks for it.
        symlink($package.'/vendor', $this->root.'/vendor');

        foreach ($files as $path => $contents) {
            @mkdir(dirname($this->root.'/'.$path), 0777, true);
            file_put_contents($this->root.'/'.$path, $contents);
        }

        $this->port = self::freePort();
        $this->stateFile = $this->root.'/storage/logs/octane-server-state.json';

        $serverState = array_replace_recursive([
            'appName' => 'Rocketship Fixture',
            'host' => '127.0.0.1',
            'port' => $this->port,
            'workers' => 2,
            'maxRequests' => 0,
            'mode' => 'processes',
            'tls' => null,
            'publicPath' => $this->root.'/public',
            // What octane:start writes: the app's whole octane config. Octane's defaults configure tables and the
            // cache, as in most apps: native must start with them configured and no APCu.
            'octaneConfig' => [
                'tables' => ['example:1000' => ['name' => 'string:1000', 'votes' => 'int']],
                'cache' => ['rows' => 1000, 'bytes' => 10000],
                'max_execution_time' => 30,
                'native' => ['keepalive_timeout' => 5, 'max_body' => 10_485_760],
            ],
        ], $state);

        $command = $artisan === null
            ? [PHP_BINARY, ...($serverState['octaneConfig']['native']['php_options'] ?? []), 'bin/native-server', $this->stateFile]
            : [PHP_BINARY, $this->root.'/artisan', ...$artisan, ...(str_ends_with($artisan[0], ':start') ? ['--port='.$this->port] : [])];

        $artisan === null && file_put_contents($this->stateFile, json_encode(['state' => $serverState]));

        $this->process = new Process($command, $artisan === null ? $package : $this->root, [
            'APP_BASE_PATH' => $this->root,
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'COMPOSER_VENDOR_DIR' => $package.'/vendor',
            'LARAVEL_OCTANE' => '1',
            // The fixture has no database: Laravel's default session and cache stores would need one.
            'SESSION_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'XDEBUG_MODE' => 'off',
            ...$this->without([...($swoole ? [] : ['swoole']), ...$without]),
            ...$env,
        ]);

        $this->process->start();

        if ($wait) {
            $this->waitUntilListening();
        }
    }

    /**
     * Run an artisan command in the fixture app.
     *
     * @param  list<string>  $arguments
     */
    public function artisan(array $arguments): Process
    {
        return tap(new Process([PHP_BINARY, $this->root.'/artisan', ...$arguments], $this->root, $this->process->getEnv()))->run();
    }

    /**
     * A PHP_INI_SCAN_DIR without these extensions' inis. Always Swoole's: ext-parallel crashes the process beside
     * ext-swoole, and the suite's PHP may load Swoole for the Swoole driver's tests. A Rocketship app runs one or
     * the other. Tests add more to see the server on a PHP that lacks them.
     *
     * @param  list<string>  $extensions
     * @return array<string, string>
     */
    private function without(array $extensions): array
    {
        $scan = PHP_CONFIG_FILE_SCAN_DIR;
        $loaded = array_filter($extensions, extension_loaded(...));

        if ($loaded === [] || $scan === '' || ! is_dir($scan)) {
            return [];
        }

        mkdir($dir = $this->root.'/ini');

        foreach (glob($scan.'/*.ini') ?: [] as $ini) {
            $contents = (string) file_get_contents($ini);

            if (! array_filter($loaded, fn (string $extension) => str_contains($contents, $extension))) {
                copy($ini, $dir.'/'.basename($ini));
            }
        }

        return ['PHP_INI_SCAN_DIR' => $dir];
    }

    /**
     * GET $path over $total fresh connections, $concurrency at a time.
     *
     * @return list<array{status: int, body: string}>
     */
    public function hammer(string $path, int $total, int $concurrency = 16): array
    {
        $multi = curl_multi_init();
        $results = [];
        $queued = 0;
        $active = [];

        $add = function () use ($multi, $path, &$queued, &$active): void {
            $handle = curl_init("http://127.0.0.1:{$this->port}{$path}");
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_FRESH_CONNECT => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_multi_add_handle($multi, $handle);
            $active[spl_object_id($handle)] = $handle;
            $queued++;
        };

        while ($queued < min($concurrency, $total)) {
            $add();
        }

        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.1);
            $this->drain();

            while ($info = curl_multi_info_read($multi)) {
                $handle = $info['handle'];
                $results[] = [
                    'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'body' => (string) curl_multi_getcontent($handle),
                ];
                curl_multi_remove_handle($multi, $handle);
                unset($active[spl_object_id($handle)]);

                if ($queued < $total) {
                    $add();
                }
            }
        } while ($running > 0 || $active !== []);

        curl_multi_close($multi);

        return $results;
    }

    /**
     * Start each request on its own connection once its delay (seconds from now) has passed.
     *
     * @param  list<array{0: string, 1: float}>  $requests  path and delay
     * @return list<array{status: int, body: string}> in the order given
     */
    public function staggered(array $requests): array
    {
        $multi = curl_multi_init();
        $start = microtime(true);
        $pending = $requests;
        $handles = $results = [];

        do {
            foreach ($pending as $index => [$path, $delay]) {
                if (microtime(true) - $start >= $delay) {
                    $handles[$index] = curl_init("http://127.0.0.1:{$this->port}{$path}");
                    curl_setopt_array($handles[$index], [CURLOPT_RETURNTRANSFER => true, CURLOPT_FORBID_REUSE => true, CURLOPT_TIMEOUT => 10]);
                    curl_multi_add_handle($multi, $handles[$index]);
                    unset($pending[$index]);
                }
            }

            curl_multi_exec($multi, $running);
            $running > 0 ? curl_multi_select($multi, 0.02) : usleep(5_000);
            $this->drain();

            while ($info = curl_multi_info_read($multi)) {
                $index = array_search($info['handle'], $handles, true);
                $results[$index] = [
                    'status' => (int) curl_getinfo($info['handle'], CURLINFO_RESPONSE_CODE),
                    'body' => (string) curl_multi_getcontent($info['handle']),
                ];
                curl_multi_remove_handle($multi, $info['handle']);
            }
        } while ($pending !== [] || count($results) < count($requests));

        curl_multi_close($multi);
        ksort($results);

        return array_values($results);
    }

    /** @return array{status: int, body: string} */
    public function get(string $path): array
    {
        return $this->hammer($path, 1)[0];
    }

    /**
     * The worker that answered, waiting up to $timeout for one: after a crash the replacement is still booting
     * and the request sits in the accept queue.
     *
     * @return array<string, mixed>
     */
    public function worker(float $timeout = 20.0): array
    {
        $deadline = microtime(true) + $timeout;

        do {
            $response = $this->get('/worker');
        } while ($response['status'] === 0 && microtime(true) < $deadline);

        if ($response['status'] !== 200) {
            throw new RuntimeException("GET /worker answered {$response['status']}: {$response['body']}\n".$this->output());
        }

        return json_decode($response['body'], true);
    }

    public function supervisorPid(): int
    {
        return is_file($this->stateFile)
            ? (int) (json_decode((string) file_get_contents($this->stateFile), true)['masterProcessId'] ?? 0)
            : 0;
    }

    public function signal(int $signal): void
    {
        // Pid 0 would signal this whole process group, test runner included.
        $pid = $this->supervisorPid() ?: $this->process->getPid();

        if ($pid > 0) {
            posix_kill($pid, $signal);
        }
    }

    /** Wait for the server to exit; returns its exit code, or null if it is still running after $timeout. */
    public function waitForExit(float $timeout = 10.0): ?int
    {
        $deadline = microtime(true) + $timeout;

        while ($this->process->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }

        return $this->process->isRunning() ? null : $this->process->getExitCode();
    }

    public function isListening(): bool
    {
        // PHPUnit records even @-suppressed warnings; a refused probe is an answer here, not an issue.
        set_error_handler(static fn (): bool => true);

        try {
            $socket = stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $error, 0.2);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /** Wait $seconds while still reading the server's output, so nothing in it blocks on a full pipe. */
    public function pause(float $seconds): void
    {
        $until = microtime(true) + $seconds;

        while (microtime(true) < $until) {
            $this->drain();
            usleep(20_000);
        }
    }

    /**
     * Read what the server has written so far. Its stdout is a pipe only Symfony Process reads, and only when
     * asked: a worker dying of a fatal error writes Laravel's HTML error page there from its shutdown handler,
     * and once the pipe is full that worker blocks, holds its control stream open and is never replaced.
     */
    private function drain(): void
    {
        $this->process->isRunning();
    }

    public function output(): string
    {
        return $this->process->getOutput().$this->process->getErrorOutput();
    }

    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->signal(SIGTERM);

            if ($this->waitForExit(10.0) === null) {
                $this->process->stop(0, SIGKILL);
            }
        }

        // A server the start command left behind: Octane's octane:start --server=swoole does not end on SIGTERM
        // here, and killing it orphans swoole-server. Every PHP process naming this app copy goes.
        exec('pgrep -f '.escapeshellarg('php.*'.$this->root), $orphans);
        array_map(fn (string $pid) => posix_kill((int) $pid, SIGKILL), $orphans);

        // A supervisor killed outright leaves its control socket; outside the app root it outlives the rm.
        if (($pid = $this->supervisorPid()) > 0 && file_exists($socket = ControlSocket::path($this->root, $pid))) {
            unlink($socket);
        }

        exec('rm -rf '.escapeshellarg($this->root));
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            if (! $this->process->isRunning()) {
                return; // exited early (boot failure tests read the exit code)
            }

            if ($this->isListening() && $this->get('/robots.txt')['status'] === 200) {
                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException("native server did not start listening:\n".$this->output());
    }

    private static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        return $port;
    }
}
