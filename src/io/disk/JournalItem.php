<?php
declare(strict_types=1);

namespace dev\winterframework\io\disk;

class JournalItem {
    const META_LEN = 48;

    public function __construct(
        protected int $start,
        protected int $length,
        protected bool $deleted = false,
        protected int $id = 0
    ) {
    }

    public function getMeta(): string {
        $meta = $this->deleted ? '1' : '0';

        $meta .= str_pad(dechex($this->start), 16, '0', STR_PAD_LEFT);
        $meta .= str_pad(dechex($this->length), 16, '0', STR_PAD_LEFT);
        $meta .= str_pad($meta, self::META_LEN, '0', STR_PAD_RIGHT);

        return $meta;
    }

    public static function fromMeta(string $meta): self {
        $deleted = ($meta[0] === '1');
        $start = hexdec(substr($meta, 1, 16));
        $length = hexdec(substr($meta, 17, 16));

        return new self($start, $length, $deleted);
    }

    public function getStart(): int {
        return $this->start;
    }

    public function setStart(int $start): void {
        $this->start = $start;
    }

    public function getLength(): int {
        return $this->length;
    }

    public function setLength(int $length): void {
        $this->length = $length;
    }

    public function getId(): int {
        return $this->id;
    }

    public function setId(int $id): void {
        $this->id = $id;
    }

    public function isDeleted(): bool {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): void {
        $this->deleted = $deleted;
    }

}