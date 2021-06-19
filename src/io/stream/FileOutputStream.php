<?php
declare(strict_types=1);

namespace dev\winterframework\io\stream;

use dev\winterframework\exception\FileNotFoundException;

class FileOutputStream implements OutputStream {
    /**
     * @var resource
     */
    protected mixed $fileResource;

    protected ?string $filePath = null;

    /**
     * FileOutputStream constructor.
     * @param string|resource $file
     * @param bool $append
     */
    public function __construct(mixed $file, bool $append = false) {
        if (is_resource($file)) {
            $this->fileResource = $file;
        } else {
            $this->fileResource = fopen($file, $append ? 'a' : 'w');
            if (!is_resource($this->fileResource)) {
                throw new FileNotFoundException('Count not open file for writing');
            }
            $this->filePath = $file;
        }
    }

    public function close(): void {
        fclose($this->fileResource);
    }

    public function write(string|int|float $data, int $length = null): int {
        if ($length !== null && is_numeric($length)) {
            return fwrite($this->fileResource, $data, $length);
        }
        return fwrite($this->fileResource, $data);
    }

    public function flush(): bool {
        return fflush($this->fileResource);
    }

    public function destroy(): bool {
        if (!$this->filePath) {
            unlink($this->filePath);
            $this->filePath = null;
        }
        return true;
    }

    public function getInputStream(): InputStream {
        if ($this->filePath) {
            return new FileInputStream($this->filePath);
        }
        return new FileInputStream($this->fileResource);
    }

}