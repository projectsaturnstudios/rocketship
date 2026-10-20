<?php

namespace ProjectSaturnStudios\Rocketship\Native;

use ProjectSaturnStudios\Rocketship\Native\Http\ParserLimits;

final readonly class ServerOptions
{
    /**
     * @param  float  $keepAliveTimeout  seconds a connection may sit idle between requests (`native.keepalive_timeout`)
     * @param  int  $writeCap  bytes a connection may owe its socket before it stops serving pipelined requests
     * @param  float  $writeTimeout  seconds a streamed response may wait on a socket that takes nothing
     * @param  array<string, mixed>|null  $tls  ssl stream-context options (local_cert, local_pk, …); null serves plaintext
     * @param  int  $maxRequests  requests this worker serves before it is replaced by a fresh one; 0 never
     * @param  float  $drainTimeout  seconds a draining worker waits on in-flight connections before closing them
     * @param  float  $headerTimeout  seconds a request head may take to arrive in full; past it the client gets 408
     */
    public function __construct(
        public float $keepAliveTimeout = 5.0,
        public int $writeCap = 1_048_576,
        public float $writeTimeout = 60.0,
        public ?array $tls = null,
        public ParserLimits $limits = new ParserLimits,
        public int $maxRequests = 0,
        public float $drainTimeout = 30.0,
        public float $headerTimeout = 30.0,
    ) {
    }
}
