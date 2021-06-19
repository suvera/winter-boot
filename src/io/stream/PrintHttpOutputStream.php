<?php
declare(strict_types=1);

namespace dev\winterframework\io\stream;

use dev\winterframework\web\http\HttpCookie;

class PrintHttpOutputStream implements HttpOutputStream {

    protected bool $cliMode = false;

    public function __construct() {
        $this->cliMode = (php_sapi_name() === 'cli');
    }

    public function writeHeader(string $name, string $value) {
        if ($this->cliMode) {
            return;
        }
        header($name . ': ' . $value);
    }

    public function setStatus(int $status, string $phrase = null, string $version = null) {
        if ($this->cliMode) {
            return;
        }
        if (!$version) {
            $version = 'HTTP/1.1';
        }
        header($version . ' ' . $status . ' ' . $phrase);
    }

    /**
     * @param HttpCookie[] $cookies
     */
    public function setCookies(array $cookies) {
        if ($this->cliMode) {
            return;
        }

        foreach ($cookies as $cookie) {
            setcookie(
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

    public function destroy(): bool {
        return true;
    }

    public function getInputStream(): InputStream {
        return new StringInputStream('');
    }


}