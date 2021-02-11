<?php /** @noinspection PhpPureAttributeCanBeAddedInspection */
declare(strict_types=1);

namespace dev\winterframework\web\http;

class HttpRequest {
    private array $queryParams;
    private array $postParams;
    private HttpHeaders $headers;
    private array $cookies;
    /**
     * @var HttpUploadedFile[]
     */
    private array $files;
    private string $method;
    private string $uri;
    private string $body;
    private $contentType;

    public function __construct() {
        $this->queryParams = isset($_GET) ? $_GET : [];
        $this->postParams = isset($_POST) ? $_POST : [];
        $this->headers = new HttpHeaders();
        $this->cookies = isset($_COOKIE) ? $_COOKIE : [];

        $this->method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : '';
        $this->uri = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '';

        $body = file_get_contents('php://input');
        $this->body = is_string($body) ? $body : '';
        
        $this->contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';

        $files = isset($_FILES) ? $_FILES : [];
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
                    isset($value['name']) ? $value['name'] : '',
                    isset($value['type']) ? $value['type'] : '',
                    isset($value['size']) ? $value['size'] : 0,
                    isset($value['tpm_name']) ? $value['tpm_name'] : '',
                    isset($value['error']) ? $value['error'] : UPLOAD_ERR_NO_FILE
                );
            }


        }
        $this->files = $uploaded;
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
        return isset($this->cookies[$name]) ? $this->cookies[$name] : null;
    }

    public function getCookies(): array {
        return $this->cookies;
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function getQueryParam(string $name): string|int|float|bool|null {
        return isset($this->queryParams[$name]) ? $this->queryParams[$name] : null;
    }

    public function hasQueryParam(string $name): bool {
        return array_key_exists($name, $this->queryParams);
    }

    public function getQueryParams(): array {
        return $this->queryParams;
    }

    public function getPostParam(string $name): string|int|float|bool|null {
        return isset($this->postParams[$name]) ? $this->postParams[$name] : null;
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
        return isset($this->files[$name]) ? $this->files[$name] : null;
    }

}