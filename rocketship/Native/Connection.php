<?php

namespace ProjectSaturnStudios\Rocketship\Native;

use Closure;
use ProjectSaturnStudios\Rocketship\Loop\LoopInterface;
use ProjectSaturnStudios\Rocketship\Native\Http\ExpectsContinue;
use ProjectSaturnStudios\Rocketship\Native\Http\HttpError;
use ProjectSaturnStudios\Rocketship\Native\Http\HttpParser;
use ProjectSaturnStudios\Rocketship\Native\Http\ParsedRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * One client socket: reads and parses requests, hands each to the worker in arrival order, and writes
 * responses back in the same order. A request is served to completion before the next one starts.
 */
final class Connection
{
    public const READ_CHUNK = 65536;

    /** A streamed body blocks its producer once this many bytes are owed to the socket. */
    public const STREAM_HIGH_WATER = 65536;

    /** Seconds a refused connection keeps reading (and discarding) so the client sees the error before the close. */
    public const LINGER = 2.0;

    private readonly WriteQueue $queue;

    /** @var list<ParsedRequest|ExpectsContinue|HttpError> */
    private array $pending = [];

    private bool $handshaking;

    private bool $reading = false;

    private bool $writableArmed = false;

    /** Inside process(): sends queue up and go out once the request is served. */
    private bool $serving = false;

    private bool $closing = false;

    private bool $closed = false;

    private bool $draining = false;

    private int $requests = 0;

    private ?float $lingerUntil = null;

    /** When the request head now arriving began to arrive; null while no head is part way in. */
    private ?float $headSince = null;

    private float $lastActivity;

    private bool $responseStarted = false;

    private bool $keepAlive = true;

    /**
     * @param  resource  $stream  an accepted, non-blocking socket
     * @param  Closure(ParsedRequest, Connection): void  $onRequest  serves the request through this connection's response methods
     * @param  Closure(Connection): void  $onClose
     */
    public function __construct(
        private $stream,
        private readonly LoopInterface $loop,
        private readonly HttpParser $parser,
        private readonly Closure $onRequest,
        private readonly Closure $onClose,
        private readonly ServerOptions $options,
        public readonly string $remoteAddress = '',
        public readonly string $localAddress = '',
    ) {
        $this->queue = new WriteQueue;
        $this->handshaking = $options->tls !== null;
        $this->lastActivity = self::now();

        if ($this->handshaking) {
            foreach ($options->tls as $option => $value) {
                stream_context_set_option($stream, 'ssl', $option, $value);
            }
        }

        $this->resumeReading();
    }

    public function id(): int
    {
        return (int) $this->stream;
    }

    public function isSecure(): bool
    {
        return $this->options->tls !== null;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Mark the current request's response as begun; an error after this point can only close the connection. */
    public function startResponse(): void
    {
        $this->responseStarted = true;
    }

    public function responseStarted(): bool
    {
        return $this->responseStarted;
    }

    /** Whether the response being written may leave the connection open for another request. */
    public function keepAlive(): bool
    {
        return $this->keepAlive;
    }

    /** Close once the current response is out. */
    public function closeAfterResponse(): void
    {
        $this->keepAlive = false;
    }

    /** Queue bytes; they go out when the current request is served (or now, outside one). */
    public function send(string $bytes): void
    {
        if ($this->closed) {
            return;
        }

        $this->queue->push($bytes);

        if (! $this->serving) {
            $this->flush();
        }
    }

    public function sendFile(FileSegment $file): void
    {
        if ($this->closed) {
            $file->release();

            return;
        }

        $this->queue->pushFile($file);

        if (! $this->serving) {
            $this->flush();
        }
    }

    /**
     * Write a streamed body chunk now, blocking this worker while the socket owes more than STREAM_HIGH_WATER.
     * A streamed response runs its callback inside the worker and produces output through an output buffer,
     * which cannot yield to the loop, so its backpressure is a wait on this one socket.
     *
     * @return bool false once the connection is gone; further chunks are dropped
     */
    public function sendNow(string $bytes): bool
    {
        if ($this->closed) {
            return false;
        }

        $this->queue->push($bytes);

        while (true) {
            if (! $this->queue->drain($this->stream)) {
                $this->terminate();

                return false;
            }

            if ($this->queue->bytes() <= self::STREAM_HIGH_WATER) {
                $this->lastActivity = self::now();

                return true;
            }

            $read = $except = null;
            $write = [$this->stream];

            $seconds = (int) $this->options->writeTimeout;
            $micro = (int) (($this->options->writeTimeout - $seconds) * 1e6);

            if (! @stream_select($read, $write, $except, $seconds, $micro)) {
                $this->terminate();

                return false;
            }
        }
    }

    /** Stop taking new requests: close now when idle, otherwise after the response in flight. */
    public function drain(): void
    {
        $this->draining = true;
        $this->keepAlive = false;

        // A connection accepted moments ago may not have sent its request yet: let it, then close.
        // One that never sends is closed by the sweep at the keep-alive timeout. An idle keep-alive has
        // nothing of the client's in flight, so it goes now rather than lingering.
        if ($this->isIdle() && $this->requests > 0) {
            $this->parser->headPending() ? $this->close() : $this->terminate();
        }
    }

    /** No request queued or being served and nothing owed to the socket. */
    public function isIdle(): bool
    {
        return ! $this->serving && $this->pending === [] && $this->queue->isEmpty();
    }

    /** Called by the server's sweep: close an idle keep-alive, finish a linger. */
    public function sweep(float $now): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->lingerUntil !== null) {
            if ($now >= $this->lingerUntil) {
                $this->terminate();
            }

            return;
        }

