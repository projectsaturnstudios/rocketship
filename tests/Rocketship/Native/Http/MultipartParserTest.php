<?php

declare(strict_types=1);

use ProjectSaturnStudios\Rocketship\Native\Http\HttpError;
use ProjectSaturnStudios\Rocketship\Native\Http\MultipartBody;
use ProjectSaturnStudios\Rocketship\Native\Http\MultipartParser;
use ProjectSaturnStudios\Rocketship\Native\Http\ParsedRequest;
use ProjectSaturnStudios\Rocketship\Native\Http\ParserLimits;
use ProjectSaturnStudios\Rocketship\Native\Http\PhpHttpParser;

const BOUNDARY = '----RocketshipBoundary7MA4YWxk';

/** @param list<array{0: string, 1: string, 2?: string, 3?: string}> $parts [name, content, filename?, type?] */
function multipart(array $parts, string $boundary = BOUNDARY): string
{
    $body = '';

    foreach ($parts as $part) {
        [$name, $content] = $part;
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"";

        if (array_key_exists(2, $part)) {
            $body .= "; filename=\"{$part[2]}\"\r\nContent-Type: ".($part[3] ?? 'application/octet-stream');
        }

        $body .= "\r\n\r\n{$content}\r\n";
    }

    return $body."--{$boundary}--\r\n";
}

function uploadDirectory(): string
{
    return $GLOBALS['rocketshipUploadDirectory'];
}

/** @return list<string> files left in the upload directory */
function leftovers(): array
{
    return glob(uploadDirectory().'/*') ?: [];
}

function multipartParser(int $maxFileSize = 1_048_576, int $maxFiles = 20): MultipartParser
{
    return new MultipartParser(BOUNDARY, uploadDirectory(), $maxFileSize, $maxFiles);
}

/** @param list<string> $chunks */
function feedMultipart(MultipartParser $parser, array $chunks): MultipartBody
{
    foreach ($chunks as $chunk) {
        $parser->write($chunk);
    }

    return $parser->finish();
}

beforeEach(function () {
    mkdir($GLOBALS['rocketshipUploadDirectory'] = sys_get_temp_dir().'/rocketship-multipart-'.bin2hex(random_bytes(6)));
});

afterEach(function () {
    array_map('unlink', leftovers());
    rmdir(uploadDirectory());
});

it('streams file parts to temp files and keeps fields in memory', function () {
    $png = random_bytes(5000)."\r\n--not-the-boundary\r\n";
    $body = feedMultipart(multipartParser(), [multipart([
        ['title', 'Saturn V'],
        ['photo', $png, 'C:\\Users\\angel\\rocket.png', 'image/png'],
    ])]);

    $file = $body->files['photo'];

    expect($body->fields)->toBe(['title' => 'Saturn V'])
        ->and($file['name'])->toBe('rocket.png')
        ->and($file['full_path'])->toBe('C:\\Users\\angel\\rocket.png')
        ->and($file['type'])->toBe('image/png')
        ->and($file['error'])->toBe(UPLOAD_ERR_OK)
        ->and($file['size'])->toBe(strlen($png))
        ->and(file_get_contents($file['tmp_name']))->toBe($png)
        ->and($body->temporaryFiles)->toBe([$file['tmp_name']])
        ->and(dirname($file['tmp_name']))->toBe(realpath(uploadDirectory()));
});

it('produces the same parts however the body is split', function () {
    $raw = multipart([['a', 'first'], ['doc', 'line1\r\nline2', 'notes.txt', 'text/plain'], ['b', 'last']]);
    $body = feedMultipart(multipartParser(), str_split($raw));

    expect($body->fields)->toBe(['a' => 'first', 'b' => 'last'])
        ->and(file_get_contents($body->files['doc']['tmp_name']))->toBe('line1\r\nline2');
});

it('nests bracketed names the way PHP fills $_POST and $_FILES', function () {
    $body = feedMultipart(multipartParser(), [multipart([
        ['user[name]', 'Angel'],
        ['tags[]', 'php'],
        ['tags[]', 'c'],
        ['docs[]', 'one', 'one.txt', 'text/plain'],
        ['docs[]', 'two', 'two.txt', 'text/plain'],
        ['user[avatar]', 'img', 'me.jpg', 'image/jpeg'],
    ])]);

    expect($body->fields)->toBe(['user' => ['name' => 'Angel'], 'tags' => ['php', 'c']])
        ->and(array_column($body->files['docs'], 'name'))->toBe(['one.txt', 'two.txt'])
        ->and($body->files['user']['avatar']['name'])->toBe('me.jpg');
});

it('reports an empty file input as UPLOAD_ERR_NO_FILE without a temp file', function () {
    $body = feedMultipart(multipartParser(), [multipart([['photo', '', '']])]);

    expect($body->files['photo'])->toMatchArray(['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0])
        ->and($body->temporaryFiles)->toBe([])
        ->and(leftovers())->toBe([]);
});

it('marks a file past the size limit UPLOAD_ERR_INI_SIZE and removes its temp file', function () {
    $body = feedMultipart(multipartParser(maxFileSize: 10), [multipart([['big', str_repeat('x', 50), 'big.bin']])]);

    expect($body->files['big'])->toMatchArray(['tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0])
        ->and($body->temporaryFiles)->toBe([])
        ->and(leftovers())->toBe([]);
});

it('skips files past max_file_uploads as PHP does', function () {
    $body = feedMultipart(multipartParser(maxFiles: 1), [multipart([['a', '1', 'a.txt'], ['b', '2', 'b.txt']])]);

    expect(array_keys($body->files))->toBe(['a']);
});

it('refuses a body that never closes its boundary', function () {
    $parser = multipartParser();
    $parser->write("--".BOUNDARY."\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\nvalue");

    expect(fn () => $parser->finish())->toThrow(HttpError::class);
});

it('removes its temp files when aborted', function () {
    $parser = multipartParser();
    $parser->write("--".BOUNDARY."\r\nContent-Disposition: form-data; name=\"f\"; filename=\"f.bin\"\r\n\r\npartial");

    expect(leftovers())->toHaveCount(1);

    $parser->abort();

    expect(leftovers())->toBe([]);
});

it('streams a chunked multipart request through the HTTP parser with an empty raw body', function () {
    $raw = multipart([['title', 'hi'], ['f', 'filedata', 'f.txt', 'text/plain']]);
    $chunked = dechex(strlen($raw))."\r\n{$raw}\r\n0\r\n\r\n";
    $parser = new PhpHttpParser(new ParserLimits(uploadDirectory: uploadDirectory()));

    [$request] = $parser->feed("POST /up HTTP/1.1\r\nHost: h\r\nContent-Type: multipart/form-data; boundary=\"".BOUNDARY."\"\r\nTransfer-Encoding: chunked\r\n\r\n{$chunked}");

    expect($request)->toBeInstanceOf(ParsedRequest::class)
        ->and($request->body)->toBe('')
        ->and($request->multipart->fields)->toBe(['title' => 'hi'])
        ->and(file_get_contents($request->multipart->files['f']['tmp_name']))->toBe('filedata');
});

it('refuses multipart without a usable boundary', function () {
    $items = (new PhpHttpParser(new ParserLimits(uploadDirectory: uploadDirectory())))->feed("POST / HTTP/1.1\r\nHost: h\r\nContent-Type: multipart/form-data\r\nContent-Length: 1\r\n\r\nx");

    expect(end($items))->toBeInstanceOf(HttpError::class)->and(end($items)->status)->toBe(400);
});
