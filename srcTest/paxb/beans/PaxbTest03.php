<?php
declare(strict_types=1);

namespace test\winterframework\paxb\beans;

use dev\winterframework\paxb\attr\XmlElement;
use dev\winterframework\paxb\attr\XmlRootElement;

#[XmlRootElement('catalog')]
class PaxbTest03 {

    #[XmlElement(name: "product", list: true, listClass: PaxbProduct::class)]
    private array $products;

    /**
     * @return PaxbProduct[]
     */
    public function getProducts(): array {
        return $this->products;
    }

}