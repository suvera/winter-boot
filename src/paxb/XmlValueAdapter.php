<?php
declare(strict_types=1);

namespace dev\winterframework\paxb;

interface XmlValueAdapter {

    /**
     * Convert php value "to XML"
     */
    public function marshal(mixed $val): string|int|float;

    /**
     *  "From XML" to php value
     */
    public function unmarshal(string|int|float $val): mixed;
}