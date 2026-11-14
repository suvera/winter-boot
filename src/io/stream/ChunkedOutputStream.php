<?php

declare(strict_types=1);

namespace dev\winterframework\io\stream;

/**
 * An HTTP output stream that can send the body in chunks while the
 * response is still open, and tell whether the client is still there.
 * Used for streamed responses.
 */
interface ChunkedOutputStream extends HttpOutputStream {

    /** Sends one chunk now. False when the client is gone or the response ended. */
    public function writeChunk(string $data): bool;

    /** Whether the client connection is still open. */
    public function isClientConnected(): bool;
}
