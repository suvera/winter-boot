<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\core\web\config;

use dev\winterframework\core\web\HandlerInterceptor;
use dev\winterframework\exception\InvalidSyntaxException;

class InterceptorRegistry {

    /**
     * @var HandlerInterceptor[][]
     */
    protected array $interceptors = [];

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

}