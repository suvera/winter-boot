<?php
declare(strict_types=1);

namespace dev\winterframework\io\stream;

interface OutputStream {
    public function close(): void;

    public function write(string|int|float $data, int $length = null): int;

    public function flush(): bool;

    public function destroy(): bool;

    public function getInputStream(): InputStream;

}