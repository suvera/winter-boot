<?php
declare(strict_types=1);

namespace dev\winterframework\type;

use ReflectionObject;

abstract class ArrayedObject {

    public function __construct($array = []) {
        $ref = new ReflectionObject($this);
        foreach ($ref->getProperties() as $p) {
            if ($p->isStatic()) {
                continue;
            }

            $propName = $p->getName();
            if (array_key_exists($propName, $array)) {
                if ($p->isPrivate()) {
                    $setter = 'set' . ucwords($propName);
                    $this->$setter($array[$propName]);
                } else {
                    $this->$propName = $array[$propName];
                }
            }
        }
    }

    public function toArray(): array {
        $ref = new ReflectionObject($this);
        $arr = [];
        foreach ($ref->getProperties() as $p) {
            if ($p->isStatic()) {
                continue;
            }

            $p->setAccessible(true);
            if ($p->isInitialized($this)) {
                $arr[$p->getName()] = $p->getValue($this);
            }
        }

        return $arr;
    }

}