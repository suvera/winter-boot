<?php

declare(strict_types=1);

namespace dev\winterframework\web\client;

use dev\winterframework\reflection\ObjectCreator;
use dev\winterframework\web\http\HttpHeaders;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\MediaType;

/**
 * Spring-style synchronous HTTP client, available as a default bean.
 *
 * Autowire it anywhere: `#[Autowired] RestTemplate $restTemplate;`
 *
 * Mirrors Spring Boot's RestTemplate surface: URI templates
 * (`/users/{id}`), query merging, JSON request/response conversion and
 * error-status translation. Response bodies map by `$responseType`:
 * `'array'` for decoded JSON, `'string'` for the raw body, or a class name
 * for {@see ObjectCreator} mapping. 4xx responses throw
 * {@see HttpClientErrorException}, 5xx throw {@see HttpServerErrorException}.
 *
 * Threading/runtime: safe to share one instance. The underlying transport
 * ({@see DefaultRestClientTransport}) uses the coroutine client inside a
 * Swoole coroutine and cURL/streams elsewhere, so the same bean works from
 * request handlers, CLI commands and plain unit tests.
 */
class RestTemplate {
    public const DEFAULT_TIMEOUT = 10.0;
    public const DEFAULT_CONNECT_TIMEOUT = 5.0;

    /**
     * Desktop-browser User-Agent sent by default. Some publishers and WAFs
     * reject headerless or bot-looking clients with HTTP 403; a real browser
     * string avoids that whole failure class. Override per request or via
     * {@see setDefaultHeader()}.
     */
    public const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/126.0.0.0 Safari/537.36';

    private RestClientTransport $transport;
    private float $timeout;
    private float $connectTimeout;
    /** @var array<string, string> */
    private array $defaultHeaders = [];
    private ?string $basicUser = null;
    private ?string $basicPassword = null;
    private bool $throwOnError = true;
    /** @var ?callable */
    private $errorHandler = null;

    public function __construct(
        ?RestClientTransport $transport = null,
        float $timeout = self::DEFAULT_TIMEOUT,
        float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        array $defaultHeaders = []
    ) {
        $this->transport = $transport ?? new DefaultRestClientTransport();
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->defaultHeaders = array_merge(
            [
                'Accept' => MediaType::APPLICATION_JSON,
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'identity',
                'User-Agent' => self::DEFAULT_USER_AGENT,
            ],
            $defaultHeaders
        );
    }

    public function setTimeout(float $timeout): void {
        $this->timeout = $timeout;
    }

    public function getTimeout(): float {
        return $this->timeout;
    }

    public function setConnectTimeout(float $connectTimeout): void {
        $this->connectTimeout = $connectTimeout;
    }

    public function getConnectTimeout(): float {
        return $this->connectTimeout;
    }

    public function setDefaultHeader(string $name, string $value): void {
        $this->removeDefaultHeader($name);
        $this->defaultHeaders[$name] = $value;
    }

    public function removeDefaultHeader(string $name): void {
        foreach (array_keys($this->defaultHeaders) as $existing) {
            if (strtolower($existing) === strtolower($name)) {
                unset($this->defaultHeaders[$existing]);
            }
        }
    }

    public function setBasicAuth(string $username, string $password): void {
        $this->basicUser = $username;
        $this->basicPassword = $password;
    }

    public function clearBasicAuth(): void {
        $this->basicUser = null;
        $this->basicPassword = null;
    }

    /**
     * When true (default, like Spring), 4xx/5xx responses throw. When false,
     * they are returned as entities with the raw string body instead.
     */
    public function setThrowOnError(bool $throw): void {
        $this->throwOnError = $throw;
    }

    /**
     * Custom error hook, mirroring Spring's ResponseErrorHandler. It is
     * called for every 4xx/5xx response instead of throwing:
     * `fn(string $method, string $url, int $status, string $body, array $headers): void`.
     * It may throw its own exception; if it returns, the call yields an
     * entity with the raw string body.
     */
    public function setErrorHandler(?callable $handler): void {
        $this->errorHandler = $handler;
    }

