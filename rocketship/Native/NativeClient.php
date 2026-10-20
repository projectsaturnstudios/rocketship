<?php

namespace ProjectSaturnStudios\Rocketship\Native;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Contracts\ServesStaticFiles;
use Laravel\Octane\MimeType;
use ProjectSaturnStudios\Rocketship\Native\Actions\ConvertParsedRequestToIlluminateRequest;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Octane;
use Laravel\Octane\OctaneResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The native server's side of the worker: requests come from the parser, responses go back through
 * the connection that carried them. The context carries `parsedRequest`, `connection`, `publicPath`
 * and `octaneConfig`.
 */
class NativeClient implements Client, ServesStaticFiles
{
    public function __construct(protected ResponseWriter $writer = new ResponseWriter)
    {
    }

    public function marshalRequest(RequestContext $context): array
    {
        return [
            (new ConvertParsedRequestToIlluminateRequest)($context->parsedRequest, $context->connection),
            $context,
        ];
    }

    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        $this->writer->write($context->connection, $context->parsedRequest, $response->response, (string) $response->outputBuffer);
    }

    public function error(Throwable $e, Application $app, Request $request, RequestContext $context): void
    {
        $connection = $context->connection;

        if ($connection->responseStarted()) {
            // Part of a response is already on the wire; a 500 now would land inside its body.
            $connection->closeAfterResponse();

            return;
        }

        $this->writer->write($connection, $context->parsedRequest, new Response(
            Octane::formatExceptionForClient($e, $app->make('config')->get('app.debug')),
            500,
            ['Content-Type' => 'text/plain'],
        ));
    }

    /**
     * Octane's rules for serving from public/: a real file inside the public path (or inside a symlinked
     * directory under it), never a php, htaccess or config file. Any method, as Octane's SwooleClient serves them.
     */
    public function canServeRequestAsStaticFile(Request $request, RequestContext $context): bool
    {
        $config = $context->octaneConfig ?? [];

        if (array_key_exists('serve_static_files', $config) && ! $config['serve_static_files']) {
            return false;
        }

        return $this->staticPath($request, $context) !== null;
    }

    public function serveStaticFile(Request $request, RequestContext $context): void
    {
        $path = (string) $this->staticPath($request, $context);
        $headers = ['Content-Type' => MimeType::get(pathinfo($path, PATHINFO_EXTENSION))];

        foreach ($context->octaneConfig['static_file_headers'] ?? [] as $pattern => $extra) {
            if ($request->is($pattern)) {
                $headers = array_merge($headers, $extra);
            }
        }

        $response = new BinaryFileResponse($path, 200, [], false, null, false, true);
        $response->setEtag(dechex((int) filemtime($path)).'-'.dechex((int) filesize($path)));
        // A static file carries no caching policy unless static_file_headers gives it one.
        $response->headers->remove('Cache-Control');

        if (! $response->isNotModified($request)) {
            $response->prepare($request);
        }

        $this->writer->write($context->connection, $context->parsedRequest, $response, exactHeaders: $headers);
    }

    /** The file a request names inside the public path, or null when it names nothing servable. */
    protected function staticPath(Request $request, RequestContext $context): ?string
    {
        $publicPath = $context->publicPath ?? null;

        if (! is_string($publicPath) || $publicPath === '' || $request->path() === '/') {
            return null;
        }

        $requested = $publicPath.'/'.$request->path();
        $resolved = realpath($requested);
        $root = realpath($publicPath);

        if ($resolved === false || $root === false || in_array(pathinfo($requested, PATHINFO_EXTENSION), ['php', 'htaccess', 'config'], true)) {
            return null;
        }

        // A symlinked directory under public may point outside it: then the path as requested is what
        // must stay inside public, and the link's target is what must be a file.
        $afterLink = $this->pathAfterSymlink($publicPath, $request->path());
        $inside = $afterLink !== null && str_ends_with($resolved, $afterLink)
            ? ! in_array('..', explode('/', $request->path()), true)
            : str_starts_with($resolved, $root.'/');

        if (! $inside || ! is_file($resolved)) {
            return null;
        }

        return $resolved;
    }

    protected function pathAfterSymlink(string $publicPath, string $path): ?string
    {
        $directories = explode('/', $path);

        while (($directory = array_shift($directories)) !== null && $directory !== '') {
            $publicPath .= '/'.$directory;

            if (is_link($publicPath)) {
                return implode('/', $directories);
            }
        }

        return null;
    }
}
