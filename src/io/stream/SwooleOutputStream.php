<?php
declare(strict_types=1);

namespace dev\winterframework\io\stream;

use dev\winterframework\web\http\HttpCookie;
use Swoole\Http\Response;

class SwooleOutputStream implements HttpOutputStream {

    public function __construct(
        protected Response $response
    ) {
    }

    public function writeHeader(string $name, ?string $value) {
        $this->response->header($name, $value);
    }

    public function setStatus(int $status, string $phrase = null, string $version = null) {
        $this->response->status($status, $phrase);
    }

    /**
     * @param HttpCookie[] $cookies
     */
    public function setCookies(array $cookies) {
        foreach ($cookies as $cookie) {
            $this->response->cookie(
                $cookie->name,
                $cookie->value,
                $cookie->expires,
                $cookie->path,
                $cookie->domain,
                $cookie->secure,
                $cookie->httponly
            );
        }
    }

    public function close(): void {
        $this->response->end();
        //$this->response->close();
    }

    public function write(string|int|float $data, int $length = null): int {
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