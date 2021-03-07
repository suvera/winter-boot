<?php
/** @noinspection PhpUnused */

declare(strict_types=1);

namespace dev\winterframework\web\http;


use dev\winterframework\web\MediaType;

class ResponseEntity {

    private HttpHeaders $headers;
    private mixed $body;
    private HttpStatus $status;
    /**
     * @var HttpCookie[]
     */
    private array $cookies = [];

    public function __construct() {
        $this->headers = new HttpHeaders();
        $this->status = HttpStatus::$OK;
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

    public function getStatus(): HttpStatus {
        return $this->status;
    }

    public function withStatus(HttpStatus $status): self {
        $this->status = $status;
        return $this;
    }

    public function withStatusCode(int $code): self {
        return self::withStatus(HttpStatus::getStatus($code));
    }

    public function withContentType(string $headerValue): self {
        $this->headers->setContentType($headerValue);
        return $this;
    }

    public function withContentLength(int $headerValue): self {
        $this->headers->setContentLength($headerValue);
        return $this;
    }

    public function withJson(mixed $body): self {
        $this->headers->setContentType(MediaType::APPLICATION_JSON);
        $this->setBody($body);
        return $this;
    }

    public function withXml(mixed $body): self {
        $this->headers->setContentType(MediaType::APPLICATION_XML);
        $this->setBody($body);
        return $this;
    }

    /**
     * @return HttpCookie[]
     */
    public function getCookies(): array {
        return $this->cookies;
    }

    /**
     * @param HttpCookie[] $cookies
     * @return self
     */
    public function setCookies(array $cookies): self {
        $this->cookies = $cookies;
        return $this;
    }

    public function withCookie(
        string $name,
        string $value = '',
        int $expires = 0,
        string $path = '',
        string $domain = '',
        bool $secure = false,
        bool $httponly = false
    ): self {

        $this->cookies[] = new HttpCookie($name, $value, $expires, $path, $domain, $secure, $httponly);

        return $this;
    }

    /**
     * #################################
     *  Static Methods
     *
     * @param mixed|null $body
     * @return static
     */
    public static function ok(mixed $body = null): self {
        $obj = new self();
        $obj->withStatus(HttpStatus::$OK);
        if ($body) {
            $obj->setBody($body);
        }
        return $obj;
    }

    public static function badRequest(): self {
        $obj = new self();
        $obj->withStatus(HttpStatus::$BAD_REQUEST);
        return $obj;
    }

    public static function accepted(): self {
        $obj = new self();
        $obj->withStatus(HttpStatus::$ACCEPTED);
        return $obj;
    }

    public static function created(string $location): self {
        $obj = new self();
        $obj->withStatus(HttpStatus::$CREATED);
        $obj->headers->setLocation($location);
        return $obj;
    }

    public static function noContent(): self {
        $obj = new self();
        $obj->withStatus(HttpStatus::$NO_CONTENT);
        return $obj;
    }

    public static function notFound(): self {
        $obj = new self();
        $obj->withStatus(HttpStatus::$NOT_FOUND);
        return $obj;
    }

    public static function unprocessableEntity(): self {
        $obj = new self();
        $obj->withStatus(HttpStatus::$UNPROCESSABLE_ENTITY);
        return $obj;
    }

    public static function unauthorized(): self {
        $obj = new self();
        $obj->withStatus(HttpStatus::$UNAUTHORIZED);
        return $obj;
    }

    public static function status(HttpStatus $status): self {
        $obj = new self();
        $obj->withStatus($status);
        return $obj;
    }

}