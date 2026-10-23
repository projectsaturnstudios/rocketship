<?php

declare(strict_types=1);

namespace ProjectSaturnStudios\Rocketship\Servers;

use Laravel\Octane\Commands\InstallCommand;
use Laravel\Octane\Commands\ReloadCommand;
use Laravel\Octane\Commands\StartCommand;
use Laravel\Octane\Commands\StatusCommand;
use Laravel\Octane\Commands\StopCommand;
use ProjectSaturnStudios\Rocketship\Native\ServerProcessInspector;
use ProjectSaturnStudios\Rocketship\Native\ServerStateFile;
use ProjectSaturnStudios\Rocketship\Native\WorkerMode;

/** The native server, with the same messages and exit codes Octane's commands give the Swoole server. */
final class NativeServer implements ServerDriver
{
    public function __construct(
        private readonly ServerProcessInspector $inspector,
        private readonly ServerStateFile $stateFile,
    ) {
    }

    public function start(StartCommand $command): int
    {
        return $command->call('octane:native', [
            // Octane's getHost() and getPort(), which the Swoole start passes on.
            '--host' => $command->option('host') ?? config('octane.host') ?? $_ENV['OCTANE_HOST'] ?? '127.0.0.1',
            '--port' => $command->option('port') ?? config('octane.port') ?? $_ENV['OCTANE_PORT'] ?? '8000',
            '--workers' => $command->option('workers') ?: config('octane.workers', 'auto'),
            '--max-requests' => $command->option('max-requests') ?: config('octane.max_requests', 500),
            '--watch' => $command->option('watch'),
            '--poll' => $command->option('poll'),
        ]);
    }

    public function stop(StopCommand $command): int
    {
        if (! $this->inspector->serverIsRunning()) {
            $this->stateFile->delete();
            $command->outputComponents()->error('Native server is not running.');

            return 1;
        }

        $command->outputComponents()->info('Stopping server...');

        if (! $this->inspector->stopServer()) {
            $command->outputComponents()->error('Failed to stop native server.');

            return 1;
        }

        $this->stateFile->delete();

        return 0;
    }

    public function reload(ReloadCommand $command): int
    {
        if (! $this->inspector->serverIsRunning()) {
            $command->outputComponents()->error('Octane server is not running.');

            return 1;
        }

        $command->outputComponents()->info('Reloading workers...');
        $this->inspector->reloadServer();

        return 0;
    }

    public function running(StatusCommand $command): bool
    {
        return $this->inspector->serverIsRunning();
    }

    public function install(InstallCommand $command): bool
    {
        // As Octane's Swoole install: name what is missing, install anyway.
        if (! WorkerMode::processesAvailable()) {
            $command->outputComponents()->warn('The native server needs ext-pcntl and ext-posix.');
        }

        if (($listen = WorkerMode::slotListenUnavailable()) !== null) {
            $command->outputComponents()->warn("The native server needs {$listen}.");
        }

        if (! extension_loaded('epoll') && ! extension_loaded('kqueue')) {
            $command->outputComponents()->warn('The native server runs on select() without ext-epoll (Linux) or ext-kqueue (macOS).');
        }

        if (($threads = WorkerMode::threadsUnavailable()) !== null) {
            $command->outputComponents()->warn("Native threads mode needs {$threads}; processes mode will be used.");
        }

        return true;
    }
}
