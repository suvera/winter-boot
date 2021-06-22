<?php
declare(strict_types=1);

namespace dev\winterframework\io\stream;

use dev\winterframework\io\file\BasicFile;
use dev\winterframework\io\file\FileMode;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use RuntimeException;

class FileInputStream implements InputStream {
    use Wlf4p;

    /**
     * @var resource
     */
    protected mixed $fileResource;

    /**
     * FileOutputStream constructor.
     * @param string|resource|BasicFile $file
     */
    public function __construct(mixed $file) {
        if ($file instanceof BasicFile) {
            if (!$file->canRead()) {
                throw new RuntimeException('Could not open file for reading');
            }
            $this->fileResource = $file->openStream(FileMode::$READ_ONLY)->getStream();
        } else if (is_resource($file)) {
            $this->fileResource = $file;
        } else {
            $this->fileResource = fopen($file, 'rb');
            if (!is_resource($this->fileResource)) {
                self::logError('Could not open file for reading ' . $file . ' ' . $this->fileResource);
                throw new RuntimeException('Could not open file for reading ' . $file);
            }
        }
    }

    public function close(): void {
        fclose($this->fileResource);
    }

    public function read($length = null): string|bool {
        if ($length !== null) {
            TypeAssert::positiveInteger($length);
            return fread($this->fileResource, $length);
        } else {
            $contents = stream_get_contents($this->fileResource);
            rewind($this->fileResource);
            return $contents;
        }
    }

    public function eof(): bool {
        return feof($this->fileResource);
    }

    public function reset(): void {
        rewind($this->fileResource);
    }

    public function skip(int $n): int {
        TypeAssert::positiveInteger($n);

        fseek($this->fileResource, $n, SEEK_CUR);
        if ($this->eof()) {
            return $n - 1;
        }
        return $n;
    }

}