    /**
     * @param array<string, string|int|float|bool> $uriVars values for {placeholders}
     * @param array<string, string> $headers extra headers for this call
     * @param array<string, string|int|float|bool> $query merged over the url query
     */
    public function getForObject(
        string $url,
        string $responseType = 'array',
        array $uriVars = [],
        array $headers = [],
        array $query = []
    ): mixed {
        return $this->exchange('GET', $url, HttpEntity::withHeaders($headers), $responseType, $uriVars, $query)
            ->getBody();
    }

    /**
     * @param array<string, string|int|float|bool> $uriVars
     * @param array<string, string> $headers
     * @param array<string, string|int|float|bool> $query
     */
    public function getForEntity(
        string $url,
        string $responseType = 'array',
        array $uriVars = [],
        array $headers = [],
        array $query = []
    ): ResponseEntity {
        return $this->exchange('GET', $url, HttpEntity::withHeaders($headers), $responseType, $uriVars, $query);
    }

    /**
     * Array/object bodies are JSON-encoded; strings go out verbatim.
     *
     * @param array<string, string|int|float|bool> $uriVars
     * @param array<string, string> $headers
     * @param array<string, string|int|float|bool> $query
     */
    public function postForObject(
        string $url,
        mixed $body,
        string $responseType = 'array',
        array $uriVars = [],
        array $headers = [],
        array $query = []
    ): mixed {
        return $this->exchange('POST', $url, new HttpEntity($body, $headers), $responseType, $uriVars, $query)
            ->getBody();
    }

    /**
     * @param array<string, string|int|float|bool> $uriVars
     * @param array<string, string> $headers
     * @param array<string, string|int|float|bool> $query
     */
    public function postForEntity(
        string $url,
        mixed $body,
        string $responseType = 'array',
        array $uriVars = [],
        array $headers = [],
        array $query = []
    ): ResponseEntity {
        return $this->exchange('POST', $url, new HttpEntity($body, $headers), $responseType, $uriVars, $query);
    }

    /**
     * @param array<string, string|int|float|bool> $uriVars
     * @param array<string, string> $headers
     * @param array<string, string|int|float|bool> $query
     */
    public function put(
        string $url,
        mixed $body,
        array $uriVars = [],
        array $headers = [],
        array $query = []
    ): void {
        $this->exchange('PUT', $url, new HttpEntity($body, $headers), 'string', $uriVars, $query);
    }

    /**
     * @param array<string, string|int|float|bool> $uriVars
     * @param array<string, string> $headers
     * @param array<string, string|int|float|bool> $query
     */
    public function delete(
        string $url,
        array $uriVars = [],
        array $headers = [],
        array $query = []
    ): void {
        $this->exchange('DELETE', $url, HttpEntity::withHeaders($headers), 'string', $uriVars, $query);
    }

    /**
     * @param array<string, string|int|float|bool> $uriVars
     * @param array<string, string> $headers
     * @param array<string, string|int|float|bool> $query
     */
    public function headForHeaders(
        string $url,
        array $uriVars = [],
        array $headers = [],
        array $query = []
    ): HttpHeaders {
        $entity = $this->exchange('HEAD', $url, HttpEntity::withHeaders($headers), 'string', $uriVars, $query);
        return $entity->getHeaders();
    }

