<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\bind;

class XmlNode {
    protected array $properties = [];
    protected string $value = '';
    /** @var XmlNode[] */
    protected array $children = [];

    public function __construct(
        protected string $name
    ) {
    }

    public function getProperties(): array {
        return $this->properties;
    }

    public function setProperties(array $properties): void {
        $this->properties = $properties;
    }

    public function getValue(): string {
        return $this->value;
    }

    public function setValue(string $value): void {
        $this->value = $value;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getChildren(): array {
        return $this->children;
    }

    public function addChildren(XmlNode $children): void {
        $this->children[] = $children;
    }

}