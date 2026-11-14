<?php

declare(strict_types=1);

namespace dev\winterframework\web\client\stream;

use dev\winterframework\web\client\RestClientException;

/**
 * Minimal byte pipe behind the streaming HTTP client: one implementation per
 * runtime (Swoole coroutine socket inside a coroutine, PHP stream socket
 * elsewhere). Internal; not part of the public API.
 */
interface StreamSocket {
    /** @throws RestClientException when the TCP connection cannot be made in time */
    public function connect(string $host, int $port, float $timeout): void;

    /**
     * Upgrades the open connection to TLS. With $verify, the peer chain must
     * validate (system CAs, or $caFile) and the certificate must match
     * $peerName; anything else fails closed.
     *
     * @throws RestClientException on handshake or verification failure
     */
    public function enableTls(string $peerName, bool $verify, ?string $caFile, float $timeout): void;

    /** @throws RestClientException when the bytes cannot be written */
    public function send(string $data): void;

    /**
     * Next bytes from the peer, waiting at most $timeout seconds.
     *
     * @return string|null bytes, '' at EOF, null when nothing arrived in time
     * @throws RestClientException on a socket error
     */
    public function recv(float $timeout): ?string;

    public function close(): void;
}
