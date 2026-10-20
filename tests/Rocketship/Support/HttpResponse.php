<?php

declare(strict_types=1);

namespace ProjectSaturnStudios\Rocketship\Tests\Support;

/** A response as a client read it off the wire. */
final class HttpResponse
{
    /**
     * @param  array<string, list<string>>  $headers  lower-cased names
     * @param  string  $head  the status line and header block as sent, through the blank line
     */
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $head,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return mixed the body decoded as JSON */
    public function json(): mixed
    {
        return json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Split a client's read buffer into complete responses. A 1xx is a response of its own.
     *
     * @param  bool  $head  responses answer HEAD requests: no body follows whatever the headers say
     * @param  bool  $closed  the server closed: a body without a length ends here
     * @return array{0: list<self>, 1: string} responses and the unparsed remainder
     */
    public static function parseAll(string $buffer, bool $head = false, bool $closed = false): array
    {
        // Offsets, not substr copies: test buffers run to tens of megabytes under a 128 MB limit.
        $responses = [];
        $offset = 0;

        while (($end = strpos($buffer, "\r\n\r\n", $offset)) !== false) {
            $lines = explode("\r\n", substr($buffer, $offset, $end - $offset));
            [, $status, $reason] = array_pad(explode(' ', array_shift($lines), 3), 3, '');
            $headers = [];

            foreach ($lines as $line) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)][] = trim($value);
            }

            $status = (int) $status;
            $bodyStart = $end + 4;

            if ($head || $status < 200 || $status === 204 || $status === 304) {
                $body = '';
                $bodyEnd = $bodyStart;
            } elseif (isset($headers['transfer-encoding'])) {
                [$body, $bodyEnd] = self::dechunk($buffer, $bodyStart);

                if ($bodyEnd === null) {
                    break;
                }
            } elseif (isset($headers['content-length'])) {
                $bodyEnd = $bodyStart + (int) $headers['content-length'][0];

                if (strlen($buffer) < $bodyEnd) {
                    break;
                }

                $body = substr($buffer, $bodyStart, $bodyEnd - $bodyStart);
            } elseif ($closed) {
                $bodyEnd = strlen($buffer);
                $body = substr($buffer, $bodyStart);
            } else {
                break;
            }

            $responses[] = new self($status, $reason, $headers, $body, substr($buffer, $offset, $bodyStart - $offset));
            $offset = $bodyEnd;
        }

        return [$responses, substr($buffer, $offset)];
    }

    /** @return array{0: string, 1: ?int} body and the offset past it, null while incomplete */
    private static function dechunk(string $buffer, int $offset): array
    {
        $body = '';

        while (($lineEnd = strpos($buffer, "\r\n", $offset)) !== false) {
            $size = (int) hexdec(explode(';', substr($buffer, $offset, $lineEnd - $offset))[0]);
            $offset = $lineEnd + 2;

            if ($size === 0) {
                return substr($buffer, $offset, 2) === "\r\n" ? [$body, $offset + 2] : [$body, null];
            }

            if (strlen($buffer) < $offset + $size + 2) {
                return [$body, null];
            }

            $body .= substr($buffer, $offset, $size);
            $offset += $size + 2;
        }

        return [$body, null];
    }
}
