<?php
declare(strict_types=1);

namespace dev\winterframework\reflection;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\TypeAssert;

class MethodResources extends ArrayList {
    protected array $byName = [];

    /**
     * Cannot construct
     */
    protected function __construct() {
    }

    public function offsetGet($offset): ?MethodResource {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        /** @var MethodResource $value */
        TypeAssert::typeOf($value, MethodResource::class);
        parent::offsetSet($offset, $value);
        $this->byName[$value->getMethod()->getShortName()] = $value;
    }

    public function offsetUnset($offset): void {
        if (isset($this->values[$offset])) {
            unset($this->byName[$this->values[$offset]->getMethod()->getShortName()]);
        }
        parent::offsetUnset($offset);
    }

    public function getMethod(string $name): ?MethodResource {
        return isset($this->byName[$name]) ? $this->byName[$name] : null;
    }
}