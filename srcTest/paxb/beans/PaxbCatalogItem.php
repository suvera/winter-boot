<?php
declare(strict_types=1);

namespace test\winterframework\paxb\beans;

use dev\winterframework\paxb\attr\XmlAttribute;
use dev\winterframework\paxb\attr\XmlElement;

class PaxbCatalogItem {
    #[XmlAttribute]
    private string $gender;

    #[XmlElement(name: "item_number")]
    private string $itemNumber;

    #[XmlElement(name: "price")]
    private float $price = 0.0;

    #[XmlElement(name: "size", list: true, listClass: PaxbItemSize::class)]
    private array $sizes;

    public function getGender(): string {
        return $this->gender;
    }

    public function getItemNumber(): string {
        return $this->itemNumber;
    }

    public function getPrice(): float {
        return $this->price;
    }

    /**
     * @return PaxbItemSize[]
     */
    public function getSizes(): array {
        return $this->sizes;
    }

}