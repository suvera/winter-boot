<?php
declare(strict_types=1);

namespace test\winterframework\paxb;

use dev\winterframework\paxb\bind\XmlSchemaCreator;
use PHPUnit\Framework\TestCase;
use test\winterframework\paxb\beans\PaxbProduct;

class XmlSchemaCreatorTests extends TestCase {

    public function testXmlSchema01(): void {
        $creator = new XmlSchemaCreator(PaxbProduct::class);
        $xsd = $creator->buildXsd();

        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xsd);
        $xml = $dom->saveXML();

        //echo "\n$xml\n";
        $this->assertNotEmpty($xml);
    }

}