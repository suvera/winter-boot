<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\bind;

use dev\winterframework\paxb\attr\XmlRootElement;
use dev\winterframework\paxb\attr\XmlValue;

#[XmlRootElement]
class XmlInterger implements XmlScalar {

    #[XmlValue]
    private ?int $value = null;

    public function getValue(): ?int {
        return $this->value;
    }

    public static function getXsdType(): string {
        return 'xsd:integer';
    }

    public function __toString() {
        return strval($this->value);
    }

}