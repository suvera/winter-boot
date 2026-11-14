<?php

declare(strict_types=1);

namespace dev\winterframework\web\http;

use dev\winterframework\util\concurrent\CancellationToken;

/**
 * Writes the body of a streamed response chunk by chunk (see
 * {@see StreamingResponseEntity}). The status and headers go out with the
 * first chunk; every write() is sent to the client at once as one HTTP
 * chunk (chunked transfer encoding, never compressed).
 *
 * A writer belongs to one response. It may be used from other coroutines of
 * the same worker while the producer runs, but each write() is one chunk:
 * build a logical message (an SSE event) into one string and write it once.
 */
interface ResponseWriter {

    /**
     * Sends $data as one chunk; an empty string sends nothing. False when
     * the client is gone or the stream has ended: the producer should stop
     * its work then.
     */
    public function write(string $data): bool;

    /**
     * Whether the client is still connected. Under Swoole it turns false as
     * soon as the connection drops, so a producer waiting on slow work can
     * check it between steps; elsewhere it turns false after a failed write.
     */
    public function isConnected(): bool;

    /**
     * True once the status and headers were sent (with the first chunk);
     * after that an error can no longer change the status.
     */
    public function isCommitted(): bool;

    public function getBytesWritten(): int;

    /**
     * Cancelled as soon as the client disconnects (or the stream ended).
     * Pass it on to work that should stop with the client, e.g. the
     * upstream call of a relay: `$rest->exchangeStream(..., cancel: $out->getCancellationToken())`
     * stops within about 100 ms of the client leaving, even while the
     * upstream is silent.
     */
    public function getCancellationToken(): CancellationToken;
}
