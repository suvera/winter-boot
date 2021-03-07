<?php
declare(strict_types=1);

namespace test\winterframework\paxb\beans;

use dev\winterframework\paxb\attr\XmlElement;
use dev\winterframework\paxb\attr\XmlRootElement;

#[XmlRootElement('breakfast_menu')]
class PaxbTest02 {

    #[XmlElement(list: true, listClass: PaxbTestFood::class)]
    private array $food;

    /**
     * @return PaxbTestFood[]
     */
    public function getFood(): array {
        return $this->food;
    }

}