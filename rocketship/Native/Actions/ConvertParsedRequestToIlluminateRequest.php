<?php

namespace ProjectSaturnStudios\Rocketship\Native\Actions;

use Illuminate\Http\Request;
use ProjectSaturnStudios\Rocketship\Native\Connection;
use ProjectSaturnStudios\Rocketship\Native\Http\ParsedRequest;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Build the Illuminate request straight from the parsed one, filling server variables, query, form,
 * cookies and files the way PHP's SAPIs fill $_SERVER, $_GET, $_POST, $_COOKIE and $_FILES.
 */
class ConvertParsedRequestToIlluminateRequest
{
    public function __invoke(ParsedRequest $parsed, Connection $connection): Request
    {
        parse_str($parsed->query, $query);

        $form = $parsed->multipart?->fields ?? [];

        if ($parsed->multipart === null && str_starts_with(strtolower($parsed->header('content-type') ?? ''), 'application/x-www-form-urlencoded')) {
            parse_str($parsed->content(), $form);
        }

        return Request::createFromBase(new SymfonyRequest(
            $query,
            $form,
            [],
            $this->cookies($parsed->headers['cookie'] ?? []),
            $parsed->multipart?->files ?? [],
            $this->server($parsed, $connection),
            $parsed->bodyStream ?? $parsed->body,
        ));
    }

    /** @return array<string, mixed> */
    protected function server(ParsedRequest $parsed, Connection $connection): array
    {
        [$serverAddress, $serverPort] = $this->split($connection->localAddress);
        [$remoteAddress, $remotePort] = $this->split($connection->remoteAddress);
        $now = microtime(true);

        $server = [
            'REQUEST_METHOD' => $parsed->method,
            'REQUEST_URI' => $parsed->path === '*' ? '*' : $parsed->path.($parsed->query !== '' ? '?'.$parsed->query : ''),
            'QUERY_STRING' => $parsed->query,
            'SERVER_PROTOCOL' => 'HTTP/'.$parsed->version,
            'SERVER_SOFTWARE' => 'Rocketship',
            'SERVER_NAME' => $this->hostName($parsed->header('host')) ?? $serverAddress,
            'SERVER_ADDR' => $serverAddress,
            'SERVER_PORT' => $serverPort,
            'REMOTE_ADDR' => $remoteAddress,
            'REMOTE_PORT' => $remotePort,
            'REQUEST_TIME' => (int) $now,
            'REQUEST_TIME_FLOAT' => $now,
        ];

        if ($connection->isSecure()) {
            $server['HTTPS'] = 'on';
        }

        foreach ($parsed->headers as $name => $values) {
            $key = strtoupper(str_replace('-', '_', $name));
            $value = implode($name === 'cookie' ? '; ' : ', ', $values);

            // As PHP's SAPIs do: the body's type and length are CONTENT_*, every other header HTTP_*.
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        return $server;
    }

    /**
     * $_COOKIE semantics: pairs split on ';', names and values url-decoded, bracketed names nested,
     * and the first of two cookies with one name wins.
     *
     * @param  list<string>  $headers
     * @return array<array-key, mixed>
     */
    protected function cookies(array $headers): array
    {
        $pairs = [];

        foreach ($headers as $header) {
            foreach (explode(';', $header) as $pair) {
                $pair = ltrim($pair, " \t");

                if ($pair === '') {
                    continue;
                }

                [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $name = urldecode($name);

                if ($name !== '' && ! array_key_exists($name, $pairs)) {
                    $pairs[$name] = urldecode($value);
                }
            }
        }

        if ($pairs === []) {
            return [];
        }

        parse_str(implode('&', array_map(
            fn (string $name, string $value): string => rawurlencode($name).'='.rawurlencode($value),
            array_keys($pairs),
            $pairs,
        )), $cookies);

        return $cookies;
    }

    /** @return array{0: string, 1: int} */
    protected function split(string $address): array
    {
        $colon = strrpos($address, ':');

        if ($colon === false) {
            return [$address, 0];
        }

        return [trim(substr($address, 0, $colon), '[]'), (int) substr($address, $colon + 1)];
    }

    protected function hostName(?string $host): ?string
    {
        if ($host === null || $host === '') {
            return null;
        }

        if (str_starts_with($host, '[')) {
            return substr($host, 0, (int) strpos($host, ']') + 1);
        }

        return explode(':', $host, 2)[0];
    }
}
