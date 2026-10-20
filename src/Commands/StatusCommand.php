<?php

namespace Laravel\Octane\Commands;

use Laravel\Octane\FrankenPhp\ServerProcessInspector as FrankenPhpServerProcessInspector;
use Laravel\Octane\RoadRunner\ServerProcessInspector as RoadRunnerServerProcessInspector;
use Laravel\Octane\Swoole\ServerProcessInspector as SwooleServerProcessInspector;
use ProjectSaturnStudios\Rocketship\Servers\ServerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'octane:status')]
class StatusCommand extends Command
{
    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'octane:status {--server= : The server that is running the application}';

    /**
     * The command's description.
     *
     * @var string
     */
    public $description = 'Get the current status of the Octane server';

    /**
     * Handle the command.
     *
     * @return int
     */
    public function handle()
    {
        $server = $this->option('server') ?: config('octane.server');

        $servers = app(ServerRegistry::class);

        $isRunning = is_string($server) && $servers->has($server)
            ? $servers->driver($server)->running($this)
            : $this->invalidServer($server);

        return ! tap($isRunning, function ($isRunning) {
            $isRunning
                ? $this->components->info('Octane server is running.')
                : $this->components->info('Octane server is not running.');
        });
    }

    /**
     * Check if the Swoole server is running.
     *
     * @return bool
     */
    public function isSwooleServerRunning()
    {
        return app(SwooleServerProcessInspector::class)
            ->serverIsRunning();
    }

    /**
     * Check if the RoadRunner server is running.
     *
     * @return bool
     */
    public function isRoadRunnerServerRunning()
    {
        return app(RoadRunnerServerProcessInspector::class)
            ->serverIsRunning();
    }

    /**
     * Check if the FrankenPHP server is running.
     *
     * @return bool
     */
    public function isFrankenPhpServerRunning()
    {
        return app(FrankenPhpServerProcessInspector::class)
            ->serverIsRunning();
    }

    /**
     * Inform the user that the server type is invalid.
     *
     * @return bool
     */
    protected function invalidServer(string $server)
    {
        $this->components->error("Invalid server: {$server}.");

        return false;
    }
}
