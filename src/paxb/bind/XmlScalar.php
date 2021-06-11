<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\bind;

interface XmlScalar {

    public function getValue(): mixed;

    public static function getXsdType(): string;
}