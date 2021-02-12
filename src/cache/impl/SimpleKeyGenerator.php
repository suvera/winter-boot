<?php
declare(strict_types=1);

namespace dev\winterframework\cache\impl;

use dev\winterframework\cache\KeyGenerator;
use dev\winterframework\stereotype\aop\AopContext;

class SimpleKeyGenerator implements KeyGenerator {

    public function generate(AopContext $ctx, object $obj, array $args): string {
        if (count($args) == 0) {
            return $ctx->getMethod()->getName();
        }

        $key = $ctx->getMethod()->getName() . '_';
        foreach ($args as $arg) {
            if (is_scalar($arg)) {
                $key .= json_encode($arg) . '-';
            } else if (is_null($arg)) {
                $key .= 'null-';
            } else if (is_array($arg)) {
                $key .= 'array-';
            } else if (is_object($arg) && method_exists($arg, '__toString')) {
                $key .= $arg->__toString() . '-';
            } else {
                $key .= gettype($arg) . '-';
            }
        }

        return substr($key, 0, 255);
    }

}