<?php

declare(strict_types=1);

namespace dev\winterframework\core\web\cors;

use dev\winterframework\core\context\PropertyContext;
use dev\winterframework\enums\RequestMethod;
use dev\winterframework\exception\WinterException;
use dev\winterframework\stereotype\web\CrossOrigin;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\web\http\HttpHeaders;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;

/**
 * Cross-Origin Resource Sharing policy (`winter.web.cors.*`).
 *
 * Deny by default: without `winter.web.cors.allowedOrigins` no CORS header is
 * ever sent and preflights are not intercepted. Origins match exactly
 * (scheme, host and port; case-insensitive) against the allowlist and the
 * response carries the configured entry, never the raw request value. The
 * `null` origin never matches. `*` is accepted as an origin only without
 * credentials; the combination is refused (CORS stays off, logged once).
 *
 * Pure logic with no server dependency, so it is unit-testable directly.
 */
final class CorsPolicy {
    use Wlf4p;

    public const DEFAULT_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];
    public const DEFAULT_HEADERS = ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With'];
    public const DEFAULT_MAX_AGE = 1800;
    public const MAX_MAX_AGE = 86400;

    private const TOKEN = "/^[A-Za-z0-9!#$%&'*+.^_`|~-]+$/";

    /** @var array<string, string> normalized origin => configured origin */
    private array $origins = [];
    private bool $anyOrigin = false;
    private bool $anyHeader = false;
    private bool $anyMethod = false;
    /** @var string[] upper-cased */
    private array $methods = [];
    /** @var array<string, string> lowercased => configured */
    private array $headers = [];
    /** @var string[] */
    private array $configuredOrigins;
    /** @var string[] */
    private array $configuredHeaders;

    /**
     * @param string[] $allowedOrigins
     * @param string[] $allowedMethods
     * @param string[] $allowedHeaders
     * @param string[] $exposedHeaders
     */
    public function __construct(
        array $allowedOrigins,
        array $allowedMethods = self::DEFAULT_METHODS,
        array $allowedHeaders = self::DEFAULT_HEADERS,
        private readonly array $exposedHeaders = [],
        private readonly bool $allowCredentials = false,
        private readonly int $maxAge = self::DEFAULT_MAX_AGE,
    ) {
        foreach ($allowedMethods as $method) {
            $method = strtoupper(trim($method));
            if ($method === '*') {
                $this->anyMethod = true;
            } elseif ($method !== '') {
                $this->methods[$method] = $method;
            }
        }
        $this->methods = array_values($this->methods);
        $this->configuredOrigins = $allowedOrigins;
        $this->configuredHeaders = $allowedHeaders;
        foreach ($allowedOrigins as $origin) {
            $origin = trim($origin);
            if ($origin === '*') {
                $this->anyOrigin = true;
            } elseif ($origin !== '' && $origin !== 'null') {
                // Browsers send the origin lowercased and compare the response
                // byte for byte, so the normalized form is what gets echoed.
                $key = self::normalizeOrigin($origin);
                $this->origins[$key] = $key;
            }
        }
        if ($this->anyOrigin && $this->allowCredentials) {
            throw new WinterException('CORS allowedOrigins "*" cannot be combined with allowCredentials');
        }
        foreach ($allowedHeaders as $header) {
            $header = trim($header);
            if ($header === '*') {
                $this->anyHeader = true;
            } elseif ($header !== '') {
                $this->headers[strtolower($header)] = $header;
            }
        }
    }

    /**
     * Builds the policy from `winter.web.cors.*`; null when CORS is off or
     * the configuration is unsafe (fail closed).
     */
    public static function fromProperties(PropertyContext $props): ?CorsPolicy {
        $origins = self::toList($props->get('winter.web.cors.allowedOrigins', []));
        if (empty($origins)) {
            return null;
        }
        $methods = self::toList($props->get('winter.web.cors.allowedMethods', []));
        $headers = self::toList($props->get('winter.web.cors.allowedHeaders', []));
        $maxAge = (int)$props->get('winter.web.cors.maxAge', self::DEFAULT_MAX_AGE);

        try {
            return new CorsPolicy(
                $origins,
                $methods ?: self::DEFAULT_METHODS,
                $headers ?: self::DEFAULT_HEADERS,
                self::toList($props->get('winter.web.cors.exposedHeaders', [])),
                $props->getBool('winter.web.cors.allowCredentials', false),
                max(0, min($maxAge, self::MAX_MAX_AGE)),
            );
        } catch (WinterException $e) {
            self::logError('winter.web.cors: ' . $e->getMessage() . '; CORS stays disabled');
            return null;
        }
    }

    /**
     * Layers `#[CrossOrigin]` attributes (class level first, then method
     * level) over the global policy: each non-empty field replaces the value
     * below it. Null when no origin is allowed or the result is unsafe
     * (fail closed). With no attributes the global policy is returned as is.
     */
    public static function resolve(?CorsPolicy $base, CrossOrigin ...$attributes): ?CorsPolicy {
        if (empty($attributes)) {
            return $base;
        }
        $origins = $base?->configuredOrigins ?? [];
        $methods = $base?->configuredMethods() ?? self::DEFAULT_METHODS;
        $headers = $base?->configuredHeaders ?? self::DEFAULT_HEADERS;
        $exposed = $base?->exposedHeaders ?? [];
        $credentials = $base?->allowCredentials ?? false;
        $maxAge = $base?->maxAge ?? self::DEFAULT_MAX_AGE;

        foreach ($attributes as $a) {
            $origins = self::toList($a->origins) ?: $origins;
            $methods = self::toList($a->methods) ?: $methods;
            $headers = self::toList($a->headers) ?: $headers;
            $exposed = self::toList($a->exposedHeaders) ?: $exposed;
            $credentials = $a->allowCredentials ?? $credentials;
            $maxAge = $a->maxAge ?? $maxAge;
        }
        if (empty($origins)) {
            self::logWarning('#[CrossOrigin] without origins (here or in winter.web.cors.allowedOrigins); CORS stays off for the route');
            return null;
        }
        try {
            return new CorsPolicy($origins, $methods, $headers, $exposed, $credentials, max(0, min($maxAge, self::MAX_MAX_AGE)));
        } catch (WinterException $e) {
            self::logError('#[CrossOrigin]: ' . $e->getMessage() . '; CORS stays off for the route');
            return null;
        }
    }

    /** @return string[] yml list or comma separated string, trimmed, no blanks */
    public static function toList(mixed $value): array {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }
        return $out;
    }

    /** @return string[] methods as configured (`*` kept) */
    private function configuredMethods(): array {
        return $this->anyMethod ? [...$this->methods, '*'] : $this->methods;
    }

    /** Origin in comparison form: trimmed, no trailing slash, lowercase. */
    public static function normalizeOrigin(string $origin): string {
        return strtolower(rtrim(trim($origin), '/'));
    }

    /** A CORS preflight: OPTIONS carrying Origin and Access-Control-Request-Method. */
    public static function isPreflight(HttpRequest $request): bool {
        return strtoupper($request->getMethod()) === RequestMethod::OPTIONS
            && self::header($request, HttpHeaders::ORIGIN) !== null
            && self::header($request, HttpHeaders::ACCESS_CONTROL_REQUEST_METHOD) !== null;
    }

    private static function header(HttpRequest $request, string $name): ?string {
        $value = $request->getFirstHeader($name);
        return ($value === null || trim($value) === '') ? null : trim($value);
    }

    /**
     * The Access-Control-Allow-Origin value for a request Origin, or null
     * when the origin is not allowed.
     */
    public function resolveOrigin(?string $origin): ?string {
        if ($origin === null || trim($origin) === '') {
            return null;
        }
        $key = self::normalizeOrigin($origin);
        if ($key === 'null') {
            return null;
        }
        if (isset($this->origins[$key])) {
            return $this->origins[$key];
        }
        return $this->anyOrigin ? '*' : null;
    }

    /**
     * Adds the CORS headers of an actual (non-preflight) request. A request
     * from a disallowed origin is still served; the browser withholds the
     * response from the page.
     */
    public function applyToResponse(HttpRequest $request, ResponseEntity $response): void {
        $origin = self::header($request, HttpHeaders::ORIGIN);
        if ($origin === null) {
            return;
        }
        $headers = $response->getHeaders();
        self::addVary($headers, HttpHeaders::ORIGIN);

        // Simple requests (GET/HEAD/POST) skip the preflight, so the method
        // allowlist is enforced here: no allow-origin header, the browser
        // withholds the response. The request itself is still served.
        $allowed = $this->methodAllowed(strtoupper($request->getMethod())) ? $this->resolveOrigin($origin) : null;
        if ($allowed === null) {
            return;
        }
        $headers->set(HttpHeaders::ACCESS_CONTROL_ALLOW_ORIGIN, $allowed);
        if ($this->allowCredentials) {
            $headers->set(HttpHeaders::ACCESS_CONTROL_ALLOW_CREDENTIALS, 'true');
        }
        if (!empty($this->exposedHeaders)) {
            $headers->set(HttpHeaders::ACCESS_CONTROL_EXPOSE_HEADERS, implode(', ', $this->exposedHeaders));
        }
    }

    /**
     * Fills the answer to a preflight: 204 with the Allow-* headers when the
     * origin, requested method and requested headers are all allowed, else 403
     * with no CORS header.
     */
    public function handlePreflight(HttpRequest $request, ResponseEntity $response): void {
        $headers = $response->getHeaders();
        self::addVary(
            $headers,
            HttpHeaders::ORIGIN,
            HttpHeaders::ACCESS_CONTROL_REQUEST_METHOD,
            HttpHeaders::ACCESS_CONTROL_REQUEST_HEADERS
        );

        $allowedOrigin = $this->resolveOrigin(self::header($request, HttpHeaders::ORIGIN));
        $method = strtoupper((string)self::header($request, HttpHeaders::ACCESS_CONTROL_REQUEST_METHOD));
        $requested = self::toList(self::header($request, HttpHeaders::ACCESS_CONTROL_REQUEST_HEADERS) ?? '');

        if ($allowedOrigin === null
            || !$this->methodAllowed($method)
            || !$this->headersAllowed($requested)
        ) {
            $response->withStatus(HttpStatus::$FORBIDDEN);
            return;
        }

        $response->withStatus(HttpStatus::$NO_CONTENT);
        $headers->set(HttpHeaders::ACCESS_CONTROL_ALLOW_ORIGIN, $allowedOrigin);
        $headers->set(
            HttpHeaders::ACCESS_CONTROL_ALLOW_METHODS,
            $this->anyMethod ? $method : implode(', ', $this->methods)
        );
        if ($this->allowCredentials) {
            $headers->set(HttpHeaders::ACCESS_CONTROL_ALLOW_CREDENTIALS, 'true');
        }
        $allowHeaders = $this->anyHeader ? $requested : array_values($this->headers);
        if (!empty($allowHeaders)) {
            $headers->set(HttpHeaders::ACCESS_CONTROL_ALLOW_HEADERS, implode(', ', $allowHeaders));
        }
        if ($this->maxAge > 0) {
            $headers->set(HttpHeaders::ACCESS_CONTROL_MAX_AGE, (string)$this->maxAge);
        }
    }

    /**
     * Merges tokens into one comma separated Vary value. Both output streams
     * replace a header of the same name on each write, so Vary must be a
     * single value; existing tokens (e.g. a controller's `Accept`) are kept.
     */
    private static function addVary(HttpHeaders $headers, string ...$tokens): void {
        $current = [];
        foreach ($headers->get(HttpHeaders::VARY) ?? [] as $value) {
            foreach (explode(',', $value) as $token) {
                if (trim($token) !== '') {
                    $current[] = trim($token);
                }
            }
        }
        if (in_array('*', $current, true)) {
            return;
        }
        $known = array_map('strtolower', $current);
        foreach ($tokens as $token) {
            if (!in_array(strtolower($token), $known, true)) {
                $current[] = $token;
                $known[] = strtolower($token);
            }
        }
        $headers->set(HttpHeaders::VARY, implode(', ', $current));
    }

    private function methodAllowed(string $method): bool {
        return $this->anyMethod || in_array($method, $this->methods, true);
    }

    /** @param string[] $requested */
    private function headersAllowed(array $requested): bool {
        foreach ($requested as $name) {
            if (!preg_match(self::TOKEN, $name)) {
                return false;
            }
            if (!$this->anyHeader && !isset($this->headers[strtolower($name)])) {
                return false;
            }
        }
        return true;
    }
}
