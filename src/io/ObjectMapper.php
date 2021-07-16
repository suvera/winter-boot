<?php
declare(strict_types=1);

namespace dev\winterframework\io;

use dev\winterframework\io\file\File;
use dev\winterframework\io\file\FileStream;

interface ObjectMapper {

    const SOURCE_XML = 1;
    const SOURCE_JSON = 2;
    const SOURCE_ARRAY = 3;

    public function readValue(string $xml, string $class, bool $validate = false): object;

    public function readValueFromFile(FileStream|File $file, string $class, bool $validate = false): object;

    public function writeValueToFile(object $object, string $filePath): void;

    public function writeValue(object $object): string;
}