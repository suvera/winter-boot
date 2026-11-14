<?php

declare(strict_types=1);

namespace dev\winterframework\web\websocket;

use RuntimeException;

/** Thrown by {@see CancellationToken::throwIfCancelled()}. */
class CancelledException extends RuntimeException {
}
