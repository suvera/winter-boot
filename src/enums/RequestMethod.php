<?php
declare(strict_types=1);

namespace dev\winterframework\enums;

final class RequestMethod {
    const GET = 'GET';
    const HEAD = "HEAD";
    const POST = "POST";
    const PUT = "PUT";
    const PATCH = "PATCH";
    const DELETE = "DELETE";
    const OPTIONS = "OPTIONS";
    const TRACE = "TRACE";

    private static array $METHODS = [
        self::GET => self::GET,
        self::HEAD => self::HEAD,
        self::POST => self::POST,
        self::PUT => self::PUT,
        self::PATCH => self::PATCH,
        self::DELETE => self::DELETE,
        self::OPTIONS => self::OPTIONS,
        self::TRACE => self::TRACE,
    ];

    public static function getAll(): array {
        return self::$METHODS;
    }
}