<?php
declare(strict_types=1);

namespace test\winterframework\paxb\beans;

use dev\winterframework\paxb\attr\XmlElement;
use dev\winterframework\paxb\attr\XmlRootElement;

#[XmlRootElement('note')]
class PaxbTest01 {
    #[XmlElement]
    private string $to;

    #[XmlElement]
    private string $from;

    #[XmlElement]
    private string $heading;

    #[XmlElement]
    private string $body;

    public function getTo(): string {
        return $this->to;
    }

    public function getFrom(): string {
        return $this->from;
    }

    public function getHeading(): string {
        return $this->heading;
    }

    public function getBody(): string {
        return $this->body;
    }
}