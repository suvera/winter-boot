<?php
declare(strict_types=1);

namespace dev\winterframework\stereotype\web;

use Attribute;

/**
 * Per-controller / per-route CORS policy, layered over `winter.web.cors.*`.
 *
 * Every field left empty (or null) inherits the next level up: method level,
 * then class level, then the global config. Only a non-empty `origins` list
 * (here or in the global config) switches CORS on for a route; without one
 * the route sends no CORS header (deny by default). The attribute only decides
 * which CORS headers are sent, it never rejects a request.
 *
 * `origins: ['*']` with `allowCredentials: true` is refused (CORS stays off
 * for that route, an error is logged).
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class CrossOrigin {

    /**
     * @param string[] $origins Exact origins browsers may call from (`https://app.example.com`).
     * @param string[] $methods Methods a preflight may request.
     * @param string[] $headers Request headers a preflight may ask for.
     * @param string[] $exposedHeaders Response headers scripts may read.
     * @param bool|null $allowCredentials Send Access-Control-Allow-Credentials.
     * @param int|null $maxAge Seconds a browser may cache the preflight.
     */
    public function __construct(
        public readonly array $origins = [],
        public readonly array $methods = [],
        public readonly array $headers = [],
        public readonly array $exposedHeaders = [],
        public readonly ?bool $allowCredentials = null,
        public readonly ?int $maxAge = null,
    ) {
    }
}
