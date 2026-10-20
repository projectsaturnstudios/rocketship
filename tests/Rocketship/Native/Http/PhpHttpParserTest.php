<?php

declare(strict_types=1);

use ProjectSaturnStudios\Rocketship\Native\Http\ExpectsContinue;
use ProjectSaturnStudios\Rocketship\Native\Http\HttpError;
use ProjectSaturnStudios\Rocketship\Native\Http\ParsedRequest;
use ProjectSaturnStudios\Rocketship\Native\Http\ParserLimits;
use ProjectSaturnStudios\Rocketship\Native\Http\PhpHttpParser;

function parser(int $maxBody = 1_048_576): PhpHttpParser
{
    return new PhpHttpParser(new ParserLimits(maxBody: $maxBody, uploadDirectory: sys_get_temp_dir()));
}

/** @return list<ParsedRequest|HttpError|ExpectsContinue> */
function parse(string $bytes, ?PhpHttpParser $parser = null): array
{
    return ($parser ?? parser())->feed($bytes);
}

function errorStatus(array $items): ?int
{
    $last = end($items);

    return $last instanceof HttpError ? $last->status : null;
}

it('parses a GET request line, headers and query', function () {
    [$request] = parse("GET /users/7?sort=name&page=2 HTTP/1.1\r\nHost: example.test\r\nX-Trace: a\r\nX-Trace: b\r\n\r\n");

    expect($request)->toBeInstanceOf(ParsedRequest::class)
        ->and($request->method)->toBe('GET')
        ->and($request->target)->toBe('/users/7?sort=name&page=2')
        ->and($request->path)->toBe('/users/7')
        ->and($request->query)->toBe('sort=name&page=2')
        ->and($request->version)->toBe('1.1')
        ->and($request->headers)->toBe(['host' => ['example.test'], 'x-trace' => ['a', 'b']])
        ->and($request->body)->toBe('')
        ->and($request->keepAlive)->toBeTrue();
});

it('waits for the rest of a request fed one byte at a time', function () {
    $parser = parser();
    $raw = "POST /echo HTTP/1.1\r\nHost: h\r\nContent-Length: 5\r\n\r\nhello";
    $seen = [];

    foreach (str_split($raw) as $i => $byte) {
        $items = $parser->feed($byte);

        if ($i < strlen($raw) - 1) {
            expect($items)->toBe([]);
        } else {
            $seen = $items;
        }
    }

    expect($seen[0]->body)->toBe('hello');
});

it('decodes a chunked body with extensions and trailers', function () {
    [$request] = parse("POST /c HTTP/1.1\r\nHost: h\r\nTransfer-Encoding: chunked\r\n\r\n5;ext=1\r\nhello\r\n6\r\n world\r\n0\r\nX-Checksum: 1\r\n\r\n");

    expect($request->body)->toBe('hello world');
});

it('returns pipelined requests in order from one feed', function () {
    $items = parse("GET /a HTTP/1.1\r\nHost: h\r\n\r\nPOST /b HTTP/1.1\r\nHost: h\r\nContent-Length: 2\r\n\r\nokGET /c HTTP/1.1\r\nHost: h\r\n\r\n");

    expect(array_map(fn (ParsedRequest $r) => $r->path, $items))->toBe(['/a', '/b', '/c'])
        ->and($items[1]->body)->toBe('ok');
});

it('serves the good requests ahead of a bad one in the same feed', function () {
    $items = parse("GET /a HTTP/1.1\r\nHost: h\r\n\r\nGET /b HTTP/9.9\r\nHost: h\r\n\r\n");

    expect($items[0]->path)->toBe('/a')
        ->and(errorStatus($items))->toBe(505);
});

it('keeps HTTP/1.1 alive unless told to close and closes HTTP/1.0 unless asked to keep alive', function (string $head, bool $keepAlive) {
    [$request] = parse($head."\r\n\r\n");

    expect($request->keepAlive)->toBe($keepAlive);
})->with([
    '1.1 default' => ["GET / HTTP/1.1\r\nHost: h", true],
    '1.1 close' => ["GET / HTTP/1.1\r\nHost: h\r\nConnection: Close", false],
    '1.1 close among tokens' => ["GET / HTTP/1.1\r\nHost: h\r\nConnection: upgrade, close", false],
    '1.0 default' => ['GET / HTTP/1.0', false],
    '1.0 keep-alive' => ["GET / HTTP/1.0\r\nConnection: Keep-Alive", true],
]);

it('ignores empty lines ahead of a request line', function () {
    [$request] = parse("\r\n\r\nGET / HTTP/1.1\r\nHost: h\r\n\r\n");

    expect($request->path)->toBe('/');
});

