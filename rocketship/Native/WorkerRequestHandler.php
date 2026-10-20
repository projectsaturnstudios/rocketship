<?php

namespace ProjectSaturnStudios\Rocketship\Native;

use Closure;
use ProjectSaturnStudios\Rocketship\Native\Http\ParsedRequest;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Stream;
use Laravel\Octane\Worker;

/** Hands each parsed request to the booted worker, as Octane's server entry scripts do. */
final class WorkerRequestHandler
{
    private float $startedAt = 0.0;

    /** The connection whose request is running, so a time-out can answer it. */
    private ?Connection $current = null;

    /**
     * @param  array<string, mixed>  $octaneConfig
     * @param  int  $maxExecutionTime  seconds; 0 lets a request run as long as it likes
     * @param  ?Closure(?Connection): void  $report  told the connection a request starts on, then null when it ends,
     *                                              so the master can time it (only while max_execution_time is on)
     */
    public function __construct(
        private readonly Worker $worker,
        private readonly NativeClient $client,
        private readonly string $publicPath,
        private readonly array $octaneConfig = [],
        private readonly int $maxExecutionTime = 0,
        private readonly ?Closure $report = null,
    ) {
        // The start command prints a line per request in local and testing, as it does for Swoole.
        $worker->onRequestHandled(function ($request, $response, $sandbox): void {
            if ($sandbox->environment('local', 'testing')) {
                Stream::request(
                    $request->getMethod(),
                    $request->fullUrl(),
                    $response->getStatusCode(),
                    (microtime(true) - $this->startedAt) * 1000,
                );
            }
        });
    }

    public function __invoke(ParsedRequest $parsed, Connection $connection): void
    {
        $this->startedAt = microtime(true);

        [$request, $context] = $this->client->marshalRequest(new RequestContext([
            'parsedRequest' => $parsed,
            'connection' => $connection,
            'publicPath' => $this->publicPath,
            'octaneConfig' => $this->octaneConfig,
        ]));

        // The master times the request on the wall clock, as Octane's Swoole server does: PHP's own limit counts CPU
        // time only, and on macOS one thread's limit fires in whichever thread runs.
        $timed = $this->report !== null && $this->maxExecutionTime > 0;
        $this->current = $connection;
        $timed && ($this->report)($connection);

        try {
            $this->worker->handle($request, $context);
        } finally {
            $this->current = null;
            $timed && ($this->report)(null);
        }
    }

    /** The master says the running request passed max_execution_time: answer it with 408. */
    public function timeOut(): void
    {
        $this->current?->timeOut();
    }
}
