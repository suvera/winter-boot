<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

/**
 * Optional capability of a {@see RestClientTransport}: delivering the
 * response body piece by piece as it arrives, for
 * {@see RestTemplate::exchangeStream()}. Kept separate from
 * RestClientTransport so existing custom transports keep compiling;
 * RestTemplate falls back to a single whole-body chunk for transports
 * without it.
 */
interface StreamingRestClientTransport {
    /**
     * Sends the request and streams the response.
     *
     * $onResponse is called exactly once, with the final (non-1xx) status
     * and the response headers, before any body bytes. $onChunk is then
     * called with every body piece as it is read (de-chunked and decoded);
     * returning false stops reading and closes the connection without an
     * error.
     *
     * When the request carries a cancellation token, the transport stops
     * promptly (within about 100 ms) once it is cancelled, also while no
     * data is arriving: it closes the connection and returns without an
     * error.
     *
     * @param callable(int, array<string, string[]>): void $onResponse
     * @param callable(string): bool $onChunk
     * @throws RestClientTimeoutException when the connection or a read times out
     * @throws RestClientException on any other transport failure
     */
    public function stream(StreamRequest $request, callable $onResponse, callable $onChunk): void;
}
