<?php

namespace ProjectSaturnStudios\Rocketship\Native\Http;

/**
 * Streaming multipart/form-data parser (RFC 7578). File parts go straight to temp files as their bytes
 * arrive; fields stay in memory. Matches PHP's rfc1867 handling: parts without a name are skipped,
 * an empty filename is UPLOAD_ERR_NO_FILE, a file past upload_max_filesize is UPLOAD_ERR_INI_SIZE,
 * files past max_file_uploads are dropped, and bracketed names nest as they do in $_POST and $_FILES.
 */
final class MultipartParser
{
    private const PREAMBLE = 0;

    private const DELIMITER_TAIL = 1;

    private const PART_HEADERS = 2;

    private const PART_BODY = 3;

    private const EPILOGUE = 4;

    private const MAX_PART_HEADERS = 16384;

    /** Bound on transport padding between a delimiter and its CRLF. */
    private const MAX_PADDING = 1024;

    private readonly string $delimiter;

    private readonly string $separator;

    private string $buffer = '';

    private int $state = self::PREAMBLE;

    /** Only the first bytes of the body may hold a delimiter without a CRLF ahead of it. */
    private bool $atBodyStart = true;

    private bool $skip = true;

    private string $name = '';

    private bool $isFile = false;

    private string $filename = '';

    private string $type = '';

    private string $value = '';

    /** @var resource|null */
    private $handle = null;

    private string $path = '';

    private int $size = 0;

    private int $error = UPLOAD_ERR_OK;

    /** @var list<array{0: string, 1: string}> */
    private array $fields = [];

    /** @var list<array{0: string, 1: array{name: string, full_path: string, type: string, tmp_name: string, error: int, size: int}}> */
    private array $files = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    public function __construct(
        string $boundary,
        private readonly string $directory,
        private readonly int $maxFileSize,
        private readonly int $maxFiles,
    ) {
        $this->delimiter = '--'.$boundary;
        $this->separator = "\r\n--".$boundary;
    }

    public function write(string $bytes): void
    {
        $this->buffer .= $bytes;

        try {
            while ($this->step()) {
                //
            }
        } catch (HttpError $e) {
            $this->abort();

            throw $e;
        }
    }

    public function finish(): MultipartBody
    {
        if ($this->state !== self::EPILOGUE) {
            $this->abort();

            throw new HttpError(400, 'Multipart body ended before its closing boundary.');
        }

        $infos = array_column($this->files, 1);
        $files = $this->nest(array_map(fn (array $file, int $index): array => [$file[0], (string) $index], $this->files, array_keys($this->files)));

        array_walk_recursive($files, function (mixed &$leaf) use ($infos): void {
            $leaf = $infos[(int) $leaf];
        });

        return new MultipartBody($this->nest($this->fields), $files, $this->temporaryFiles);
    }

    /** Close and remove every temp file this body wrote. */
    public function abort(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;

        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        $this->temporaryFiles = [];
        $this->buffer = '';
    }

    private function step(): bool
    {
        return match ($this->state) {
            self::PREAMBLE => $this->readPreamble(),
            self::DELIMITER_TAIL => $this->readDelimiterTail(),
            self::PART_HEADERS => $this->readPartHeaders(),
            self::PART_BODY => $this->readPartBody(),
            default => $this->buffer = '',
        } === true;
    }

    private function readPreamble(): bool
    {
        if ($this->atBodyStart) {
            if (str_starts_with($this->buffer, $this->delimiter)) {
                $this->buffer = substr($this->buffer, strlen($this->delimiter));
                $this->state = self::DELIMITER_TAIL;

                return true;
            }

            if (strlen($this->buffer) < strlen($this->delimiter) && str_starts_with($this->delimiter, $this->buffer)) {
                return false;
            }

            $this->atBodyStart = false;
        }

        $at = strpos($this->buffer, $this->separator);

        if ($at !== false) {
            $this->buffer = substr($this->buffer, $at + strlen($this->separator));
            $this->state = self::DELIMITER_TAIL;

            return true;
        }

        $this->keepTail();

        return false;
    }

    private function readDelimiterTail(): bool
    {
        if (strlen($this->buffer) < 2) {
            return false;
        }

        if (str_starts_with($this->buffer, '--')) {
            $this->buffer = '';
            $this->state = self::EPILOGUE;

            return true;
        }

        $padded = ltrim($this->buffer, " \t");

        if (str_starts_with($padded, "\r\n")) {
            $this->buffer = substr($padded, 2);
            $this->state = self::PART_HEADERS;

            return true;
        }

        if ($padded === '' || $padded === "\r") {
            if (strlen($this->buffer) > self::MAX_PADDING) {
                throw new HttpError(400, 'Malformed multipart delimiter.');
            }

            return false;
        }

        throw new HttpError(400, 'Malformed multipart delimiter.');
    }

