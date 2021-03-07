<?php
declare(strict_types=1);

namespace dev\winterframework\io\file;

class InMemoryFile extends BasicFile {

    public function __destruct() {
        $this->delete();
    }

    public function getRealPath(): string {
        return $this->filePath;
    }

    public function getName(): string {
        return $this->name;
    }

}