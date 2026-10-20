<?php

namespace ProjectSaturnStudios\Rocketship\Native;

/** A span of an open file queued for the socket; read in chunks as the socket drains. */
final class FileSegment
{
    public const CHUNK = 65536;

    /** Bytes read from the file and not yet taken by the socket. */
    public string $pending = '';

    /**
     * @param  resource  $handle  positioned at the first byte to send
     * @param  ?string  $deleteAfter  removed once the segment is sent or dropped (BinaryFileResponse::deleteFileAfterSend)
     */
    public function __construct(
        private $handle,
        public int $remaining,
        private readonly ?string $deleteAfter = null,
    ) {
    }

    /** Refill $pending from the file; false when the file ended before $remaining bytes. */
    public function fill(): bool
    {
        if ($this->pending !== '' || $this->remaining === 0) {
            return true;
        }

        $chunk = fread($this->handle, min(self::CHUNK, $this->remaining));

        if ($chunk === false || $chunk === '') {
            return false;
        }

        $this->pending = $chunk;
        $this->remaining -= strlen($chunk);

        return true;
    }

    public function done(): bool
    {
        return $this->pending === '' && $this->remaining === 0;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        if ($this->deleteAfter !== null && is_file($this->deleteAfter)) {
            @unlink($this->deleteAfter);
        }
    }
}
