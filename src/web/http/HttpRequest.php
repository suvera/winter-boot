<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace dev\winterframework\web\http;

class HttpRequest {
    protected array $queryParams;
    protected array $postParams;
    protected HttpHeaders $headers;
    protected array $cookies;
    /**
     * @var HttpUploadedFile[]
     */
    protected array $files;
    protected string $method;
    protected string $uri;
    protected string $body;
    protected string $contentType;
    protected ?string $remoteAddr = null;
    protected ?int $remotePort = null;
    protected ?string $serverAddr = null;
    protected ?int $serverPort = null;
    protected ?string $serverProtocol = null;
    protected ?string $queryString = null;
    protected ?int $requestTime = null;
    protected ?float $requestTimeFloat = null;

    public function __construct() {
        $this->queryParams = $_GET ?? [];
        $this->postParams = $_POST ?? [];
        $this->headers = new HttpHeaders();
        $this->loadHeaders();
        $this->cookies = $_COOKIE ?? [];

        $this->method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
        $this->uri = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '';

        $this->remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : null;
        $this->remotePort = isset($_SERVER['REMOTE_PORT']) ? (int)$_SERVER['REMOTE_PORT'] : null;
        $this->serverAddr = isset($_SERVER['SERVER_ADDR']) ? (string)$_SERVER['SERVER_ADDR'] : null;
        $this->serverPort = isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : null;
        $this->serverProtocol = isset($_SERVER['SERVER_PROTOCOL']) ? (string)$_SERVER['SERVER_PROTOCOL'] : null;
        $this->queryString = isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : null;
        $this->requestTime = isset($_SERVER['REQUEST_TIME']) ? (int)$_SERVER['REQUEST_TIME'] : null;
        $this->requestTimeFloat = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float)$_SERVER['REQUEST_TIME_FLOAT'] : null;

        $body = file_get_contents('php://input');
        $this->body = is_string($body) ? $body : '';

        $this->contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        $files = $_FILES ?? [];
        $this->files = $this->loadUploadedFiles($files);
    }

    protected function loadUploadedFiles(array $files): array {
        $uploaded = [];
        foreach ($files as $name => $data) {
            $uploaded[$name] = [];
            if (!is_array($data['error'])) {
                $fileList = [$data];
            } else {
                $fileList = $data;
            }

            foreach ($fileList as $value) {
                $uploaded[$name][] = HttpUploadedFile::fromArray($value);
            }
        }
        return $uploaded;
    }

    protected function loadHeaders(): void {
        if (!function_exists('getallheaders')) {
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) == 'HTTP_') {
                    $ucWord = ucwords(strtolower(str_replace('_', ' ', substr($name, 5))));
                    if (!is_null($value)) {
                        $this->headers->add(str_replace(' ', '-', $ucWord), $value);
                    }
                }
            }
        } else {
            foreach (getallheaders() as $name => $value) {
                if (!is_null($value)) {
                    $this->headers->add($name, $value);
                }
            }
        }
    }

    public function getHeaders(): HttpHeaders {
        return $this->headers;
    }

    public function getHeader(string $headerName): ?array {
        return $this->headers->get($headerName);
    }

    public function getFirstHeader(string $headerName): ?string {
        return $this->headers->getFirst($headerName);
    }

    public function getCookie(string $name): ?string {
        return $this->cookies[$name] ?? null;
    }

    public function getCookies(): array {
        return $this->cookies;
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function getQueryParam(string $name): string|int|float|bool|null {
        return $this->queryParams[$name] ?? null;
    }

    public function hasQueryParam(string $name): bool {
        return array_key_exists($name, $this->queryParams);
    }

    public function getQueryParams(): array {
        return $this->queryParams;
    }

    public function getPostParam(string $name): string|int|float|bool|null {
        return $this->postParams[$name] ?? null;
    }

    public function getPostParams(): array {
        return $this->postParams;
    }

    public function getRawBody(): string {
        return $this->body;
    }

    public function getContentType(): string {
        return $this->contentType;
    }

    public function getUri(): string {
        return $this->uri;
    }

    public function getRemoteAddr(): ?string {
        return $this->remoteAddr;
    }

    public function getRemotePort(): ?int {
        return $this->remotePort;
    }

    public function getServerAddr(): ?string {
        return $this->serverAddr;
    }

    public function getServerPort(): ?int {
        return $this->serverPort;
    }

    public function getServerProtocol(): ?string {
        return $this->serverProtocol;
    }

    public function getQueryString(): ?string {
        return $this->queryString;
    }

    public function getRequestTime(): ?int {
        return $this->requestTime;
    }

    public function getRequestTimeFloat(): ?float {
        return $this->requestTimeFloat;
    }

    public function getFiles(): array {
        return $this->files;
    }

    public function getFile(string $name): null|array|HttpUploadedFile {
        return $this->files[$name] ?? null;
    }
}
