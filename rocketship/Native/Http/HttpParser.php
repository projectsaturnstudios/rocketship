<?php

namespace ProjectSaturnStudios\Rocketship\Native\Http;

/**
 * Turns the bytes of one connection into requests. A C parser can implement this as a second driver.
 */
interface HttpParser
{
    /**
     * Feed bytes as they arrive; returns what they completed, in order. An HttpError is always last:
     * the parser stops there and every later feed returns nothing.
     *
     * @return list<ParsedRequest|ExpectsContinue|HttpError>
     */
    public function feed(string $bytes): array;

    /** Drop a request in progress, removing any upload temp files it wrote. */
    public function abort(): void;

    /** Bytes of a request head have arrived but not the whole head: what the header deadline bounds. */
    public function headPending(): bool;
}
