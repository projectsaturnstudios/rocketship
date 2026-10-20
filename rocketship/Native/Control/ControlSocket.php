<?php

namespace ProjectSaturnStudios\Rocketship\Native\Control;

use RuntimeException;

/**
 * The supervisor's control listener: a unix socket every worker connects to at boot. The supervisor
 * writes reload and stop frames down each connection; a worker's death closes its end.
 */
final class ControlSocket
{
    /** Longest unix socket path: sun_path holds 108 bytes on Linux, 104 on macOS and the BSDs, NUL included. */
    public static function maxPathLength(): int
    {
        return PHP_OS_FAMILY === 'Linux' ? 107 : 103;
    }

    /**
     * storage/rocketship/control-<pid>.sock inside the app. An app deep enough to overflow sun_path gets a
     * socket in the temp dir instead, named for its base path, or in /tmp when the temp dir is that deep too.
     */
    public static function path(string $basePath, int $pid, ?string $tempDir = null): string
    {
        $name = self::fallbackPrefix($basePath)."{$pid}.sock";

        foreach ([
            rtrim($basePath, '/')."/storage/rocketship/control-{$pid}.sock",
            rtrim($tempDir ?? sys_get_temp_dir(), '/').'/'.$name,
        ] as $path) {
            if (strlen($path) <= self::maxPathLength()) {
                return $path;
            }
        }

        return '/tmp/'.$name;
    }

    /**
     * Remove the sockets this app's supervisors left when they were killed outright, wherever path() put them.
     * A socket whose supervisor is alive, or whose pid now belongs to another user, stays.
     */
    public static function removeStale(string $basePath, ?string $tempDir = null): void
    {
        $prefix = self::fallbackPrefix($basePath);

        foreach ([
            rtrim($basePath, '/').'/storage/rocketship/control-*.sock',
            rtrim($tempDir ?? sys_get_temp_dir(), '/')."/{$prefix}*.sock",
            "/tmp/{$prefix}*.sock",
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                if (preg_match('/-(\d+)\.sock$/', $path, $pid)
                    && ! posix_kill((int) $pid[1], 0) && posix_get_last_error() === PCNTL_ESRCH) {
                    @unlink($path);
                }
            }
        }
    }

    private static function fallbackPrefix(string $basePath): string
    {
        return 'rocketship-'.substr(hash('xxh3', $basePath), 0, 8).'-';
    }

    /**
     * Listen at $path, readable and writable by this user only.
     *
     * @return resource a non-blocking listener
     */
    public static function listen(string $path)
    {
        if (! is_dir($directory = dirname($path)) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the control socket directory [{$directory}].");
        }

        // A socket file left by a supervisor that died with this pid refuses the bind.
        if (file_exists($path)) {
            unlink($path);
        }

        $previous = umask(0177);

        try {
            // A deep queue, so a burst of connections (every worker at once, or a local flood) is not refused.
            $context = stream_context_create(['socket' => ['backlog' => 511]]);
            $listener = stream_socket_server("unix://{$path}", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        } finally {
            umask($previous);
        }

        if ($listener === false) {
            throw new RuntimeException("Could not listen on the control socket [{$path}]: {$error} ({$errno})");
        }

        stream_set_blocking($listener, false);

        return $listener;
    }
}
