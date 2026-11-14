<?php

declare(strict_types=1);

namespace dev\winterframework\web\sse;

use RuntimeException;

/** A server-sent event stream could not be parsed (e.g. an event over the size cap). */
class SseException extends RuntimeException {
}
