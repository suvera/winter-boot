<?php

declare(strict_types=1);

namespace dev\winterframework\web\http;

use Closure;

/**
 * Body of a {@see StreamingResponseEntity}: the producer that writes the
 * response in chunks. The dispatcher runs it after the controller method
 * returned, sends each chunk as it is written, and ends the response when
 * the producer returns.
 */
final class StreamingBody {
    private Closure $producer;

    /**
     * @param callable(ResponseWriter): void $producer
     */
    public function __construct(callable $producer) {
        $this->producer = $producer(...);
    }

    public function run(ResponseWriter $writer): void {
        ($this->producer)($writer);
    }
}
