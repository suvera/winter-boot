<?php
/** @noinspection PhpUnused */

declare(strict_types=1);

namespace dev\winterframework\type;

final class AttributeList extends ArrayList {
    private array $byName = [];

    protected function __construct() {
    }

    public function offsetGet($offset): ?object {
        return parent::offsetGet($offset);
    }

    public function offsetUnset($offset): void {
        if (isset($this->values[$offset])) {
            $obj = $this->values[$offset];
            unset($this->byName[$obj::class][spl_object_hash($obj)]);
        }

        parent::offsetUnset($offset);
    }

    public function offsetSet($offset, $value): void {
        TypeAssert::object($value);
        parent::offsetSet($offset, $value);

        if (!isset($this->byName[$value::class])) {
            $this->byName[$value::class] = [];
        }
        $this->byName[$value::class][spl_object_hash($value)] = $value;
    }

    public function getByNames(string ...$names): array {
        $new = [];

        foreach ($names as $name) {
            if (isset($this->byName[$name])) {
                $new = array_merge($new, $this->byName[$name]);
            }
        }

        return $new;
    }

    public function getByName(string $name): ?object {
        if (isset($this->byName[$name])) {
            $key = array_key_first($this->byName[$name]);
            return $this->byName[$name][$key];
        }

        return null;
    }

}