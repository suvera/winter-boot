<?php
declare(strict_types=1);

namespace examples\MyApp\data;

use dev\winterframework\paxb\attr\XmlElement;
use dev\winterframework\stereotype\JsonProperty;

class Product {

    #[JsonProperty]
    #[XmlElement]
    private string $name;

    #[JsonProperty]
    #[XmlElement]
    private float $price;

    public function getName(): string {
        return $this->name;
    }

    public function getPrice(): float {
        return $this->price;
    }
}