<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\core\web\config;

use dev\winterframework\core\web\HandlerInterceptor;
use dev\winterframework\exception\InvalidSyntaxException;
use dev\winterframework\core\web\RequestContextPropagator;

class InterceptorRegistry {

    /**
     * @var HandlerInterceptor[][]
     */
    protected array $interceptors = [];

    /**
     * @var RequestContextPropagator[]
     */
    protected array $contextPropagators = [];

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
     * Carries request-scoped context into later coroutines of the same
     * request (every WebSocket message and close); see
     * {@see RequestContextPropagator}. restore() runs in registration order,
     * clear() in reverse.
     */
    public function addContextPropagator(RequestContextPropagator $propagator): void {
        $this->contextPropagators[spl_object_id($propagator)] = $propagator;
    }

    /** @return RequestContextPropagator[] */
    public function getContextPropagators(): array {
        return array_values($this->contextPropagators);
    }

}