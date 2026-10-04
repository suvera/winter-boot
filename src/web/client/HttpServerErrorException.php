<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

/**
 * HTTP 5xx response, mirroring Spring's HttpServerErrorException.
 */
class HttpServerErrorException extends HttpStatusCodeException {
}
