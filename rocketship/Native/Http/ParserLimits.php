<?php

namespace ProjectSaturnStudios\Rocketship\Native\Http;

final readonly class ParserLimits
{
    public string $uploadDirectory;

    public int $maxFileSize;

    public int $maxFiles;

    /**
     * @param  ?string  $uploadDirectory  defaults to upload_tmp_dir, then the system temp directory
     * @param  ?int  $maxFileSize  defaults to upload_max_filesize
     * @param  ?int  $maxFiles  defaults to max_file_uploads
     */
    public function __construct(
        public int $maxRequestLine = 8192,
        public int $maxHeaderBytes = 65536,
        public int $maxBody = 10_485_760,
        ?string $uploadDirectory = null,
        ?int $maxFileSize = null,
        ?int $maxFiles = null,
    ) {
        $this->uploadDirectory = $uploadDirectory ?? (ini_get('upload_tmp_dir') ?: sys_get_temp_dir());
        $this->maxFileSize = $maxFileSize ?? ini_parse_quantity(ini_get('upload_max_filesize') ?: '2M');
        $this->maxFiles = $maxFiles ?? (int) (ini_get('max_file_uploads') ?: 20);
    }
}
