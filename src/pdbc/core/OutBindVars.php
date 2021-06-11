<?php
declare(strict_types=1);

namespace dev\winterframework\pdbc\core;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\TypeAssert;

class OutBindVars extends ArrayList {

    public function offsetGet($offset): ?OutBindVar {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        if (is_array($value) && isset($value[0]) && isset($value[1])) {
            $value = new OutBindVar($value[0], $value[1], $value[2] ?? BindType::STRING);
        }
        TypeAssert::typeOf($value, OutBindVar::class);
        /** @var OutBindVar $value */
        $offset = $value->getName();
        parent::offsetSet($offset, $value);
    }

    public function add(string $name, mixed $value, int $type = BindType::STRING): static {
        $this[] = new OutBindVar($name, $value, $type);
        return $this;
    }

    public function merge(self $other): void {
        foreach ($other as $row) {
            $this[] = $row;
        }
    }

}