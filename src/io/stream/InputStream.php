<?php
declare(strict_types=1);

namespace dev\winterframework\io\stream;

interface InputStream {
    /**
     * Closes this input stream and releases any system resources associated with the stream.
     */
    public function close(): void;

    /**
     * Read given $length bytes, or read all
     * @param int|null $length
     * @return mixed
     */
    public function read(int $length = null): mixed;

    /**
     * Reached end of the stream
     *
     * @return bool
     */
    public function eof(): bool;

    /**
     * Re-positions this stream to the beginning
     */
    public function reset(): void;

    /**
     * Skips over and discards n bytes of data from this input stream.
     * - Return the actual number of bytes skipped.
     */
    public function skip(int $n): int;
}