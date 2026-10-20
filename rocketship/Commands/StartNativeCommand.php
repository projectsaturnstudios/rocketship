<?php

declare(strict_types=1);

namespace ProjectSaturnStudios\Rocketship\Commands;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Octane\Commands\Command;
use Laravel\Octane\Commands\Concerns\InteractsWithEnvironmentVariables;
use Laravel\Octane\Commands\Concerns\InteractsWithServers;
use ProjectSaturnStudios\Rocketship\Native\ServerProcessInspector;
use ProjectSaturnStudios\Rocketship\Native\ServerStateFile;
use ProjectSaturnStudios\Rocketship\Native\WorkerMode;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'octane:native')]
class StartNativeCommand extends Command implements SignalableCommandInterface
{
    use InteractsWithEnvironmentVariables, InteractsWithServers;

    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'octane:native
                    {--host= : The IP address the server should bind to}
                    {--port= : The port the server should be available on}
                    {--workers=auto : The number of workers that should be available to handle requests}
                    {--max-requests=500 : The number of requests to process before reloading the server}
                    {--mode= : threads, processes or auto [default: octane.native.mode]}
                    {--watch : Automatically reload the server when the application is modified}
                    {--poll : Use file system polling while watching in order to watch files over a network}';

    /**
     * The command's description.
     *
     * @var string
     */
    public $description = 'Start the Octane native server';

    /**
     * Indicates whether the command should be shown in the Artisan command list.
     *
     * @var bool
     */
    protected $hidden = true;

    /**
     * Handle the command.
     *
     * @return int
     */
    public function handle(ServerProcessInspector $inspector, ServerStateFile $serverStateFile)
    {
        try {
            $mode = WorkerMode::resolve($this->option('mode') ?: (string) config('octane.native.mode', 'auto'));
            $tls = $this->tls();
        } catch (RuntimeException|InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return 1;
        }

        $this->ensurePortIsAvailable();

        if ($inspector->serverIsRunning()) {
            $this->components->error('Native server is already running.');

            return 1;
        }

        $this->forgetEnvironmentVariables();

        $serverStateFile->writeState([
            'appName' => config('app.name', 'Laravel'),
            'host' => $this->getHost(),
            'port' => (int) $this->getPort(),
            'workers' => $this->workerCount(),
            'maxRequests' => (int) $this->option('max-requests'),
            'mode' => $mode->value,
            'tls' => $tls,
            'publicPath' => public_path(),
            'octaneConfig' => config('octane'),
        ]);

        $server = tap(new Process([
            (new PhpExecutableFinder)->find(),
            ...(config('octane.native.php_options') ?? []),
            'native-server',
            $serverStateFile->path(),
        ], realpath(__DIR__.'/../../bin'), [
            'APP_ENV' => app()->environment(),
            'APP_BASE_PATH' => base_path(),
            'LARAVEL_OCTANE' => 1,
        ], null, null))->start();

        return $this->runServer($server, $inspector, 'native');
    }

    /**
     * TLS when octane.native.tls names a certificate. A PEM file may hold the key as well, so the key is optional.
     *
     * @return array{cert: string, key: ?string}|null
     */
    protected function tls(): ?array
    {
        $cert = config('octane.native.tls.cert');
        $key = config('octane.native.tls.key');

        if (! $cert) {
            return null;
        }

        foreach (array_filter([$cert, $key]) as $file) {
            if (! is_readable($file)) {
                throw new InvalidArgumentException("Native TLS file [{$file}] is not readable.");
            }
        }

        return ['cert' => $cert, 'key' => $key ?: null];
    }

    protected function workerCount(): int
    {
        return $this->option('workers') === 'auto'
            ? self::cpuCount()
            : max(1, (int) $this->option('workers'));
    }

    /** Online CPUs, as Swoole's cpuCount() reports them. */
    public static function cpuCount(): int
    {
        $count = PHP_OS_FAMILY === 'Darwin'
            ? (int) shell_exec('sysctl -n hw.ncpu 2>/dev/null')
            : (int) shell_exec('nproc 2>/dev/null');

        return max(1, $count);
    }

    /**
     * Write the server process output to the console.
     *
     * @param  \Symfony\Component\Process\Process  $server
     * @return void
     */
    protected function writeServerOutput($server)
    {
        [$output, $errorOutput] = $this->getServerOutput($server);

        Str::of($output)
            ->explode("\n")
            ->filter()
            ->each(fn ($output) => is_array($stream = json_decode($output, true))
                ? $this->handleStream($stream)
                : $this->components->info($output)
            );

        Str::of($errorOutput)
            ->explode("\n")
            ->filter()
            ->groupBy(fn ($output) => $output)
            ->each(function ($group) {
                is_array($stream = json_decode($output = $group->first(), true)) && isset($stream['type'])
                    ? $this->handleStream($stream)
                    : $this->raw($output);
            });
    }

    /**
     * Stop the server.
     *
     * @return void
     */
    protected function stopServer()
    {
        $this->callSilent('octane:stop', [
            '--server' => 'native',
        ]);
    }
}
