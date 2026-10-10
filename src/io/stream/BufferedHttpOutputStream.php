<?php
declare(strict_types=1);

namespace dev\winterframework\io\stream;

use dev\winterframework\web\http\HttpCookie;

/**
 * Collects a rendered response in memory instead of sending it, for
 * requests dispatched in-process. Status and headers stay on the
 * ResponseEntity; only the body bytes are kept here.
 */
class BufferedHttpOutputStream implements HttpOutputStream {

    private string $buffer = '';

    public function writeHeader(string $name, ?string $value): void {
    }

    public function setStatus(int $status, ?string $phrase = null, ?string $version = null): void {
    }

    /**
     * @param HttpCookie[] $cookies
     */
    public function setCookies(array $cookies): void {
    }

    public function close(): void {
    }

    public function write(string|int|float $data, ?int $length = null): int {
        $data = (string)$data;
        if ($length !== null) {
            $data = substr($data, 0, max(0, $length));
        }
        $this->buffer .= $data;
        return strlen($data);
    }

    public function flush(): bool {
        return true;
    }

    public function destroy(): bool {
        $this->buffer = '';
        return true;
    }

    public function getInputStream(): InputStream {
        return new StringInputStream($this->buffer);
    }

    public function getContents(): string {
        return $this->buffer;
    }
}
