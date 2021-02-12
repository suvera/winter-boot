<?php
declare(strict_types=1);

namespace dev\winterframework\cache\impl;

use dev\winterframework\cache\ValueWrapper;
use dev\winterframework\core\System;

class SimpleValueWrapper implements ValueWrapper {

    public static ValueWrapper $NULL_VALUE;

    private int $accessTimeMs;
    private int $writeTimeMs;

    public function __construct(
        private mixed $value
    ) {
        $this->accessTimeMs = System::currentTimeMillis();
        $this->writeTimeMs = System::currentTimeMillis();
    }

    public function getAccessTimeMs(): int {
        return $this->accessTimeMs;
    }

    public function getWriteTimeMs(): int {
        return $this->writeTimeMs;
    }

    public function get(): mixed {
        $this->accessTimeMs = System::currentTimeMillis();
        return $this->value;
    }
}

SimpleValueWrapper::$NULL_VALUE = new SimpleValueWrapper(null);