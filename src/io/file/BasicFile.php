<?php
declare(strict_types=1);

namespace dev\winterframework\io\file;

class BasicFile implements File {
    use FileTrait;

    public function __construct(
        string $filePath,
        string $name = ''
    ) {
        $this->name = $name;
        $this->filePath = $filePath;
    }
}