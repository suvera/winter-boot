<?php
declare(strict_types=1);

namespace dev\winterframework\io\stream;

use dev\winterframework\util\Debug;
use dev\winterframework\web\http\HttpCookie;

class PrintHttpOutputStream implements HttpOutputStream {

    public function __construct() {
    }

    public function writeHeader(string $name, string $value) {
        header($name . ': ' . $value);
    }

    public function setStatus(int $status, string $phrase = null, string $version = null) {
        if (!$version) {
            $version = 'HTTP/1.1';
        }
        header($version . ' ' . $status . ' ' . $phrase);
    }

    /**
     * @param HttpCookie[] $cookies
     */
    public function setCookies(array $cookies) {
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

}