it('refuses a request line past 8 KB with 414, complete or not', function () {
    $long = 'GET /'.str_repeat('a', 8200);

    expect(errorStatus(parse($long)))->toBe(414)
        ->and(errorStatus(parse($long." HTTP/1.1\r\nHost: h\r\n\r\n")))->toBe(414);
});

it('refuses headers past 64 KB with 431, complete or not', function () {
    $head = "GET / HTTP/1.1\r\nHost: h\r\n".str_repeat('X-Pad: '.str_repeat('p', 1000)."\r\n", 70);

    expect(errorStatus(parse($head)))->toBe(431)
        ->and(errorStatus(parse($head."\r\n")))->toBe(431);
});

it('refuses a declared body past max_body with 413 before reading it', function () {
    expect(errorStatus(parse("POST / HTTP/1.1\r\nHost: h\r\nContent-Length: 11\r\n\r\n", parser(maxBody: 10))))->toBe(413)
        ->and(errorStatus(parse("POST / HTTP/1.1\r\nHost: h\r\nContent-Length: 99999999999999999999\r\n\r\n")))->toBe(413);
});

it('refuses a chunked body that grows past max_body with 413', function () {
    expect(errorStatus(parse("POST / HTTP/1.1\r\nHost: h\r\nTransfer-Encoding: chunked\r\n\r\n6\r\nabcdef\r\n6\r\n", parser(maxBody: 10))))->toBe(413);
});

it('refuses request smuggling shapes', function (string $head, int $status) {
    expect(errorStatus(parse($head."\r\n\r\n")))->toBe($status);
})->with([
    'content-length and transfer-encoding' => ["POST / HTTP/1.1\r\nHost: h\r\nContent-Length: 3\r\nTransfer-Encoding: chunked", 400],
    'signed content-length' => ["POST / HTTP/1.1\r\nHost: h\r\nContent-Length: +3", 400],
    'conflicting content-lengths' => ["POST / HTTP/1.1\r\nHost: h\r\nContent-Length: 3\r\nContent-Length: 4", 400],
    'list of conflicting lengths' => ["POST / HTTP/1.1\r\nHost: h\r\nContent-Length: 3, 4", 400],
    'chunked not last' => ["POST / HTTP/1.1\r\nHost: h\r\nTransfer-Encoding: chunked, gzip", 400],
    'unknown coding' => ["POST / HTTP/1.1\r\nHost: h\r\nTransfer-Encoding: gzip, chunked", 501],
    'transfer-encoding on 1.0' => ["POST / HTTP/1.0\r\nTransfer-Encoding: chunked", 400],
    'space before colon' => ["GET / HTTP/1.1\r\nHost : h", 400],
    'obs-fold' => ["GET / HTTP/1.1\r\nHost: h\r\nX-A: 1\r\n  continued", 400],
    'control byte in value' => ["GET / HTTP/1.1\r\nHost: h\r\nX-A: a\x01b", 400],
    'missing host on 1.1' => ['GET / HTTP/1.1', 400],
    'two hosts' => ["GET / HTTP/1.1\r\nHost: a\r\nHost: b", 400],
    'bare LF line ending' => ["GET / HTTP/1.1\nHost: h", 400],
]);

it('accepts repeated identical content-lengths', function () {
    [$request] = parse("POST / HTTP/1.1\r\nHost: h\r\nContent-Length: 2\r\nContent-Length: 2\r\n\r\nok");

    expect($request->body)->toBe('ok');
});

it('refuses malformed request lines', function (string $line, int $status) {
    expect(errorStatus(parse($line."\r\nHost: h\r\n\r\n")))->toBe($status);
})->with([
    'unsupported version' => ['GET / HTTP/2.0', 505],
    'garbage version' => ['GET / HTTX/1.1', 400],
    'bad method token' => ['G(T / HTTP/1.1', 400],
    'extra space' => ['GET  / HTTP/1.1', 400],
    'relative target' => ['GET users HTTP/1.1', 400],
    'asterisk off OPTIONS' => ['GET * HTTP/1.1', 400],
    'raw non-ascii target' => ["GET /caf\xc3\xa9 HTTP/1.1", 400],
]);

it('accepts the asterisk form for OPTIONS and the absolute form with its authority as host', function () {
    [$options] = parse("OPTIONS * HTTP/1.1\r\nHost: h\r\n\r\n");
    [$absolute] = parse("GET http://other.test:8080/p?q=1 HTTP/1.1\r\nHost: h\r\n\r\n");

    expect($options->path)->toBe('*')
        ->and($absolute->path)->toBe('/p')
        ->and($absolute->query)->toBe('q=1')
        ->and($absolute->headers['host'])->toBe(['other.test:8080']);
});

