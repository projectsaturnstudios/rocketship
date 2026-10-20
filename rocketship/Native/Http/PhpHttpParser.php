<?php

namespace ProjectSaturnStudios\Rocketship\Native\Http;

/**
 * HTTP/1.1 request parser (RFC 9112) in PHP. Strict where leniency lets a front proxy and this server
 * disagree on where a request ends: CRLF line endings only, no obs-fold, no Content-Length beside
 * Transfer-Encoding, chunked as the only and final transfer coding.
 */
final class PhpHttpParser implements HttpParser
{
    private const HEAD = 0;

    private const FIXED_BODY = 1;

    private const CHUNK_SIZE = 2;

    private const CHUNK_DATA = 3;

    private const CHUNK_END = 4;

    private const TRAILERS = 5;

    private const FAILED = 6;

    /** Bound on a chunk-size line, extensions included. */
    private const MAX_CHUNK_LINE = 4096;

    private const TOKEN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

    private string $buffer = '';

    private int $state = self::HEAD;

    /** Bytes left in the fixed-length body or the current chunk. */
    private int $remaining = 0;

    private int $bodyBytes = 0;

    private int $trailerBytes = 0;

    private string $body = '';

    /** A body past SPOOL_BYTES goes to a temp file as it arrives, so unserved uploads do not fill the worker. */
    private const SPOOL_BYTES = 1_048_576;

    /** @var resource|null */
    private $spool = null;

    private ?MultipartParser $multipart = null;

    /** @var array{method: string, target: string, path: string, query: string, version: string, headers: array<string, list<string>>, keepAlive: bool}|null */
    private ?array $head = null;

    /** While a head arrives: offset of its first header line (null before the request line is complete). */
    private ?int $headStart = null;

    /** How far the header lines have been read, so a feed parses only what is new. */
    private int $headOffset = 0;

    /** @var array<string, list<string>> the header lines read so far */
    private array $headHeaders = [];

    public function __construct(private readonly ParserLimits $limits)
    {
    }

    public function feed(string $bytes): array
    {
        if ($this->state === self::FAILED) {
            return [];
        }

        $this->buffer .= $bytes;
        $items = [];

        try {
            while (($item = $this->step()) !== null) {
                if ($item !== true) {
                    $items[] = $item;
                }
            }
        } catch (HttpError $e) {
            $this->abort();
            $this->state = self::FAILED;
            $items[] = $e;
        }

        return $items;
    }

    public function abort(): void
    {
        $this->multipart?->abort();
        $this->multipart = null;
        $this->buffer = $this->body = '';

        if (is_resource($this->spool)) {
            fclose($this->spool);
        }

        $this->spool = null;
        $this->head = null;
        $this->headStart = null;
        $this->headHeaders = [];
        $this->state = self::HEAD;
    }

    public function headPending(): bool
    {
        return $this->state === self::HEAD && $this->buffer !== '';
    }

    /** @return ParsedRequest|ExpectsContinue|true|null a finished item, true for progress, null when more bytes are needed */
    private function step(): ParsedRequest|ExpectsContinue|bool|null
    {
        return match ($this->state) {
            self::HEAD => $this->readHead(),
            self::FIXED_BODY => $this->readFixedBody(),
            self::CHUNK_SIZE => $this->readChunkSize(),
            self::CHUNK_DATA => $this->readChunkData(),
            self::CHUNK_END => $this->readChunkEnd(),
            self::TRAILERS => $this->readTrailers(),
            default => null,
        };
    }

