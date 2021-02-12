<?php
declare(strict_types=1);

namespace dev\winterframework\cache;

use dev\winterframework\stereotype\aop\AopContext;

interface KeyGenerator {
    
    public function generate(AopContext $ctx, object $obj, array $args): string;
}