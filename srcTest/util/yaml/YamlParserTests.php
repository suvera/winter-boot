<?php
declare(strict_types=1);

namespace test\winterframework\util\yaml;

use dev\winterframework\util\yaml\YamlParser;
use PHPUnit\Framework\TestCase;

class YamlParserTests extends TestCase {

    public function testYaml01() {
        $props = YamlParser::parseFile(dirname(dirname(__DIR__)) . '/config/application.yml', true);

        var_dump($props);
    }
}