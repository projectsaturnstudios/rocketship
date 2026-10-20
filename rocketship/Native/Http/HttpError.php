<?php

namespace ProjectSaturnStudios\Rocketship\Native\Http;

use RuntimeException;

/** A request the server refuses before the application sees it; the connection answers $status and closes. */
final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
