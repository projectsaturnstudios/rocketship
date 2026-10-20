<?php

namespace ProjectSaturnStudios\Rocketship\Native\Http;

final readonly class MultipartBody
{
    /**
     * @param  array<array-key, mixed>  $fields  nested the way PHP fills $_POST
     * @param  array<array-key, mixed>  $files  nested by field name; each leaf is a file array with
     *                                          name, full_path, type, tmp_name, error and size, as Symfony's FileBag takes them
     * @param  list<string>  $temporaryFiles  temp files written for this request; the server removes any the app leaves behind
     */
    public function __construct(
        public array $fields,
        public array $files,
        public array $temporaryFiles,
    ) {
    }
}
