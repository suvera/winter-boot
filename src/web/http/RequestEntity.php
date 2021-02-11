<?php
/** @noinspection PhpUnused */
/** @noinspection PhpPureAttributeCanBeAddedInspection */
declare(strict_types=1);

namespace dev\winterframework\web\http;

use dev\winterframework\enums\RequestMethod;

class RequestEntity {

    private HttpHeaders $headers;
    private mixed $body;
    private string $requestMethod = RequestMethod::GET;

    public function __construct() {
        $this->headers = new HttpHeaders();
    }

    public function getHeaders(): HttpHeaders {
        return $this->headers;
    }

    public function setHeaders(HttpHeaders $headers): self {
        $this->headers = $headers;
        return $this;
    }

    public function setBody(mixed $body): self {
        $this->body = $body;
        return $this;
    }

    public function getBody(): mixed {
        return $this->body;
    }

    public function withHeader(string $headerName, string $headerValue): self {
        $this->headers->set($headerName, $headerValue);
        return $this;
    }

    public function withContentType(string $headerValue): self {
        $this->headers->setContentType($headerValue);
        return $this;
    }

    public function withContentLength(int $headerValue): self {
        $this->headers->setContentLength($headerValue);
        return $this;
    }

    public function getRequestMethod(): string {
        return $this->requestMethod;
    }

    public function setRequestMethod(string $requestMethod): RequestEntity {
        $this->requestMethod = $requestMethod;
        return $this;
    }

}