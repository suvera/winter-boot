<?php
declare(strict_types=1);

namespace dev\winterframework\io\stream;

class PrintOutputStream implements OutputStream {

    public function close(): void {
        // nothing
    }

    public function write(string|int|float $data, int $length = null): int {
        $data = (string)$data;

        if ($length !== null && is_numeric($length)) {
            if ($length < 0) {
                $length = 0;
            }

            echo substr($data, 0, $length);
            return (strlen($data) > $length) ? $length : strlen($data);
        }
        echo $data;
        return strlen($data);
    }

    public function flush(): bool {
        flush();
        return true;
    }

}