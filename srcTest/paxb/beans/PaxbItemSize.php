<?php
declare(strict_types=1);

namespace test\winterframework\paxb\beans;

use dev\winterframework\paxb\attr\XmlAttribute;
use dev\winterframework\paxb\attr\XmlElement;

class PaxbItemSize {
    #[XmlAttribute]
    private string $description;

    #[XmlElement(name: "color_swatch", list: true, listClass: PaxbColorSwatch::class)]
    private array $colorSwatch;

    public function getDescription(): string {
        return $this->description;
    }

    /**
     * @return PaxbColorSwatch[]
     */
    public function getColorSwatch(): array {
        return $this->colorSwatch;
    }

}