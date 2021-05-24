<?php
declare(strict_types=1);

namespace dev\winterframework\io\stream;

use dev\winterframework\web\http\HttpCookie;

interface HttpOutputStream extends OutputStream {

    public function writeHeader(string $name, string $value);

    public function setStatus(int $status, string $phrase = null, string $version = null);

    /**
     * @param HttpCookie[] $cookies
     */
    public function setCookies(array $cookies);
}