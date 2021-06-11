<?php
declare(strict_types=1);

namespace test\winterframework\paxb;

use dev\winterframework\io\file\BasicFile;
use dev\winterframework\paxb\bind\XmlNode;
use dev\winterframework\paxb\XmlObjectMapper;
use PHPUnit\Framework\TestCase;
use test\winterframework\paxb\beans\PaxbTest01;
use test\winterframework\paxb\beans\PaxbTest02;
use test\winterframework\paxb\beans\PaxbTest03;

class XmlObjectMapperTests extends TestCase {

    public function testPaxb01(): void {

        $mapper = new XmlObjectMapper();

        /** @var PaxbTest01 $obj */
        $obj = $mapper->readValueFromFile(
            new BasicFile(__DIR__ . '/xml/PaxbTest01.xml'),
            PaxbTest01::class,
            true
        );
        //print_r($obj);
        $this->assertNotEmpty($obj);

        $this->assertSame($obj->getTo(), 'Tove');
        $this->assertSame($obj->getFrom(), 'Jani');
        $this->assertSame($obj->getHeading(), 'Reminder');
        $this->assertSame($obj->getBody(), 'Don\'t forget me this weekend!');
    }


    public function testPaxb02(): void {

        $mapper = new XmlObjectMapper();

        /** @var PaxbTest02 $obj */
        $obj = $mapper->readValueFromFile(
            new BasicFile(__DIR__ . '/xml/PaxbTest02.xml'),
            PaxbTest02::class,
            true
        );
        //var_dump($obj);
        $this->assertNotEmpty($obj);
        $arr = $obj->getFood();
        $this->assertSame(count($arr), 5);

        $this->assertSame($arr[2]->calories, 900);
        $this->assertSame($arr[3]->calories, 600);
        $this->assertSame($arr[3]->name, 'French Toast');
        $this->assertSame($arr[4]->name, 'Homestyle Breakfast');
    }

    public function testPaxb03(): void {

        $mapper = new XmlObjectMapper();

        /** @var PaxbTest03 $obj */
        $obj = $mapper->readValueFromFile(
            new BasicFile(__DIR__ . '/xml/PaxbTest03.xml'),
            PaxbTest03::class,
            false
        );
        //print_r($obj);
        $this->assertNotEmpty($obj);

        $p = $obj->getProducts();
        $this->assertSame(count($p), 1);
        $items = $p[0]->getCatalogItems();
        $this->assertSame(count($items), 2);
        $this->assertSame($items[0]->getGender(), "Men's");
        $this->assertSame($items[1]->getGender(), "Women's");
        $this->assertSame($items[0]->getPrice(), 39.95);
        $this->assertSame($items[1]->getPrice(), 42.50);
        $this->assertSame(count($items[1]->getSizes()), 4);

        $extras = $p[0]->getExtras();
        $this->assertSame(count($extras), 2);
        $this->assertInstanceOf(XmlNode::class, $extras[0]);
        $this->assertInstanceOf(XmlNode::class, $extras[1]);
    }

}