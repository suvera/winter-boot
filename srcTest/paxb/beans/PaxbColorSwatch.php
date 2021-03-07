<?php
declare(strict_types=1);

namespace test\winterframework\paxb\beans;

use dev\winterframework\paxb\attr\XmlAttribute;
use dev\winterframework\paxb\attr\XmlValue;

class PaxbColorSwatch {
    #[XmlAttribute]
    private string $image;

    #[XmlValue]
    private string $value;

    public function getImage(): string {
        return $this->image;
    }

    public function getValue(): string {
        return $this->value;
    }


}