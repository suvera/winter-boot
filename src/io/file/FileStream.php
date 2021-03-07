<?php
declare(strict_types=1);

namespace dev\winterframework\io\file;

class FileStream {

    public function __construct(
        private File $file,
        private mixed $resource
    ) {
    }

    public function getStream(): mixed {
        return $this->resource;
    }

    public function close(): void {
        fclose($this->resource);
    }

    public function getFile(): File {
        return $this->file;
    }

}