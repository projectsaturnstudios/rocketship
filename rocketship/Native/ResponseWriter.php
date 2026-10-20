<?php

namespace ProjectSaturnStudios\Rocketship\Native;

use ProjectSaturnStudios\Rocketship\Native\Http\ParsedRequest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Frames a Symfony response onto a connection: status line, headers and cookies, then the body as a
 * string, a file span the loop streams in 64 KB chunks, or a streamed callback's output.
 */
class ResponseWriter
{
    /** Reasons Symfony lacks, as Octane's Swoole client sends them. */
    public const REASONS = [
        419 => 'Page Expired',
        425 => 'Too Early',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
    ];

    /** Headers the server frames itself; an app's copies are dropped. */
    private const FRAMING = ['transfer-encoding', 'connection', 'keep-alive'];

    /**
     * @param  array<string, string>  $exactHeaders  sent as given, replacing the response's own (Symfony's header
     *                                               bag rewrites Cache-Control and appends charsets)
     */
    /**
     * True while a streamed response's output handler runs. A thread cancelled there (max_execution_time) leaves
     * PHP's output layer marked running, and its request shutdown then reports a fatal that is only that.
     */
    public static bool $inOutputHandler = false;

    public function write(Connection $connection, ParsedRequest $request, Response $response, string $outputBuffer = '', array $exactHeaders = []): void
    {
        $status = $response->getStatusCode();
        $bodyless = $request->method === 'HEAD' || $status < 200 || $status === 204 || $status === 304;
        $headers = $this->headers($connection, $response);

        foreach ($exactHeaders as $name => $value) {
            $headers[strtolower($name)] = [$name, [$value]];
        }

        if ($status < 200 || $status === 204 || $status === 304) {
            unset($headers['content-length']);
        }

        if ($response instanceof BinaryFileResponse) {
            $this->writeFile($connection, $request, $response, $headers, $bodyless);

            return;
        }

        if ($response instanceof StreamedResponse) {
            $this->writeStream($connection, $request, $response, $headers, $bodyless, $outputBuffer);

            return;
        }

        $body = $outputBuffer.$response->getContent();

        if (! $bodyless) {
            $headers['content-length'] = ['Content-Length', [(string) strlen($body)]];
        }

        $head = $this->head($connection, $request, $response, $headers);

        $connection->startResponse();
        $connection->send($head.($bodyless ? '' : $body));
    }

    /** @param array<string, array{0: string, 1: list<string>}> $headers */
    protected function writeFile(Connection $connection, ParsedRequest $request, BinaryFileResponse $response, array $headers, bool $bodyless): void
    {
        $path = $response->getFile()->getPathname();
        $offset = (fn (): int => $this->offset)->call($response);
        $maxlen = (fn (): int => $this->maxlen)->call($response);
        $delete = (fn (): bool => $this->deleteFileAfterSend)->call($response);

        $size = (int) @filesize($path);
        $length = $maxlen >= 0 ? min($maxlen, max(0, $size - $offset)) : max(0, $size - $offset);
        $handle = $bodyless || $length === 0 ? false : @fopen($path, 'rb');

        if (! $bodyless) {
            $headers['content-length'] = ['Content-Length', [(string) ($handle === false ? 0 : $length)]];
        }

        $head = $this->head($connection, $request, $response, $headers);

        $connection->startResponse();
        $connection->send($head);

        if ($handle === false) {
            if ($delete && is_file($path)) {
                @unlink($path);
            }

            return;
        }

        fseek($handle, $offset);
        $connection->sendFile(new FileSegment($handle, $length, $delete ? $path : null));
    }

    /** @param array<string, array{0: string, 1: list<string>}> $headers */
    protected function writeStream(Connection $connection, ParsedRequest $request, StreamedResponse $response, array $headers, bool $bodyless, string $outputBuffer): void
    {
        if ($bodyless) {
            $head = $this->head($connection, $request, $response, $headers);

            $connection->startResponse();
            $connection->send($head);

            return;
        }

        $chunked = ! isset($headers['content-length']) && $request->version === '1.1';

        if ($chunked) {
            $headers['transfer-encoding'] = ['Transfer-Encoding', ['chunked']];
        } elseif (! isset($headers['content-length'])) {
            // An HTTP/1.0 client reads a body of unknown length to the close.
            $connection->closeAfterResponse();
        }

        $emit = static fn (string $data): bool => $data === '' || $connection->sendNow($chunked ? dechex(strlen($data))."\r\n{$data}\r\n" : $data);

        $head = $this->head($connection, $request, $response, $headers);

        $connection->startResponse();

        if (! $connection->sendNow($head)) {
            return;
        }

        $emit($outputBuffer);

        ob_start(static function (string $data) use ($emit): string {
            // A cancel jumps out without running finally, which leaves the flag set exactly when it should be.
            self::$inOutputHandler = true;

            try {
                $emit($data);
            } finally {
                self::$inOutputHandler = false;
            }

            return '';
        }, 1);

        try {
            $response->sendContent();
        } finally {
            ob_end_clean();
        }

        if ($chunked) {
            $connection->sendNow("0\r\n\r\n");
        }
    }

    /** @return array<string, array{0: string, 1: list<string>}> lower-cased name => [name as sent, values] */
    protected function headers(Connection $connection, Response $response): array
    {
        $headers = [];

        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $headers[strtolower($name)] = [$name, array_map('strval', $values)];
        }

        foreach (self::FRAMING as $name) {
            if ($name === 'connection' && isset($headers[$name]) && in_array('close', array_map('strtolower', $headers[$name][1]), true)) {
                $connection->closeAfterResponse();
            }

            unset($headers[$name]);
        }

        $headers['date'] ??= ['Date', [gmdate('D, d M Y H:i:s').' GMT']];

        return $headers;
    }

    /** @param array<string, array{0: string, 1: list<string>}> $headers */
    protected function head(Connection $connection, ParsedRequest $request, Response $response, array $headers): string
    {
        $status = $response->getStatusCode();
        // Symfony takes any string as the reason phrase: a line break in it would start a header line.
        $reason = str_replace(["\r", "\n"], ' ', (fn (): string => $this->statusText)->call($response));

        if ($reason === '' || $reason === 'unknown status') {
            $reason = self::REASONS[$status] ?? 'Unknown Status';
        }

        $head = "HTTP/1.1 {$status} {$reason}\r\n";

        foreach ($headers as [$name, $values]) {
            foreach ($values as $value) {
                $head .= $this->line($name, $value);
            }
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $head .= $this->line('Set-Cookie', (string) $cookie);
        }

        if (! $connection->keepAlive()) {
            $head .= "Connection: close\r\n";
        } elseif ($request->version === '1.0') {
            $head .= "Connection: keep-alive\r\n";
        }

        return $head."\r\n";
    }

    /** One header line; a name or value carrying CR or LF is dropped, as PHP's header() refuses it. */
    protected function line(string $name, string $value): string
    {
        if (strpbrk($name.$value, "\r\n") !== false) {
            return '';
        }

        return "{$name}: {$value}\r\n";
    }
}
