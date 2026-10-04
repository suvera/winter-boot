<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

/**
 * A request completed but the server answered with an error status,
 * mirroring Spring's HttpStatusCodeException. The message carries only the
 * method, the query-stripped URL and the status line: response bodies and
 * query values are available via getters but are never interpolated, so
 * secrets in either place cannot leak into logs.
 */
class HttpStatusCodeException extends RestClientException {
    /**
     * @param array<string, string[]> $responseHeaders
     */
    public function __construct(
        private int $statusCode,
        private string $statusText,
        private array $responseHeaders = [],
        private string $responseBody = '',
        ?string $method = null,
        ?string $url = null
    ) {
        $target = '';
        if ($method !== null || $url !== null) {
            $target = trim(($method ?? '') . ' ' . RestTemplate::sanitizeUrl($url ?? ''));
            $target = $target !== '' ? ' for ' . $target : '';
        }
        parent::__construct(
            'HTTP request' . $target . ' failed with status '
            . $statusCode . ($statusText !== '' ? ' ' . $statusText : '')
        );
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }

    public function getStatusText(): string {
        return $this->statusText;
    }

    /** @return array<string, string[]> */
    public function getResponseHeaders(): array {
        return $this->responseHeaders;
    }

    public function getResponseBody(): string {
        return $this->responseBody;
    }
}
