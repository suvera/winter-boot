<?php
declare(strict_types=1);

namespace dev\winterframework\mcp;

use dev\winterframework\stereotype\RestController;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use dev\winterframework\web\MediaType;
use JsonException;

/**
 * The built-in MCP endpoint (Streamable HTTP, stateless JSON responses).
 * Registered by WinterWebContext only when the application has at least
 * one #[McpTool]; it is an ordinary route, so the application's
 * HandlerInterceptors (auth, rate limits) run on it.
 *
 * GET (server-sent event stream) and DELETE (session end) are answered
 * with 405: this server keeps no sessions and sends no notifications.
 */
#[RestController]
class McpController {

    public const DEFAULT_PATH = '/mcp';
    public const DEFAULT_MAX_BODY_BYTES = 1_048_576;
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param string[] $allowedOrigins exact Origin values browsers may call from
     */
    public function __construct(
        private readonly McpServer $server,
        private readonly array $allowedOrigins = [],
        private readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ) {
    }

    public function getServer(): McpServer {
        return $this->server;
    }

    public function post(HttpRequest $request): ResponseEntity {
        $rejected = self::checkTransport($request, $this->allowedOrigins, $this->maxBodyBytes);
        if ($rejected !== null) {
            return $rejected;
        }

        try {
            $message = json_decode($request->getRawBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::rpcErrorResponse(HttpStatus::$BAD_REQUEST, McpServer::PARSE_ERROR, 'parse error');
        }

        if (is_array($message) && $message !== [] && array_is_list($message)) {
            // JSON-RPC batching was removed from MCP in 2025-06-18.
            return self::rpcErrorResponse(
                HttpStatus::$BAD_REQUEST,
                McpServer::INVALID_REQUEST,
                'batch requests are not supported'
            );
        }

        $response = $this->server->handle($message, $request);
        if ($response === null) {
            return ResponseEntity::accepted();
        }
        return self::jsonResponse(HttpStatus::$OK, $response);
    }

    public function notAllowed(): ResponseEntity {
        return ResponseEntity::status(HttpStatus::$METHOD_NOT_ALLOWED)
            ->withHeader('Allow', 'POST');
    }

    /**
     * Fail-closed transport checks, in order: Origin (DNS-rebinding
     * protection), MCP-Protocol-Version, Content-Type, Accept, body size.
     * Returns the error response, or null when the request may proceed.
     *
     * @param string[] $allowedOrigins
     */
    public static function checkTransport(HttpRequest $request, array $allowedOrigins, int $maxBodyBytes): ?ResponseEntity {
        $origin = $request->getFirstHeader('Origin');
        if ($origin !== null && $origin !== '' && !self::isAllowedOrigin($origin, $allowedOrigins)) {
            return self::rpcErrorResponse(HttpStatus::$FORBIDDEN, McpServer::INVALID_REQUEST, 'origin not allowed');
        }

        $version = $request->getFirstHeader('MCP-Protocol-Version');
        if ($version !== null && !in_array($version, McpServer::SUPPORTED_VERSIONS, true)) {
            return self::rpcErrorResponse(
                HttpStatus::$BAD_REQUEST,
                McpServer::INVALID_REQUEST,
                'unsupported MCP-Protocol-Version; supported: ' . implode(', ', McpServer::SUPPORTED_VERSIONS)
            );
        }

        $contentType = strtolower(trim((string)strtok($request->getContentType() ?: (string)$request->getFirstHeader('Content-Type'), ';')));
        if ($contentType !== MediaType::APPLICATION_JSON) {
            return self::rpcErrorResponse(
                HttpStatus::$UNSUPPORTED_MEDIA_TYPE,
                McpServer::INVALID_REQUEST,
                'Content-Type must be application/json'
            );
        }

        $accept = $request->getFirstHeader('Accept');
        if ($accept !== null && trim($accept) !== '' && !self::acceptsJson($accept)) {
            return self::rpcErrorResponse(
                HttpStatus::$NOT_ACCEPTABLE,
                McpServer::INVALID_REQUEST,
                'Accept must allow application/json'
            );
        }

        if (strlen($request->getRawBody()) > $maxBodyBytes) {
            return self::rpcErrorResponse(
                HttpStatus::$PAYLOAD_TOO_LARGE,
                McpServer::INVALID_REQUEST,
                'request too large'
            );
        }
        return null;
    }

    /**
     * Exact match against the configured list (scheme, host and port as
     * the browser sends them). There is no same-host shortcut: under DNS
     * rebinding the attacker's Origin and Host are the same name.
     *
     * @param string[] $allowedOrigins
     */
    public static function isAllowedOrigin(string $origin, array $allowedOrigins): bool {
        $origin = strtolower(rtrim(trim($origin), '/'));
        foreach ($allowedOrigins as $allowed) {
            if (is_string($allowed) && $origin === strtolower(rtrim(trim($allowed), '/'))) {
                return true;
            }
        }
        return false;
    }

    public static function acceptsJson(string $accept): bool {
        foreach (explode(',', $accept) as $range) {
            $type = strtolower(trim((string)strtok($range, ';')));
            if (in_array($type, ['application/json', 'application/*', '*/*'], true)) {
                return true;
            }
        }
        return false;
    }

    public static function rpcErrorResponse(HttpStatus $status, int $code, string $message): ResponseEntity {
        return self::jsonResponse($status, McpServer::error(null, $code, $message));
    }

    private static function jsonResponse(HttpStatus $status, array $payload): ResponseEntity {
        // Encoded here (not by the renderer) to keep {} vs [] exact.
        return ResponseEntity::status($status)
            ->withContentType(MediaType::APPLICATION_JSON)
            ->setBody(json_encode($payload, self::JSON_FLAGS));
    }
}
