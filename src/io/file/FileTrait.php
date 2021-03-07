<?php
declare(strict_types=1);

namespace dev\winterframework\io\file;

trait FileTrait {

    protected string $filePath;
    protected string $name = '';

    public function canRead(): bool {
        return is_readable($this->filePath);
    }

    public function canWrite(): bool {
        return is_writable($this->filePath);
    }

    public function delete(): bool {
        return unlink($this->filePath);
    }

    public function exists(): bool {
        return file_exists($this->filePath);
    }

    public function isDirectory(): bool {
        return is_dir($this->filePath);
    }

    public function isFile(): bool {
        return is_file($this->filePath);
    }

    public function getRealPath(): string {
        $path = realpath($this->filePath);
        return is_bool($path) ? $this->filePath : $path;
    }

    public function getName(): string {
        return empty($this->name) ? basename($this->name) : $this->name;
    }

    public function lastModified(): int {
        return $this->exists() ? filemtime($this->filePath) : 0;
    }

    public function setPermissions(int $octalPerms): bool {
        return chmod($this->filePath, $octalPerms);
    }

    public function openStream(FileMode $mode): FileStream {
        /** @noinspection PhpParamsInspection */
        return new FileStream($this, fopen($this->filePath, $mode->getModeValue()));
    }
}