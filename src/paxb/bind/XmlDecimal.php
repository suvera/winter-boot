<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\bind;

use dev\winterframework\paxb\attr\XmlRootElement;
use dev\winterframework\paxb\attr\XmlValue;

#[XmlRootElement]
class XmlDecimal implements XmlScalar {

    #[XmlValue]
    private ?float $value = null;

    public function getValue(): ?float {
        return $this->value;
    }

    public static function getXsdType(): string {
        return 'xsd:decimal';
    }

    public function __toString() {
        return strval($this->value);
    }
}