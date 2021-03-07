<?php
declare(strict_types=1);

namespace dev\winterframework\paxb;

use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ref\RefProperty;

trait XmlStereoTypeTrait {

    protected function findName(RefProperty|RefMethod $ref): string {
        if ($ref instanceof RefProperty) {
            return $ref->getName();
        } else {
            $name = $ref->getShortName();

            $set = substr($name, 0, 3);
            if ($set == 'set' || $set == 'get') {
                $name = substr($name, 3);
                $name[0] = strtolower($name[0]);
            }

            return $name;
        }
    }
}