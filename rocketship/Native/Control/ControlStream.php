<?php

namespace ProjectSaturnStudios\Rocketship\Native\Control;

use ProjectSaturnStudios\Rocketship\Loop\LoopInterface;

/**
 * One end of a control connection. Frames are single lines: a worker sends `hello <token>`, then `ready`
 * once it serves or `failed` when it cannot boot; the supervisor sends `reload` or `stop`.
 */
final class ControlStream
{
    /** Longest frame: a hello with its token and secret is well under this. */
    public const MAX_FRAME = 1024;

    private string $buffer = '';

    /** Frames written but not yet taken by the socket: never dropped, never split. */
    private string $outbound = '';

    private ?LoopInterface $loop = null;

    private bool $watchingWritable = false;

    /** @param resource $stream */
    public function __construct(public readonly mixed $stream)
    {
        stream_set_blocking($stream, false);
    }

    /**
     * Connect to the supervisor's control socket, retrying until $timeout: a unix connect fails at once, without
     * waiting, while the socket is missing or its listen queue is full (a busy master, or a local flood).
     */
    public static function connect(string $path, float $timeout = 5.0): ?self
    {
        $deadline = microtime(true) + $timeout;
        set_error_handler(static fn (): bool => true);

        try {
            while (($stream = stream_socket_client("unix://{$path}", $errno, $error, $timeout)) === false
                && microtime(true) < $deadline) {
                usleep(50_000);
            }
        } finally {
            restore_error_handler();
        }

        return $stream === false ? null : new self($stream);
    }

    /** Flush what the socket would not take from this loop, once it is writable again. */
    public function attach(LoopInterface $loop): void
    {
        $this->loop = $loop;
        $this->flush();
    }

    /** Queue a frame and write what the socket takes now; a peer that is gone shows up as EOF on read. */
    public function send(string $frame): void
    {
        $this->outbound .= $frame."\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (! is_resource($this->stream)) {
            $this->outbound = '';

            return;
        }

        if ($this->outbound !== '') {
            $written = @fwrite($this->stream, $this->outbound);
            $this->outbound = $written === false ? $this->outbound : substr($this->outbound, $written);
        }

        if ($this->outbound !== '' && $this->loop !== null && ! $this->watchingWritable) {
            $this->watchingWritable = true;
            $this->loop->onWritable($this->stream, $this->flush(...));
        } elseif ($this->outbound === '' && $this->watchingWritable) {
            $this->watchingWritable = false;
            $this->loop?->cancelWritable($this->stream);
        }
    }

    /**
     * The complete frames that have arrived; a partial line waits for the rest.
     *
     * @return list<string>|null null once the peer has closed its end
     */
    public function receive(): ?array
    {
        $data = is_resource($this->stream) ? @fread($this->stream, 8192) : false;

        if ($data === false || ($data === '' && feof($this->stream))) {
            return null;
        }

        $frames = explode("\n", $this->buffer.$data);
        $this->buffer = array_pop($frames);

        // A peer that never ends a frame is broken or hostile: treat it as gone rather than buffer it forever.
        return strlen($this->buffer) > self::MAX_FRAME ? null : $frames;
    }

    public function close(): void
    {
        if ($this->watchingWritable) {
            $this->watchingWritable = false;
            $this->loop?->cancelWritable($this->stream);
        }

        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
