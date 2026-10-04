<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

/**
 * HTTP 4xx response, mirroring Spring's HttpClientErrorException.
 */
class HttpClientErrorException extends HttpStatusCodeException {
}
