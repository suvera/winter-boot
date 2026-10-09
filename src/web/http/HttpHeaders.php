<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\web\http;

use dev\winterframework\type\TypeAssert;

final class HttpHeaders {
    const ACCEPT = "Accept";
    const ACCEPT_CHARSET = "Accept-Charset";
    const ACCEPT_ENCODING = "Accept-Encoding";
    const ACCEPT_LANGUAGE = "Accept-Language";
    const ACCEPT_RANGES = "Accept-Ranges";
    const ACCESS_CONTROL_ALLOW_CREDENTIALS = "Access-Control-Allow-Credentials";
    const ACCESS_CONTROL_ALLOW_HEADERS = "Access-Control-Allow-Headers";
    const ACCESS_CONTROL_ALLOW_METHODS = "Access-Control-Allow-Methods";
    const ACCESS_CONTROL_ALLOW_ORIGIN = "Access-Control-Allow-Origin";
    const ACCESS_CONTROL_EXPOSE_HEADERS = "Access-Control-Expose-Headers";
    const ACCESS_CONTROL_MAX_AGE = "Access-Control-Max-Age";
    const ACCESS_CONTROL_REQUEST_HEADERS = "Access-Control-Request-Headers";
    const ACCESS_CONTROL_REQUEST_METHOD = "Access-Control-Request-Method";
    const AGE = "Age";
    const ALLOW = "Allow";
    const AUTHORIZATION = "Authorization";
    const CACHE_CONTROL = "Cache-Control";
    const CONNECTION = "Connection";
    const CONTENT_ENCODING = "Content-Encoding";
    const CONTENT_DISPOSITION = "Content-Disposition";
    const CONTENT_LANGUAGE = "Content-Language";
    const CONTENT_LENGTH = "Content-Length";
    const CONTENT_LOCATION = "Content-Location";
    const CONTENT_RANGE = "Content-Range";
    const CONTENT_TYPE = "Content-Type";
    const COOKIE = "Cookie";
    const DATE = "Date";
    const ETAG = "ETag";
    const EXPECT = "Expect";
    const EXPIRES = "Expires";
    const FROM = "From";
    const HOST = "Host";
    const IF_MATCH = "If-Match";
    const IF_MODIFIED_SINCE = "If-Modified-Since";
    const IF_NONE_MATCH = "If-None-Match";
    const IF_RANGE = "If-Range";
    const IF_UNMODIFIED_SINCE = "If-Unmodified-Since";
    const LAST_MODIFIED = "Last-Modified";
    const LINK = "Link";
    const LOCATION = "Location";
    const MAX_FORWARDS = "Max-Forwards";
    const ORIGIN = "Origin";
    const PRAGMA = "Pragma";
    const PROXY_AUTHENTICATE = "Proxy-Authenticate";
    const PROXY_AUTHORIZATION = "Proxy-Authorization";
    const RANGE = "Range";
    const REFERER = "Referer";
    const RETRY_AFTER = "Retry-After";
    const SERVER = "Server";
    const SET_COOKIE = "Set-Cookie";
    const SET_COOKIE2 = "Set-Cookie2";
    const TE = "TE";
    const TRAILER = "Trailer";
    const TRANSFER_ENCODING = "Transfer-Encoding";
    const UPGRADE = "Upgrade";
    const USER_AGENT = "User-Agent";
    const VARY = "Vary";
    const VIA = "Via";
    const WARNING = "Warning";
    const WWW_AUTHENTICATE = "WWW-Authenticate";

    /**
     * Values keyed by the name as first given. Lookups are case-insensitive
     * (RFC 9110 field names): $names maps the lower-cased name to that key,
     * so 'user-agent', 'User-Agent' and 'USER-AGENT' are one header.
     */
    private array $headers = [];

    /** @var string[] lower-cased name => key in $headers */
    private array $names = [];

    /**
     * Rejects CR/LF in header names and values to block HTTP response
     * splitting. Header values never legitimately contain raw newlines.
     */
    private static function assertNoCrlf(string $headerName, string $headerValue): void {
        if (str_contains($headerName, "\r") || str_contains($headerName, "\n")
            || str_contains($headerValue, "\r") || str_contains($headerValue, "\n")
        ) {
            throw new \InvalidArgumentException(
                'Invalid header "' . $headerName . '": CR/LF characters are not allowed'
            );
        }
    }

    private function keyOf(string $headerName): ?string {
        return $this->names[strtolower($headerName)] ?? null;
    }

    public function merge(HttpHeaders $other): void {
        if ($this === $other) {
            return;
        }
        foreach ($other->headers as $name => $values) {
            $this->remove($name);
            $this->headers[$name] = $values;
            $this->names[strtolower($name)] = $name;
        }
    }

    public function add(string $headerName, string $headerValue): void {
        TypeAssert::notEmpty('headerName', $headerName);
        self::assertNoCrlf($headerName, $headerValue);

        $key = $this->keyOf($headerName);
        if ($key !== null) {
            $this->headers[$key][] = $headerValue;
        } else {
            $this->headers[$headerName] = [$headerValue];
            $this->names[strtolower($headerName)] = $headerName;
        }
    }

    public function getAll(): array {
        return $this->headers;
    }

    public function set(string $headerName, string $headerValue): void {
        TypeAssert::notEmpty('headerName', $headerName);
        self::assertNoCrlf($headerName, $headerValue);
        $this->remove($headerName);
        $this->headers[$headerName] = [$headerValue];
        $this->names[strtolower($headerName)] = $headerName;
    }

    public function setIfNot(string $headerName, string $headerValue): void {
        TypeAssert::notEmpty('headerName', $headerName);
        self::assertNoCrlf($headerName, $headerValue);
        if ($this->keyOf($headerName) !== null) {
            return;
        }
        $this->headers[$headerName] = [$headerValue];
        $this->names[strtolower($headerName)] = $headerName;
    }

    public function contains(string $headerName): bool {
        return $this->keyOf($headerName) !== null;
    }

    public function remove(string $headerName): void {
        $key = $this->keyOf($headerName);
        if ($key !== null) {
            unset($this->headers[$key], $this->names[strtolower($headerName)]);
        }
    }

    public function get(string $headerName): ?array {
        $key = $this->keyOf($headerName);
        return $key === null ? null : $this->headers[$key];
    }

    public function getFirst(string $headerName): ?string {
        $key = $this->keyOf($headerName);
        return $key === null ? null : $this->headers[$key][0];
    }

    public function setContentType(string $headerValue): void {
        $this->set(self::CONTENT_TYPE, $headerValue);
    }

    public function getContentType(): ?string {
        return $this->getFirst(self::CONTENT_TYPE);
    }

    public function setContentLength(int $headerValue): void {
        $this->set(self::CONTENT_LENGTH, "$headerValue");
    }

    public function getContentLength(): ?int {
        $val = $this->getFirst(self::CONTENT_LENGTH);
        return is_null($val) ? $val : intval($val);
    }

    public function setLocation(string $headerValue): void {
        $this->set(self::LOCATION, $headerValue);
    }

    public function getLocation(): ?string {
        return $this->getFirst(self::LOCATION);
    }

}