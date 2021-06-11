<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\bind;

use dev\winterframework\paxb\attr\XmlAttribute;
use dev\winterframework\paxb\attr\XmlElement;
use dev\winterframework\paxb\attr\XmlValue;
use dev\winterframework\reflection\VariableResource;

class ScalarPropertyValue {
    protected mixed $value;

    public function __construct(
        protected VariableResource $resource,
        protected object $object,
        protected object $annotation
    ) {
    }

    /**
     * @return XmlElement|XmlValue|XmlAttribute|object
     */
    public function getAnnotation(): object {
        return $this->annotation;
    }

    public function getResource(): VariableResource {
        return $this->resource;
    }

    public function getObject(): object {
        return $this->object;
    }

    public function getValue(): mixed {
        return $this->value;
    }

    public function setValue(mixed $value): void {
        $this->value = $value;
    }


}