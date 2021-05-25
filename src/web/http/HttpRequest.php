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

    public function __construct() {
        $this->queryParams = $_GET ?? [];
        $this->postParams = $_POST ?? [];
        $this->headers = new HttpHeaders();
        $this->loadHeaders();
        $this->cookies = $_COOKIE ?? [];

        $this->method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
        $this->uri = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '';

        $body = file_get_contents('php://input');
        $this->body = is_string($body) ? $body : '';

        $this->contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        $files = $_FILES ?? [];
        $this->files = $this->loadUploadedFiles($files);
    }

    protected function loadUploadedFiles($files): array {
        $uploaded = [];
        foreach ($files as $name => $data) {
            $uploaded[$name] = [];
            if (!is_array($data['error'])) {
                $fileList = [$data];
            } else {
                $fileList = $data;
            }

            foreach ($fileList as $value) {
                $uploaded[$name][] = new HttpUploadedFile(
                    $value['name'] ?? '',
                    $value['type'] ?? '',
                    $value['size'] ?? 0,
                    $value['tpm_name'] ?? '',
                    $value['error'] ?? UPLOAD_ERR_NO_FILE
                );
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

    public function getFiles(): array {
        return $this->files;
    }

    public function getFile(string $name): ?array {
        return $this->files[$name] ?? null;
    }

}