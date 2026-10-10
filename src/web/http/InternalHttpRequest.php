<?php
declare(strict_types=1);

namespace dev\winterframework\web\http;

/**
 * A request dispatched in-process (for example an MCP tool call routed
 * to a REST endpoint). It is built from explicit values, never from
 * superglobals, and the dispatcher never ends the process after
 * answering it.
 *
 * Caller details (remote address, protocol, request time) are copied
 * from the outer request, so interceptors see the real caller.
 */
class InternalHttpRequest extends HttpRequest {

    /** @noinspection PhpMissingParentConstructorInspection */
    public function __construct(
        HttpRequest $outer,
        string $method,
        string $uri,
        array $queryParams = [],
        array $postParams = [],
        string $body = '',
        string $contentType = '',
        ?HttpHeaders $headers = null,
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->queryParams = $queryParams;
        $this->postParams = $postParams;
        $this->body = $body;
        $this->contentType = $contentType;
        $this->headers = $headers ?? new HttpHeaders();
        if ($contentType !== '') {
            $this->headers->set(HttpHeaders::CONTENT_TYPE, $contentType);
        }
        $this->cookies = $outer->getCookies();
        $this->files = [];

        $this->remoteAddr = $outer->getRemoteAddr();
        $this->remotePort = $outer->getRemotePort();
        $this->serverAddr = $outer->getServerAddr();
        $this->serverPort = $outer->getServerPort();
        $this->serverProtocol = $outer->getServerProtocol();
        $this->queryString = $queryParams ? http_build_query($queryParams) : null;
        $this->requestTime = $outer->getRequestTime();
        $this->requestTimeFloat = $outer->getRequestTimeFloat();
    }

    public function exitsAfterResponse(): bool {
        return false;
    }
}