it('refuses malformed chunk framing', function (string $body) {
    expect(errorStatus(parse("POST / HTTP/1.1\r\nHost: h\r\nTransfer-Encoding: chunked\r\n\r\n".$body)))->toBe(400);
})->with([
    'non-hex size' => ["zz\r\n"],
    'missing CRLF after data' => ["3\r\nabcXY"],
    'oversized size field' => [str_repeat('f', 17)."\r\n"],
]);

it('asks for 100-continue once, only while the body is still owed', function () {
    $parser = parser();
    $items = $parser->feed("PUT /f HTTP/1.1\r\nHost: h\r\nExpect: 100-continue\r\nContent-Length: 4\r\n\r\n");

    expect($items)->toHaveCount(1)
        ->and($items[0])->toBeInstanceOf(ExpectsContinue::class)
        ->and($parser->feed('da'))->toBe([])
        ->and($parser->feed('ta')[0]->body)->toBe('data');

    $already = parse("PUT /f HTTP/1.1\r\nHost: h\r\nExpect: 100-continue\r\nContent-Length: 4\r\n\r\ndata");

    expect($already)->toHaveCount(1)->and($already[0])->toBeInstanceOf(ParsedRequest::class);
});

it('refuses an expectation it cannot meet with 417', function () {
    expect(errorStatus(parse("PUT /f HTTP/1.1\r\nHost: h\r\nExpect: 200-ok\r\nContent-Length: 1\r\n\r\nx")))->toBe(417);
});

it('stops parsing after an error', function () {
    $parser = parser();
    $parser->feed("GET / HTTX/1.1\r\n\r\n");

    expect($parser->feed("GET / HTTP/1.1\r\nHost: h\r\n\r\n"))->toBe([]);
});

it('parses a head fed one byte at a time in linear time', function () {
    $head = "GET / HTTP/1.1\r\nHost: x\r\n";

    while (strlen($head) < 60_000) {
        $head .= 'X-Pad-'.strlen($head).': '.str_repeat('a', 40)."\r\n";
    }

    $parser = parser();
    $items = [];
    $started = hrtime(true);

    foreach (str_split($head."\r\n") as $byte) {
        array_push($items, ...$parser->feed($byte));
    }

    // Re-reading every header line on every byte took about 27 s for this head; one pass takes milliseconds.
    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
        ->and($items)->toHaveCount(1)
        ->and($items[0])->toBeInstanceOf(ParsedRequest::class)
        ->and($items[0]->header('x-pad-25'))->toBe(str_repeat('a', 40));
});

it('still refuses a bad header line as soon as it arrives when fed piecemeal', function () {
    $parser = parser();

    expect(parse("GET / HTTP/1.1\r\nHost: x\r\n", $parser))->toBe([])
        ->and(errorStatus(parse("Bad Name: y\r\n", $parser)))->toBe(400);
});

it('reports whether a request head is part way in', function () {
    $parser = parser();

    $idle = $parser->headPending();
    $parser->feed("GET / HTTP/1.1\r\nHo");
    $partial = $parser->headPending();
    $parser->feed("st: x\r\nContent-Length: 4\r\n\r\nab");
    $inBody = $parser->headPending();
    $parser->feed('cd');

    expect($idle)->toBeFalse()
        ->and($partial)->toBeTrue()
        ->and($inBody)->toBeFalse() // a slow body is not a slow head
        ->and($parser->headPending())->toBeFalse();
});

it('spools a large body to a temp file instead of holding it in memory', function () {
    $parser = parser(maxBody: 8_000_000);
    $body = str_repeat('0123456789abcdef', 250_000); // 4 MB
    $parser->feed("POST /upload HTTP/1.1\r\nHost: x\r\nContent-Length: ".strlen($body)."\r\n\r\n");

    $before = memory_get_usage();
    $items = [];

    foreach (str_split($body, 65536) as $chunk) {
        array_push($items, ...$parser->feed($chunk));
    }

    $grew = memory_get_usage() - $before;
    [$request] = $items;

    expect($grew)->toBeLessThan(1_000_000)
        ->and($request->body)->toBe('')
        ->and(stream_get_contents($request->bodyStream))->toBe($body)
        ->and($request->content())->toBe($body);
});

it('keeps a small body as a string', function () {
    [$request] = parse("POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 5\r\n\r\nhello");

    expect($request->body)->toBe('hello')
        ->and($request->bodyStream)->toBeNull()
        ->and($request->content())->toBe('hello');
});