    /**
     * Full-control call, mirroring Spring's exchange(): any method with an
     * optional {@see HttpEntity} (headers + body) and a response type.
     *
     * @param array<string, string|int|float|bool> $uriVars
     * @param array<string, string|int|float|bool> $query
     */
    public function exchange(
        string $method,
        string $url,
        ?HttpEntity $entity,
        string $responseType = 'array',
        array $uriVars = [],
        array $query = []
    ): ResponseEntity {
        $method = strtoupper($method);
        $entity = $entity ?? HttpEntity::empty();

        $targetUrl = self::appendQuery(self::expandUri($url, $uriVars), $query);
        [$payload, $requestHeaders] = $this->encodeBody($entity);

        $result = $this->transport->request(
            $method,
            $targetUrl,
            $requestHeaders,
            $payload,
            $this->timeout,
            $this->connectTimeout
        );

        $status = (int)($result['status'] ?? 0);
        /** @var array<string, string[]> $responseHeaders */
        $responseHeaders = $result['headers'] ?? [];
        $responseBody = (string)($result['body'] ?? '');

        if ($status >= 400) {
            $this->handleError($method, $targetUrl, $status, $responseHeaders, $responseBody);
            if (!$this->throwOnError && $this->errorHandler === null) {
                return self::toEntity($status, $responseHeaders, $responseBody);
            }
        }

        return self::toEntity($status, $responseHeaders, self::convertBody($responseBody, $responseType));
    }

    /**
     * @param array<string, string[]> $responseHeaders
     * @throws HttpStatusCodeException
     */
    private function handleError(
        string $method,
        string $url,
        int $status,
        array $responseHeaders,
        string $responseBody
    ): void {
        if ($this->errorHandler !== null) {
            ($this->errorHandler)($method, $url, $status, $responseBody, $responseHeaders);
            return;
        }
        if (!$this->throwOnError) {
            return;
        }
        $statusText = self::reasonPhrase($status);
        if ($status >= 500) {
            throw new HttpServerErrorException(
                $status, $statusText, $responseHeaders, $responseBody, $method, $url
            );
        }
        throw new HttpClientErrorException(
            $status, $statusText, $responseHeaders, $responseBody, $method, $url
        );
    }

    /** @return array{0: ?string, 1: array<string, string>} */
    private function encodeBody(HttpEntity $entity): array {
        $headers = $this->mergeHeaders($entity->getHeaders());
        $body = $entity->getBody();

        if ($body === null) {
            return [null, $headers];
        }

        if (is_string($body)) {
            return [$body, $headers];
        }

        try {
            $payload = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RestClientException('Could not JSON-encode request body.', 0, $e);
        }
        if (!self::hasHeader($headers, 'Content-Type')) {
            $headers['Content-Type'] = MediaType::APPLICATION_JSON;
        }
        return [$payload, $headers];
    }

    /** @param array<string, string> $extra */
    private function mergeHeaders(array $extra): array {
        $merged = $this->defaultHeaders;
        foreach ($extra as $name => $value) {
            self::assertNoCrlf((string)$name, (string)$value);
            foreach (array_keys($merged) as $existing) {
                if (strtolower($existing) === strtolower((string)$name)) {
                    unset($merged[$existing]);
                }
            }
            $merged[(string)$name] = (string)$value;
        }
        if ($this->basicUser !== null && $this->basicPassword !== null
            && !self::hasHeader($merged, 'Authorization')) {
            $merged['Authorization'] = 'Basic '
                . base64_encode($this->basicUser . ':' . $this->basicPassword);
        }
        return $merged;
    }

    /** @param array<string, string> $headers */
    private static function hasHeader(array $headers, string $name): bool {
        foreach (array_keys($headers) as $existing) {
            if (strtolower((string)$existing) === strtolower($name)) {
                return true;
            }
        }
        return false;
    }

    private static function assertNoCrlf(string $name, string $value): void {
        if (str_contains($name, "\r") || str_contains($name, "\n")
            || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new RestClientException('Invalid header: header names and values must not contain CR/LF');
        }
    }

