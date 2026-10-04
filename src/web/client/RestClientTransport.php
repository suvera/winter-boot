<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

/**
 * Low-level HTTP transport behind {@see RestTemplate}, mirroring Spring's
 * ClientHttpRequestFactory: it moves bytes and reports back a plain array,
 * while RestTemplate owns URI building, body conversion and error mapping.
 *
 * Custom implementations (tests, proxies, stubs) can be passed to the
 * RestTemplate constructor. The default implementation picks the engine at
 * runtime: coroutine client inside a Swoole coroutine, cURL when available,
 * plain PHP streams otherwise.
 */
interface RestClientTransport {
    /**
     * @param array<string, string> $headers flat request headers, already merged
     * @return array{status: int, headers: array<string, string[]>, body: string}
     * @throws RestClientException on transport failure
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeout,
        float $connectTimeout
    ): array;
}
