<?php

declare(strict_types=1);

namespace ProjectSaturnStudios\Rocketship\Native;

use Laravel\Octane\Contracts\ServerProcessInspector as ServerProcessInspectorContract;
use Laravel\Octane\PosixExtension;

/** The native master as Octane's commands see it: alive, reload (SIGUSR1) and drain-then-exit (SIGTERM). */
class ServerProcessInspector implements ServerProcessInspectorContract
{
    public function __construct(
        protected ServerStateFile $serverStateFile,
        protected PosixExtension $posix,
    ) {
    }

    public function serverIsRunning(): bool
    {
        $masterProcessId = $this->serverStateFile->read()['masterProcessId'];

        return $masterProcessId && $this->posix->kill($masterProcessId, 0);
    }

    public function reloadServer(): void
    {
        if ($masterProcessId = $this->serverStateFile->read()['masterProcessId']) {
            $this->posix->kill($masterProcessId, SIGUSR1);
        }
    }

    public function stopServer(): bool
    {
        $masterProcessId = $this->serverStateFile->read()['masterProcessId'];

        return $masterProcessId && $this->posix->kill($masterProcessId, SIGTERM);
    }
}