    /**
     * Replaces {placeholders} with rawurlencoded values. Unknown placeholders
     * fail closed instead of sending a half-built URL.
     *
     * @param array<string, string|int|float|bool> $vars
     */
    public static function expandUri(string $url, array $vars): string {
        if ($vars === [] && !str_contains($url, '{')) {
            return $url;
        }
        $missing = [];
        $expanded = preg_replace_callback(
            '#\{([A-Za-z0-9_]+)\}#',
            function (array $m) use ($vars, &$missing): string {
                if (!array_key_exists($m[1], $vars)) {
                    $missing[] = $m[1];
                    return $m[0];
                }
                $value = $vars[$m[1]];
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                return rawurlencode((string)$value);
            },
            $url
        );
        if ($missing !== []) {
            throw new RestClientException(
                'Missing URI variables for url template: ' . implode(', ', $missing)
            );
        }
        return (string)$expanded;
    }

    /** @param array<string, string|int|float|bool> $query */
    public static function appendQuery(string $url, array $query): string {
        if ($query === []) {
            return $url;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            throw new RestClientException('Invalid request url: ' . self::sanitizeUrl($url));
        }
        $existing = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $existing);
        }
        $merged = array_merge($existing, $query);
        $qs = http_build_query($merged);

        $base = $url;
        $pos = strpos($url, '?');
        if ($pos !== false) {
            $base = substr($url, 0, $pos);
        }
        $hash = strpos($base, '#');
        $fragment = '';
        if ($hash !== false) {
            $fragment = substr($base, $hash);
            $base = substr($base, 0, $hash);
        }
        return $qs !== '' ? $base . '?' . $qs . $fragment : $base . $fragment;
    }

    /**
     * Query- and fragment-stripped URL for exceptions and logs: query values
     * routinely carry tokens and secrets, so they never appear in messages.
     */
    public static function sanitizeUrl(string $url): string {
        $parts = parse_url($url);
        if ($parts === false) {
            return '(invalid url)';
        }
        $out = '';
        if (isset($parts['scheme'])) {
            $out .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $out .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $out .= ':' . $parts['port'];
        }
        $out .= $parts['path'] ?? '/';
        return $out;
    }

    private static function convertBody(string $body, string $responseType): mixed {
        if ($responseType === 'string') {
            return $body;
        }
        if ($body === '') {
            return null;
        }
        if ($responseType === 'array') {
            try {
                return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                throw new RestClientException('Could not JSON-decode response body.', 0, $e);
            }
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RestClientException('Could not JSON-decode response body.', 0, $e);
        }
        if (!is_array($decoded)) {
            throw new RestClientException(
                'Cannot map response body to ' . $responseType . ': expected a JSON object or array.'
            );
        }
        try {
            return ObjectCreator::createObject($responseType, $decoded);
        } catch (\Throwable $e) {
            throw new RestClientException(
                'Cannot map response body to ' . $responseType . '.', 0, $e
            );
        }
    }

    /** @param array<string, string[]> $headers */
    private static function toEntity(int $status, array $headers, mixed $body): ResponseEntity {
        $entity = new ResponseEntity();
        $entity->withStatus(self::toHttpStatus($status));
        $httpHeaders = new HttpHeaders();
        foreach ($headers as $name => $values) {
            $first = true;
            foreach ((array)$values as $value) {
                if ($first) {
                    $first = false;
                    $httpHeaders->set((string)$name, (string)$value);
                } else {
                    $httpHeaders->add((string)$name, (string)$value);
                }
            }
        }
        $entity->setHeaders($httpHeaders);
        $entity->setBody($body);
        return $entity;
    }

    private static function toHttpStatus(int $status): HttpStatus {
        try {
            // @: getStatus() reads a static map with no has() guard, so an
            // exotic code raises a warning before the TypeError we catch.
            return @HttpStatus::getStatus($status);
        } catch (\Throwable) {
            return new HttpStatus($status, '');
        }
    }

    private static function reasonPhrase(int $status): string {
        try {
            return @HttpStatus::getStatus($status)->getReasonPhrase();
        } catch (\Throwable) {
            return '';
        }
    }
}
