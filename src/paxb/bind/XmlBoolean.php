<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\bind;

use dev\winterframework\paxb\attr\XmlRootElement;
use dev\winterframework\paxb\attr\XmlValue;

#[XmlRootElement]
class XmlBoolean implements XmlScalar {

    #[XmlValue]
    private ?bool $value = null;

    public function getValue(): ?bool {
        return $this->value;
    }

    public static function getXsdType(): string {
        return 'xsd:boolean';
    }

    public function __toString() {
        return $this->value ? 'true' : 'false';
    }


}