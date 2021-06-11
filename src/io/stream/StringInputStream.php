<?php
declare(strict_types=1);

namespace dev\winterframework\io\stream;

use dev\winterframework\type\TypeAssert;

class StringInputStream implements InputStream {

    protected int $pos = 0;

    public function __construct(protected string $value) {
    }

    public function close(): void {
        unset($this->value);
    }

    public function read(int $length = null): string {
        if ($length !== null) {
            TypeAssert::positiveInteger($length);
            $data = substr($this->value, $this->pos, $length);
            $this->pos += $length;
            return $data;
        } else {
            return $this->value;
        }
    }

    public function eof(): bool {
        return ($this->pos >= strlen($this->value));
    }

    public function reset(): void {
        $this->pos = 0;
    }

    public function skip(int $n): int {
        TypeAssert::positiveInteger($n);
        $this->pos += $n;
        if ($this->eof()) {
            return $n - 1;
        }

        return $n;
    }

}