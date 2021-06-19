<?php
declare(strict_types=1);

namespace dev\winterframework\io\stream;

class StringOutputStream implements OutputStream {
    protected string $buffer = '';

    public function close(): void {
        $this->buffer = '';
    }

    public function write(string|int|float $data, int $length = null): int {
        $data = (string)$data;

        if ($length !== null && is_numeric($length)) {
            if ($length < 0) {
                $length = 0;
            }

            $this->buffer .= substr($data, 0, $length);
            return (strlen($data) > $length) ? $length : strlen($data);
        }
        $this->buffer .= $data;
        return strlen($data);
    }

    public function flush(): bool {
        return true;
    }

    public function destroy(): bool {
        return true;
    }

    public function getInputStream(): InputStream {
        return new StringInputStream($this->buffer);
    }
}