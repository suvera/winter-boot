<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\core\web\config;

use dev\winterframework\core\web\HandlerInterceptor;
use dev\winterframework\exception\InvalidSyntaxException;
use dev\winterframework\web\websocket\WebSocketContextHook;

class InterceptorRegistry {

    /**
     * @var HandlerInterceptor[][]
     */
    protected array $interceptors = [];

    /**
     * @var WebSocketContextHook[]
     */
    protected array $webSocketContextHooks = [];

    public function addInterceptor(HandlerInterceptor $interceptor, string ...$regexPaths): void {
        foreach ($regexPaths as $regexPath) {
            if (preg_match('/' . $regexPath . '/', 'test string') === false) {
                throw new InvalidSyntaxException('HandlerInterceptor Path expression is invalid "$regexPath"');
            }
            if (!isset($this->interceptors[$regexPath])) {
                $this->interceptors[$regexPath] = [];
            }
            $this->interceptors[$regexPath][spl_object_id($interceptor)] = $interceptor;
        }
    }

    public function getInterceptors(): array {
        return $this->interceptors;
    }

    /**
     * Restores request-scoped context for every WebSocket call (see
     * {@see WebSocketContextHook}); hooks run in registration order
     * (clear() in reverse).
     */
    public function addWebSocketContextHook(WebSocketContextHook $hook): void {
        $this->webSocketContextHooks[spl_object_id($hook)] = $hook;
    }

    /** @return WebSocketContextHook[] */
    public function getWebSocketContextHooks(): array {
        return array_values($this->webSocketContextHooks);
    }

}