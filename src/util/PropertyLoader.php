<?php
declare(strict_types=1);

namespace dev\winterframework\util;

use dev\winterframework\util\yaml\YamlParser;

final class PropertyLoader {

    public static function loadProperties(string $propertyFilePath): array {
        return YamlParser::parseFile($propertyFilePath, true);
    }

    public static function loadLogging(string $logFilePath): array {
        return YamlParser::parseFile($logFilePath, false);
    }
}