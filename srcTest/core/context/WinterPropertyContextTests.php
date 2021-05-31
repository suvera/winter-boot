<?php
declare(strict_types=1);

namespace test\winterframework\core\context;

use dev\winterframework\core\context\WinterPropertyContext;
use dev\winterframework\util\PropertyLoader;
use PHPUnit\Framework\TestCase;

/**
 * Run:
 *
 *    ./bin/phpunit srcTest/core/context/WinterPropertyContextTests.php
 */
class WinterPropertyContextTests extends TestCase {

    public function testPropertyLoad01(): void {
        $prop = new WinterPropertyContext([__DIR__ . '/files']);

        $this->assertSame($prop->get('tesla.key.krazy'), "Thgh%#)()*\$l;',./po");
        //var_dump($prop->getAll());

        print_r(PropertyLoader::loadProperties(__DIR__ . '/files/test.yml'));
    }
}