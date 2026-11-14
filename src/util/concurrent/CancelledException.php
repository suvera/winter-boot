<?php

declare(strict_types=1);

namespace dev\winterframework\util\concurrent;

use RuntimeException;

/** Thrown by {@see CancellationToken::throwIfCancelled()}. */
class CancelledException extends RuntimeException {
}
