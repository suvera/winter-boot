<?php
declare(strict_types=1);

namespace dev\winterframework\io\stream;

use Closure;
use dev\winterframework\web\http\HttpCookie;
use Swoole\Http\Response;

class SwooleOutputStream implements ChunkedOutputStream {

    /**
     * @param Closure(): bool|null $connectionProbe tells whether the client
     *     connection still exists (Server::exist() on the response's fd);
     *     without it only a failed write reveals a gone client.
     */
    public function __construct(
        protected Response $response,
        protected ?Closure $connectionProbe = null
    ) {
    }

    public function writeHeader(string $name, ?string $value): void {
        $this->response->header($name, $value);
    }

    public function setStatus(int $status, ?string $phrase = null, ?string $version = null): void {
        $this->response->status($status, $phrase);
    }

    /**
     * @param HttpCookie[] $cookies
     */
    public function setCookies(array $cookies): void {
        foreach ($cookies as $cookie) {
            $this->response->cookie(
                $cookie->name,
                $cookie->value,
                $cookie->expires,
                $cookie->path,
                $cookie->domain,
                $cookie->secure,
                $cookie->httponly,
                $cookie->samesite
            );
        }
    }

    public function close(): void {
        $this->response->end();
        //$this->response->close();
    }

    public function write(string|int|float $data, ?int $length = null): int {
        $data = (string)$data;

        if ($length !== null && is_numeric($length)) {
            if ($length < 0) {
                $length = 0;
            }

            $this->response->write(substr($data, 0, $length));
            return (strlen($data) > $length) ? $length : strlen($data);
        }
        $this->response->write($data);
        return strlen($data);
    }

    public function writeChunk(string $data): bool {
        if ($data === '') {
            return $this->isClientConnected();
        }
        // @: Swoole warns when the client is gone; the false return says so.
        return @$this->response->write($data) === true;
    }

    public function isClientConnected(): bool {
        if (!$this->response->isWritable()) {
            return false;
        }
        return $this->connectionProbe === null || ($this->connectionProbe)();
    }

    public function flush(): bool {
        return true;
    }

    public function destroy(): bool {
        return true;
    }

    public function getInputStream(): InputStream {
        return new StringInputStream('');
    }


}