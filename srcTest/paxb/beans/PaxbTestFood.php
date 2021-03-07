<?php
declare(strict_types=1);

namespace test\winterframework\paxb\beans;

use dev\winterframework\paxb\attr\XmlElement;

class PaxbTestFood {
    #[XmlElement]
    public string $name;

    #[XmlElement]
    public string $price;

    #[XmlElement]
    public string $description;

    #[XmlElement]
    public int $calories;

}