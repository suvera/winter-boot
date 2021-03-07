<?php
declare(strict_types=1);

namespace dev\winterframework\paxb\attr;

use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ref\RefMethod;
use dev\winterframework\reflection\ref\RefProperty;
use dev\winterframework\stereotype\StereoType;

interface XmlStereoType extends StereoType {

    //public function getXmlSchema(): string;
    public function getRefOwner(): RefProperty|RefMethod|RefKlass;
}