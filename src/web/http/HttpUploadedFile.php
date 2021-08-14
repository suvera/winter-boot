<?php
declare(strict_types=1);

namespace dev\winterframework\web\http;

use dev\winterframework\io\file\File;
use dev\winterframework\io\file\FileTrait;

class HttpUploadedFile implements File {
    use FileTrait;

    public function __construct(
        string $name,
        protected string $type,
        protected int $size,
        protected string $filePath,
        protected int $error
    ) {
        $this->name = $name;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getType(): string {
        return $this->type;
    }

    public function getSize(): int {
        return $this->size;
    }

    public function getFilePath(): string {
        return $this->filePath;
    }

    public function getError(): int {
        return $this->error;
    }

}