    private function readHead(): ParsedRequest|ExpectsContinue|bool|null
    {
        if ($this->headStart === null) {
            // Empty lines ahead of a request line are ignored (RFC 9112 §2.2).
            while (str_starts_with($this->buffer, "\r\n")) {
                $this->buffer = substr($this->buffer, 2);
            }

            $lineEnd = strpos($this->buffer, "\n");

            if ($lineEnd === false) {
                if (strlen($this->buffer) > $this->limits->maxRequestLine) {
                    throw new HttpError(414, 'Request line too long.');
                }

                return null;
            }

            if ($lineEnd > $this->limits->maxRequestLine) {
                throw new HttpError(414, 'Request line too long.');
            }

            if ($lineEnd === 0 || $this->buffer[$lineEnd - 1] !== "\r") {
                throw new HttpError(400, 'Lines must end in CRLF.');
            }

            $this->headStart = $this->headOffset = $lineEnd + 1;
            $this->headHeaders = [];
        }

        $headStart = $this->headStart;
        $offset = $this->headOffset;
        $headers = $this->headHeaders;

        while (true) {
            $end = strpos($this->buffer, "\n", $offset);

            if ($end === false) {
                if (strlen($this->buffer) - $headStart > $this->limits->maxHeaderBytes) {
                    throw new HttpError(431, 'Request header fields too large.');
                }

                // Resume here on the next feed instead of re-reading every line.
                $this->headOffset = $offset;
                $this->headHeaders = $headers;

                return null;
            }

            if ($end + 1 - $headStart > $this->limits->maxHeaderBytes) {
                throw new HttpError(431, 'Request header fields too large.');
            }

            if ($this->buffer[$end - 1] !== "\r") {
                throw new HttpError(400, 'Lines must end in CRLF.');
            }

            $line = substr($this->buffer, $offset, $end - 1 - $offset);
            $offset = $end + 1;

            if ($line === '') {
                break;
            }

            $this->addHeader($headers, $line);
        }

        $requestLine = substr($this->buffer, 0, $headStart - 2);
        $this->buffer = substr($this->buffer, $offset);
        $this->headStart = null;
        $this->headHeaders = [];

        return $this->begin($requestLine, $headers);
    }

