<?php
declare(strict_types=1);

namespace test\winterframework\paxb\beans;

use dev\winterframework\paxb\attr\XmlAnyElement;
use dev\winterframework\paxb\attr\XmlAttribute;
use dev\winterframework\paxb\attr\XmlElement;
use dev\winterframework\paxb\attr\XmlRootElement;

#[XmlRootElement('product')]
class PaxbProduct {
    #[XmlAttribute]
    private string $description;

    #[XmlAttribute(name: "product_image")]
    private string $productImage;

    #[XmlElement(name: "catalog_item", list: true, listClass: PaxbCatalogItem::class)]
    private array $catalogItems;

    #[XmlAnyElement]
    private ?array $extras = null;

    public function getDescription(): string {
        return $this->description;
    }

    public function getProductImage(): string {
        return $this->productImage;
    }

    /**
     * @return PaxbCatalogItem[]
     */
    public function getCatalogItems(): array {
        return $this->catalogItems;
    }

    public function getExtras(): mixed {
        return $this->extras;
    }


}