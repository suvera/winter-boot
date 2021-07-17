<?php
/** @noinspection PhpUnused */

declare(strict_types=1);

namespace dev\winterframework\stereotype\util;

use dev\winterframework\type\ArrayList;
use dev\winterframework\type\TypeAssert;

class UriPath extends ArrayList {

    public function offsetGet($offset): ?UriPathPart {
        return parent::offsetGet($offset);
    }

    public function offsetSet($offset, $value): void {
        TypeAssert::typeOf($value, UriPathPart::class);
        parent::offsetSet($offset, $value);
    }

    public function getRegex(): string {
        return implode(
            '\/',
            array_map(function (UriPathPart $p) {
                return $p->getRegex();
            }, $this->values)
        );
    }

    public function getNormalized(): string {
        return implode(
            '/',
            array_map(function (UriPathPart $p) {
                return $p->getNormalized();
            }, $this->values)
        );
    }

    public function getRaw(): string {
        return implode(
            '/',
            array_map(function (UriPathPart $p) {
                return $p->getPart();
            }, $this->values)
        );
    }
}