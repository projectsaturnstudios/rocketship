<?php

namespace ProjectSaturnStudios\Rocketship\Native;

/** Bytes owed to one socket, in order: strings and file spans. */
final class WriteQueue
{
    /** Small writes merge into the string ahead of them up to this size, so one response goes out in one write. */
    private const MERGE_LIMIT = 1_048_576;

    /** @var list<string|FileSegment> */
    private array $segments = [];

    private int $bytes = 0;

    public function push(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        $this->bytes += strlen($bytes);
        $last = array_key_last($this->segments);

        if ($last !== null && is_string($this->segments[$last]) && strlen($this->segments[$last]) + strlen($bytes) <= self::MERGE_LIMIT) {
            $this->segments[$last] .= $bytes;

            return;
        }

        $this->segments[] = $bytes;
    }

    public function pushFile(FileSegment $file): void
    {
        if ($file->done()) {
            $file->release();

            return;
        }

        $this->bytes += $file->remaining;
        $this->segments[] = $file;
    }

    public function isEmpty(): bool
    {
        return $this->segments === [];
    }

    /** Bytes still owed, file spans included. */
    public function bytes(): int
    {
        return $this->bytes;
    }

    /**
     * Write until the socket stops taking bytes or the queue empties.
     *
     * @param  resource  $stream  a non-blocking socket
     * @return bool false when the socket failed or a file ended early; the connection is beyond saving
     */
    public function drain($stream): bool
    {
        while ($this->segments !== []) {
            $head = $this->segments[0];

            if ($head instanceof FileSegment) {
                if (! $head->fill()) {
                    return false;
                }

                if ($head->done()) {
                    $head->release();
                    array_shift($this->segments);

                    continue;
                }

                $written = @fwrite($stream, $head->pending);

                if ($written === false) {
                    return false;
                }

                $this->bytes -= $written;
                $head->pending = substr($head->pending, $written);

                if ($head->pending !== '') {
                    return true;
                }

                continue;
            }

            $written = @fwrite($stream, $head);

            if ($written === false) {
                return false;
            }

            $this->bytes -= $written;

            if ($written < strlen($head)) {
                $this->segments[0] = substr($head, $written);

                return true;
            }

            array_shift($this->segments);
        }

        return true;
    }

    /** Drop everything owed, closing (and deleting where asked) the files behind it. */
    public function discard(): void
    {
        foreach ($this->segments as $segment) {
            if ($segment instanceof FileSegment) {
                $segment->release();
            }
        }

        $this->segments = [];
        $this->bytes = 0;
    }
}
