<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

/**
 * A streamed response could not be decoded (malformed SSE or JSON line,
 * an item over the size cap). The message never contains body content.
 */
class StreamDecodeException extends RestClientException {
}
