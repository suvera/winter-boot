<?php

declare(strict_types=1);

namespace winterBootTests\fixtures\webapp;

use dev\winterframework\core\web\HandlerInterceptor;
use dev\winterframework\web\http\HttpRequest;
use dev\winterframework\web\http\HttpStatus;
use dev\winterframework\web\http\ResponseEntity;
use Throwable;

/**
 * Cookie authentication like SocMon's WebSessionAuthenticator: preHandle
 * reads the "user" cookie into the coroutine context (401 without it),
 * afterCompletion clears it. Every call is counted per URI so tests can
 * assert each hook ran exactly once.
 */
class FixtureAuthInterceptor implements HandlerInterceptor {

    /** @var array<string, array<string, int>> Worker-local; the fixture runs one worker. */
    public static array $calls = [];

    private static function count(string $hook, HttpRequest $request): void {
        $uri = strtok($request->getUri(), '?');
        self::$calls[$uri][$hook] = (self::$calls[$uri][$hook] ?? 0) + 1;
    }

    public function preHandle(HttpRequest $request, ResponseEntity $response): bool {
        self::count('pre', $request);
        $user = $request->getCookie('user');
        if ($user === null || $user === '' || $user === 'nobody') {
            $response->withStatus(HttpStatus::$UNAUTHORIZED);
            $response->withJson(['error' => 'unauthorized']);
            return false;
        }
        FixtureContext::set($user);
        $response->withHeader('X-Fixture-Auth', 'ok');
        return true;
    }

    public function postHandle(HttpRequest $request, ResponseEntity $response): void {
        self::count('post', $request);
    }

    public function afterCompletion(HttpRequest $request, ResponseEntity $response, ?Throwable $ex = null): void {
        self::count('after', $request);
        FixtureContext::clear();
    }
}
