<?php

namespace ProjectSaturnStudios\Rocketship\Native\Http;

final readonly class ParsedRequest
{
    /**
     * @param  string  $target  the request-target exactly as sent (REQUEST_URI)
     * @param  string  $path  the target's path, still percent-encoded; '*' for OPTIONS *
     * @param  '1.0'|'1.1'  $version
     * @param  array<string, list<string>>  $headers  lower-cased names, values in arrival order
     * @param  string  $body  the decoded body; empty for multipart/form-data, as php://input is under PHP's SAPIs,
     *                        and when the body was large enough to spool to $bodyStream
     * @param  resource|null  $bodyStream  a large body, spooled to a temp file and rewound
     */
    public function __construct(
        public string $method,
        public string $target,
        public string $path,
        public string $query,
        public string $version,
        public array $headers,
        public bool $keepAlive,
        public string $body = '',
        public ?MultipartBody $multipart = null,
        public mixed $bodyStream = null,
    ) {
    }

    /** The whole body as a string, read back from the spool when it was spooled. */
    public function content(): string
    {
        if ($this->bodyStream === null) {
            return $this->body;
        }

        rewind($this->bodyStream);

        return (string) stream_get_contents($this->bodyStream);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}
