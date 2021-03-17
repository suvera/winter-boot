<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\bind;

use dev\winterframework\paxb\attr\XmlStereoType;
use dev\winterframework\reflection\MethodResource;
use dev\winterframework\reflection\VariableResource;

class XmlBeanAnnotation {

    public function __construct(
        protected XmlStereoType $annotation,
        protected VariableResource|MethodResource $resource,
    ) {
    }

    public function getAnnotation(): XmlStereoType {
        return $this->annotation;
    }

    public function getResource(): VariableResource|MethodResource {
        return $this->resource;
    }

}