    /** @param array<string, list<string>> $headers */
    private function addHeader(array &$headers, string $line): void
    {
        if ($line[0] === ' ' || $line[0] === "\t") {
            throw new HttpError(400, 'Obsolete header line folding.');
        }

        $colon = strpos($line, ':');

        if ($colon === false || ! preg_match(self::TOKEN, $name = substr($line, 0, $colon))) {
            throw new HttpError(400, 'Malformed header field.');
        }

        $value = trim(substr($line, $colon + 1), " \t");

        if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value)) {
            throw new HttpError(400, 'Control character in header value.');
        }

        $headers[strtolower($name)][] = $value;
    }

    /** @param array<string, list<string>> $headers */
    private function begin(string $requestLine, array $headers): ExpectsContinue|ParsedRequest|bool
    {
        $parts = explode(' ', $requestLine);

        if (count($parts) !== 3) {
            throw new HttpError(400, 'Malformed request line.');
        }

        [$method, $target, $protocol] = $parts;

        if (! preg_match('#^HTTP/(\d)\.(\d)$#', $protocol, $match)) {
            throw new HttpError(400, 'Malformed protocol version.');
        }

        $version = "{$match[1]}.{$match[2]}";

        if ($version !== '1.1' && $version !== '1.0') {
            throw new HttpError(505, 'HTTP version not supported.');
        }

        if (! preg_match(self::TOKEN, $method)) {
            throw new HttpError(400, 'Malformed method.');
        }

        if (! preg_match('/^[\x21-\x7e]+$/', $target)) {
            throw new HttpError(400, 'Malformed request target.');
        }

        $hosts = $headers['host'] ?? [];

        if (count($hosts) > 1 || ($version === '1.1' && $hosts === [])) {
            throw new HttpError(400, 'A request needs exactly one Host header.');
        }

        [$path, $query] = $this->resolveTarget($method, $target, $headers);
        [$chunked, $length] = $this->framing($version, $headers);

        $connection = $this->values($headers['connection'] ?? []);
        $keepAlive = $version === '1.1'
            ? ! in_array('close', $connection, true)
            : in_array('keep-alive', $connection, true);

        $continue = false;

        if (isset($headers['expect']) && $version === '1.1') {
            if ($this->values($headers['expect']) !== ['100-continue']) {
                throw new HttpError(417, 'Expectation failed.');
            }

            $continue = $chunked || $length > 0;
        }

        $this->body = '';
        $this->bodyBytes = 0;
        $this->multipart = null;

        if (($chunked || $length > 0) && preg_match('#^multipart/form-data\s*(;|$)#i', $headers['content-type'][0] ?? '')) {
            $this->multipart = new MultipartParser(
                $this->boundary($headers['content-type'][0]),
                $this->limits->uploadDirectory,
                $this->limits->maxFileSize,
                $this->limits->maxFiles,
            );
        }

        $this->head = compact('method', 'target', 'path', 'query', 'version', 'headers', 'keepAlive');

        if ($chunked) {
            $this->state = self::CHUNK_SIZE;
        } elseif ($length > 0) {
            $this->state = self::FIXED_BODY;
            $this->remaining = $length;
        } else {
            return $this->complete();
        }

        $owed = $chunked ? $this->buffer === '' : strlen($this->buffer) < $length;

        return $continue && $owed ? new ExpectsContinue : true;
    }

    /**
     * @param  array<string, list<string>>  $headers  the absolute form's authority replaces Host (RFC 9112 §3.2.2)
     * @return array{0: string, 1: string} path and query
     */
    private function resolveTarget(string $method, string $target, array &$headers): array
    {
        if ($target === '*') {
            if ($method !== 'OPTIONS') {
                throw new HttpError(400, 'The asterisk form is for OPTIONS.');
            }

            return ['*', ''];
        }

        if ($target[0] === '/') {
            $parts = explode('?', $target, 2);

            return [$parts[0], $parts[1] ?? ''];
        }

        if (preg_match('#^https?://#i', $target)) {
            $url = parse_url($target);

            if ($url === false || ! isset($url['host'])) {
                throw new HttpError(400, 'Malformed absolute request target.');
            }

            $headers['host'] = [$url['host'].(isset($url['port']) ? ':'.$url['port'] : '')];

            return [$url['path'] ?? '/', $url['query'] ?? ''];
        }

        throw new HttpError(400, 'Malformed request target.');
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @return array{0: bool, 1: int} chunked, fixed length
     */
    private function framing(string $version, array $headers): array
    {
        if (isset($headers['transfer-encoding'])) {
            $codings = $this->values($headers['transfer-encoding']);

            if ($version === '1.0') {
                throw new HttpError(400, 'Transfer-Encoding is not HTTP/1.0 framing.');
            }

            if (isset($headers['content-length'])) {
                throw new HttpError(400, 'Content-Length beside Transfer-Encoding.');
            }

            if (end($codings) !== 'chunked' || in_array('chunked', array_slice($codings, 0, -1), true)) {
                throw new HttpError(400, 'Chunked must be the final transfer coding, applied once.');
            }

            if (count($codings) > 1) {
                throw new HttpError(501, 'Unsupported transfer coding.');
            }

            return [true, 0];
        }

        if (! isset($headers['content-length'])) {
            return [false, 0];
        }

        $lengths = $this->values($headers['content-length']);

        if ($lengths === [] || count(array_unique($lengths)) !== 1 || ! ctype_digit($lengths[0])) {
            throw new HttpError(400, 'Malformed Content-Length.');
        }

        $digits = ltrim($lengths[0], '0');

        if (strlen($digits) > 18 || (int) $digits > $this->limits->maxBody) {
            throw new HttpError(413, 'Payload too large.');
        }

        return [false, (int) $digits];
    }

    private function boundary(string $contentType): string
    {
        if (! preg_match('/;\s*boundary\s*=\s*(?:"([^"]{1,70})"|([^\s;,"]{1,70}))/i', $contentType, $match)) {
            throw new HttpError(400, 'Multipart body without a boundary.');
        }

        return ($match[2] ?? '') !== '' ? $match[2] : $match[1];
    }

    private function readFixedBody(): ParsedRequest|bool|null
    {
        if (! $this->take()) {
            return null;
        }

        return $this->remaining === 0 ? $this->complete() : null;
    }

    private function readChunkSize(): bool|null
    {
        $end = strpos($this->buffer, "\n");

        if ($end === false) {
            if (strlen($this->buffer) > self::MAX_CHUNK_LINE) {
                throw new HttpError(400, 'Chunk size line too long.');
            }

            return null;
        }

        if ($end > self::MAX_CHUNK_LINE || $end === 0 || $this->buffer[$end - 1] !== "\r") {
            throw new HttpError(400, 'Malformed chunk size line.');
        }

        $size = rtrim(explode(';', substr($this->buffer, 0, $end - 1), 2)[0], " \t");
        $this->buffer = substr($this->buffer, $end + 1);

        if (! preg_match('/^[0-9A-Fa-f]{1,16}$/', $size)) {
            throw new HttpError(400, 'Malformed chunk size.');
        }

        $size = ltrim($size, '0');

        if ($size === '') {
            $this->state = self::TRAILERS;
            $this->trailerBytes = 0;

            return true;
        }

        if (strlen($size) > 15 || $this->bodyBytes + hexdec($size) > $this->limits->maxBody) {
            throw new HttpError(413, 'Payload too large.');
        }

        $this->remaining = (int) hexdec($size);
        $this->state = self::CHUNK_DATA;

        return true;
    }

    private function readChunkData(): bool|null
    {
        if (! $this->take()) {
            return null;
        }

        if ($this->remaining > 0) {
            return null;
        }

        $this->state = self::CHUNK_END;

        return true;
    }

    private function readChunkEnd(): bool|null
    {
        if (strlen($this->buffer) < 2) {
            return null;
        }

        if (! str_starts_with($this->buffer, "\r\n")) {
            throw new HttpError(400, 'Chunk data must end in CRLF.');
        }

        $this->buffer = substr($this->buffer, 2);
        $this->state = self::CHUNK_SIZE;

        return true;
    }

    private function readTrailers(): ParsedRequest|bool|null
    {
        $end = strpos($this->buffer, "\n");

        if ($end === false) {
            if ($this->trailerBytes + strlen($this->buffer) > $this->limits->maxHeaderBytes) {
                throw new HttpError(431, 'Request trailer fields too large.');
            }

            return null;
        }

        $this->trailerBytes += $end + 1;

        if ($this->trailerBytes > $this->limits->maxHeaderBytes) {
            throw new HttpError(431, 'Request trailer fields too large.');
        }

        if ($end === 0 || $this->buffer[$end - 1] !== "\r") {
            throw new HttpError(400, 'Lines must end in CRLF.');
        }

        // Trailer fields are read past, not merged: nothing in a Laravel request reads them.
        $line = substr($this->buffer, 0, $end - 1);
        $this->buffer = substr($this->buffer, $end + 1);

        return $line === '' ? $this->complete() : true;
    }

    /** Move up to $remaining buffered body bytes to the body; false when the buffer held none. */
    private function take(): bool
    {
        if ($this->buffer === '' && $this->remaining > 0) {
            return false;
        }

        $length = min($this->remaining, strlen($this->buffer));
        $bytes = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        $this->remaining -= $length;
        $this->bodyBytes += $length;

        if ($this->multipart !== null) {
            $this->multipart->write($bytes);
        } elseif ($this->spool !== null) {
            fwrite($this->spool, $bytes);
        } elseif (strlen($this->body) + $length > self::SPOOL_BYTES) {
            $this->spool = fopen('php://temp/maxmemory:0', 'w+b');
            fwrite($this->spool, $this->body.$bytes);
            $this->body = '';
        } else {
            $this->body .= $bytes;
        }

        return true;
    }

    private function complete(): ParsedRequest
    {
        $multipart = $this->multipart?->finish();
        $head = $this->head;

        $request = new ParsedRequest(
            $head['method'],
            $head['target'],
            $head['path'],
            $head['query'],
            $head['version'],
            $head['headers'],
            $head['keepAlive'],
            $this->body,
            $multipart,
            $this->spool,
        );

        if ($this->spool !== null) {
            rewind($this->spool);
        }

        $this->spool = null;
        $this->multipart = null;
        $this->head = null;
        $this->body = '';
        $this->state = self::HEAD;

        return $request;
    }

    /**
     * Comma-separated list values across repeated fields, trimmed and lower-cased.
     *
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function values(array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            foreach (explode(',', $field) as $value) {
                if (($value = strtolower(trim($value, " \t"))) !== '') {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }
}
