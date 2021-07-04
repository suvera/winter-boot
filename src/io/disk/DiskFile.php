<?php
declare(strict_types=1);

namespace dev\winterframework\io\disk;

use dev\winterframework\util\log\Wlf4p;
use RuntimeException;

class DiskFile {
    use Wlf4p;

    const PREFIX = 'WINTER';
    const PREFIX_LEN = 6;

    /**
     * @var resource
     */
    protected mixed $resource;

    protected Journal $journal;
    protected int $pos = 0;

    public function __construct(
        protected string $filePath
    ) {
        // a+ : In this mode, fseek() only affects the reading position, writes are always appended.
        $this->resource = fopen($this->filePath, 'a+');
        if (!is_resource($this->resource)) {
            self::logError('Could not open Disk File for reading/writing ' . $this->filePath);
            throw new RuntimeException('Could not open Disk File for reading/writing ' . $this->filePath);
        }
        $this->journal = new Journal();
        $this->load();
    }

    protected function read(int $size): string|false {
        return fread($this->resource, $size);
    }

    protected function write(string $data): void {
        $written = fwrite($this->resource, $data);

        if ($written !== strlen($data)) {
            throw new RuntimeException('Could not write to Disk File ' . $this->filePath);
        }
        fflush($this->resource);
    }

    protected function load(): void {
        $prefix = $this->read(self::PREFIX_LEN);
        if (empty($prefix)) {
            $this->write(self::PREFIX);
        }
        $this->pos += self::PREFIX_LEN;
    }

    protected function addItem(string $data): int {
        $len = strlen($data);

        $item = new JournalItem(
            $this->pos,
            JournalItem::META_LEN + $len,
            false
        );

        $this->write($item->getMeta() . $data);

        $this->journal->addEntry($item);

        $this->pos += $item->getLength();

        return $item->getId();
    }

}