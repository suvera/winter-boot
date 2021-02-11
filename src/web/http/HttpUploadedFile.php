<?php
declare(strict_types=1);

namespace dev\winterframework\web\http;

class HttpUploadedFile {

    public function __construct(
        public string $name,
        public string $type,
        public int $size,
        public string $tpmName,
        public int $error
    ) {
    }
}