    private function readPartHeaders(): bool
    {
        if (str_starts_with($this->buffer, "\r\n")) {
            $block = '';
            $this->buffer = substr($this->buffer, 2);
        } else {
            $end = strpos($this->buffer, "\r\n\r\n");

            if ($end === false) {
                if (strlen($this->buffer) > self::MAX_PART_HEADERS) {
                    throw new HttpError(400, 'Multipart part headers too large.');
                }

                return false;
            }

            if ($end > self::MAX_PART_HEADERS) {
                throw new HttpError(400, 'Multipart part headers too large.');
            }

            $block = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + 4);
        }

        $this->beginPart($block);
        $this->state = self::PART_BODY;

        return true;
    }

    private function readPartBody(): bool
    {
        $at = strpos($this->buffer, $this->separator);

        if ($at === false) {
            $this->keepTail(emit: true);

            return false;
        }

        $this->emit(substr($this->buffer, 0, $at));
        $this->buffer = substr($this->buffer, $at + strlen($this->separator));
        $this->endPart();
        $this->state = self::DELIMITER_TAIL;

        return true;
    }

    /** Hold back enough bytes to recognise a separator split across writes; emit the rest if asked. */
    private function keepTail(bool $emit = false): void
    {
        $keep = strlen($this->separator) - 1;

        if (strlen($this->buffer) <= $keep) {
            return;
        }

        if ($emit) {
            $this->emit(substr($this->buffer, 0, -$keep));
        }

        $this->buffer = substr($this->buffer, -$keep);
    }

    private function beginPart(string $block): void
    {
        $headers = [];

        foreach (explode("\r\n", $block) as $line) {
            if (($colon = strpos($line, ':')) !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }

        $this->skip = true;
        $this->isFile = false;
        $this->value = $this->filename = $this->type = $this->path = '';
        $this->size = 0;
        $this->error = UPLOAD_ERR_OK;
        $this->handle = null;

        $disposition = $headers['content-disposition'] ?? '';

        if (! preg_match('/^form-data\s*(;|$)/i', $disposition)) {
            return;
        }

        $params = $this->dispositionParameters($disposition);

        if (($params['name'] ?? '') === '') {
            return;
        }

        $this->name = $params['name'];
        $this->skip = false;

        if (! array_key_exists('filename', $params)) {
            return;
        }

        $this->isFile = true;

        if (count($this->files) >= $this->maxFiles) {
            $this->skip = true;

            return;
        }

        $this->filename = $params['filename'];
        $this->type = $headers['content-type'] ?? '';

        if ($this->filename === '') {
            $this->error = UPLOAD_ERR_NO_FILE;

            return;
        }

        $path = @tempnam($this->directory, 'php');
        $handle = $path === false ? false : @fopen($path, 'wb');

        if ($handle === false) {
            if ($path !== false) {
                @unlink($path);
            }

            $this->error = UPLOAD_ERR_NO_TMP_DIR;

            return;
        }

        $this->path = $path;
        $this->handle = $handle;
        $this->temporaryFiles[] = $path;
    }

    private function emit(string $bytes): void
    {
        if ($this->skip || $bytes === '') {
            return;
        }

        if (! $this->isFile) {
            $this->value .= $bytes;

            return;
        }

        if ($this->handle === null) {
            return;
        }

        $this->size += strlen($bytes);

        if ($this->size > $this->maxFileSize) {
            $this->dropFile(UPLOAD_ERR_INI_SIZE);

            return;
        }

        if (@fwrite($this->handle, $bytes) !== strlen($bytes)) {
            $this->dropFile(UPLOAD_ERR_CANT_WRITE);
        }
    }

    private function dropFile(int $error): void
    {
        fclose($this->handle);
        @unlink($this->path);

        $this->temporaryFiles = array_values(array_diff($this->temporaryFiles, [$this->path]));
        $this->handle = null;
        $this->error = $error;
    }

    private function endPart(): void
    {
        if ($this->skip) {
            return;
        }

        if (! $this->isFile) {
            $this->fields[] = [$this->name, $this->value];

            return;
        }

        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }

        $ok = $this->error === UPLOAD_ERR_OK;

        $this->files[] = [$this->name, [
            'name' => preg_replace('#^.*[/\\\\]#', '', $this->filename),
            'full_path' => $this->filename,
            'type' => $ok ? $this->type : '',
            'tmp_name' => $ok ? $this->path : '',
            'error' => $this->error,
            'size' => $ok ? $this->size : 0,
        ]];
    }

    /** @return array<string, string> */
    private function dispositionParameters(string $disposition): array
    {
        preg_match_all('/;\s*([A-Za-z0-9*_.\-]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;]*))/', $disposition, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $parameters = [];

        foreach ($matches as $match) {
            // Only an escaped quote is unescaped: browsers send Windows paths with bare backslashes.
            $parameters[strtolower($match[1])] = $match[2] !== null
                ? str_replace('\\"', '"', $match[2])
                : trim($match[3]);
        }

        return $parameters;
    }

    /**
     * Nest [name, value] pairs by their brackets exactly as PHP does for $_POST: parse_str decodes each
     * name before splitting it on brackets, so the encoded names keep binary values and brackets intact.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return array<array-key, mixed>
     */
    private function nest(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        parse_str(implode('&', array_map(fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]), $pairs)), $nested);

        return $nested;
    }
}
