<?php
declare(strict_types=1);

namespace dev\winterframework\util\yaml;

use dev\winterframework\exception\FileNotFoundException;
use dev\winterframework\type\Arrays;
use Symfony\Component\Yaml\Parser;

final class YamlParser {

    public static function parseFile(string $filePath, bool $flatten = false): array {
        $yaml = new Parser();

        if (!file_exists($filePath)) {
            throw new FileNotFoundException("Could not find property file " . json_encode($filePath));
        }

        if (!is_readable($filePath)) {
            throw new FileNotFoundException("Could not READ property file " . json_encode($filePath));
        }

        $data = $yaml->parse(file_get_contents($filePath));
        if (!$flatten) {
            return $data;
        }

        return Arrays::flattenByKey($data);
    }
}