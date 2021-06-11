<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\bind;

use dev\winterframework\paxb\attr\XmlRootElement;
use dev\winterframework\paxb\attr\XmlValue;
use Stringable;

#[XmlRootElement]
class XmlString implements Stringable, XmlScalar {

    #[XmlValue]
    private ?string $value = null;

    public function __toString() {
        return $this->value;
    }

    public function getValue(): ?string {
        return $this->value;
    }

    public static function getXsdType(): string {
        return 'xsd:string';
    }


}