        if ($this->headSince !== null && ! $this->serving && $now - $this->headSince >= $this->options->headerTimeout) {
            // Every byte resets the keep-alive clock; only a bound on the whole head stops a trickle.
            $this->refuse(new HttpError(408, 'Request header timeout.'));

            return;
        }

        // Part of a head waits on header_timeout, not keep-alive: it gets its 408 there.
        if ($this->isIdle() && $this->headSince === null && $now - $this->lastActivity >= $this->options->keepAliveTimeout) {
            $this->terminate();
        }
    }

    public function handleReadable(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->handshaking) {
            $this->continueHandshake();

            return;
        }

        // Plain TCP: one read per wakeup; the loop is level-triggered. TLS: OpenSSL decrypts whole records
        // into its own buffer, so bytes past this read can sit there with the socket empty; drain to a short read.
        do {
            $data = @fread($this->stream, self::READ_CHUNK);

            if ($data === false || ($data === '' && feof($this->stream))) {
                $this->terminate();

                return;
            }

            if ($data === '') {
                break;
            }

            $this->lastActivity = self::now();

            if (! $this->closing) {
                array_push($this->pending, ...$this->parser->feed($data));
            }
        } while ($this->isSecure() && strlen($data) === self::READ_CHUNK && ! $this->closed);

        $this->headSince = $this->parser->headPending() ? ($this->headSince ?? self::now()) : null;

        $this->process();
    }

    public function handleWritable(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->handshaking) {
            $this->continueHandshake();

            return;
        }

        $this->flush();

        if (! $this->closed && ! $this->serving && $this->queue->bytes() <= $this->options->writeCap) {
            $this->process();
        }
    }

    /** Close once everything owed is written. */
    public function close(): void
    {
        if ($this->closed || $this->closing) {
            return;
        }

        $this->closing = true;
        $this->pending = [];

        if ($this->queue->isEmpty()) {
            $this->finishClose();
        }
    }

    /** The socket's descriptor: in threads mode the master reaches a timed-out request's client through it. */
    public function fd(): int
    {
        return (int) posix_fileno($this->stream);
    }

    /**
     * The request ran past max_execution_time: answer 408 at once, as Octane does on Swoole, and close. Called
     * from a signal handler mid-request, so it skips the queue and writes blocking.
     */
    public function timeOut(): void
    {
        if ($this->closed || ! is_resource($this->stream)) {
            return;
        }

        stream_set_blocking($this->stream, true);
        @fwrite($this->stream, self::plainResponse(408, 'Request Timeout.'));
        $this->terminate();
    }

    /** Write 408 to a worker thread's client, after checking the descriptor still belongs to that client. */
    public static function answerTimedOut(int $fd, bool $tls, string $peer): void
    {
        $socket = @posix_socket_fdopen(posix_dup($fd));

        if (! is_resource($socket)) {
            return;
        }

        if (stream_socket_get_name($socket, true) === $peer) {
            if (! $tls) {
                // TLS state lives in the worker's stream: a TLS client only sees the connection close. One
                // non-blocking try: the copy shares the worker's file status, and a client that stopped reading
                // must never stall the master; with its buffer full it gets the close without the 408.
                stream_set_blocking($socket, false);
                @fwrite($socket, Connection::plainResponse(408, 'Request Timeout.'));
            }

            stream_socket_shutdown($socket, STREAM_SHUT_RDWR);
        }

        fclose($socket);
    }

    /** Tear down now: unwatch, close, drop what is owed, remove upload temp files, notify once. */
    public function terminate(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->pending = [];
        $this->loop->cancelReadable($this->stream);
        $this->loop->cancelWritable($this->stream);
        $this->queue->discard();
        $this->parser->abort();
        @fclose($this->stream);

        ($this->onClose)($this);
    }

    /** Serve queued items in order while the socket keeps up. */
    private function process(): void
    {
        if ($this->serving || $this->closed) {
            return;
        }

        while ($this->pending !== [] && ! $this->closing && ! $this->closed) {
            if ($this->queue->bytes() > $this->options->writeCap) {
                // Too much owed: stop reading until the socket drains; handleWritable() resumes us.
                $this->pauseReading();

                return;
            }

            $item = array_shift($this->pending);

            if ($item instanceof ExpectsContinue) {
                $this->send("HTTP/1.1 100 Continue\r\n\r\n");
            } elseif ($item instanceof HttpError) {
                $this->refuse($item);
            } else {
                $this->serve($item);
            }
        }

        if (! $this->closing && ! $this->closed) {
            $this->resumeReading();
        }
    }

    private function serve(ParsedRequest $request): void
    {
        $this->serving = true;
        $this->requests++;
        $this->responseStarted = false;
        $this->keepAlive = $request->keepAlive && ! $this->draining;

        try {
            ($this->onRequest)($request, $this);

            if (! $this->responseStarted && ! $this->closed) {
                $this->startResponse();
                $this->keepAlive = false;
                $this->send(self::plainResponse(500, 'Internal Server Error'));
            }
        } finally {
            $this->serving = false;

            foreach ($request->multipart?->temporaryFiles ?? [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        $this->lastActivity = self::now();

        if ($this->closed) {
            return;
        }

        if (! $this->keepAlive) {
            $this->close();
        }

        $this->flush();
    }

    /** Answer a request the parser refused, then close without resetting what the client is still sending. */
    private function refuse(HttpError $error): void
    {
        $this->keepAlive = false;
        $this->closing = true;
        $this->pending = [];
        $this->queue->push(self::plainResponse($error->status, $error->getMessage()));
        $this->flush();
    }

    /** Write what the socket takes; arm writable for the rest; finish a close once everything is out. */
    private function flush(): void
    {
        if ($this->closed || $this->handshaking) {
            return;
        }

        if (! $this->queue->drain($this->stream)) {
            $this->terminate();

            return;
        }

        $this->lastActivity = self::now();

        if (! $this->queue->isEmpty()) {
            if (! $this->writableArmed) {
                $this->writableArmed = true;
                $this->loop->onWritable($this->stream, $this->handleWritable(...));
            }

            return;
        }

        if ($this->writableArmed) {
            $this->writableArmed = false;
            $this->loop->cancelWritable($this->stream);
        }

        if ($this->closing) {
            $this->finishClose();
        } elseif ($this->draining && ! $this->serving && $this->pending === []) {
            // The last response of a draining connection is out: nothing else will be served on it.
            $this->close();
        }
    }

    /**
     * Everything is written. A clean close shuts our side and lingers on reads, so a client still sending
     * (a refused upload) reads the response instead of a reset; the sweep ends the linger.
     */
    private function finishClose(): void
    {
        if ($this->lingerUntil !== null || $this->closed) {
            return;
        }

        @stream_socket_shutdown($this->stream, STREAM_SHUT_WR);
        $this->lingerUntil = self::now() + self::LINGER;
        $this->resumeReading();
    }

    private function continueHandshake(): void
    {
        $result = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_SERVER);

        if ($result === false) {
            $this->terminate();

            return;
        }

        if ($result === true) {
            $this->handshaking = false;
            // The client's first request may already sit decrypted inside OpenSSL.
            $this->handleReadable();
        }
        // 0: the handshake wants the client's next flight; the readable watch wakes us.
    }

    private function resumeReading(): void
    {
        if (! $this->reading && ! $this->closed) {
            $this->reading = true;
            $this->loop->onReadable($this->stream, $this->handleReadable(...));
        }
    }

    private function pauseReading(): void
    {
        if ($this->reading) {
            $this->reading = false;
            $this->loop->cancelReadable($this->stream);
        }
    }

    public static function plainResponse(int $status, string $body): string
    {
        $reason = Response::$statusTexts[$status] ?? 'Error';

        return "HTTP/1.1 {$status} {$reason}\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n"
            .'Content-Length: '.strlen($body)."\r\n"
            ."Connection: close\r\n\r\n"
            .$body;
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
