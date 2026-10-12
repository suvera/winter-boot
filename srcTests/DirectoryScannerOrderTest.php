<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\io\file\DirectoryScanner;
use winterBootTests\Support\TestCase;

class DirectoryScannerOrderTest extends TestCase {

    private const LAYOUT = [
        '1.10.0/000-b',
        '1.9.0/000-a',
        '1.0.0/010-c',
        '1.0.0/002-b',
        '1.0.0/000-baseline',
        '2.0.0/000-d',
    ];

    private const EXPECTED = [
        '1.0.0/000-baseline',
        '1.0.0/002-b',
        '1.0.0/010-c',
        '1.9.0/000-a',
        '1.10.0/000-b',
        '2.0.0/000-d',
    ];

    public function testSqlFilesRunInVersionOrder(): void {
        $this->assertScanOrder('sql', fn(string $dir) => DirectoryScanner::scanForSqlFiles($dir));
    }

    public function testJsonFilesRunInVersionOrder(): void {
        $this->assertScanOrder('json', fn(string $dir) => DirectoryScanner::scanForJsonFiles($dir));
    }

    private function assertScanOrder(string $ext, callable $scan): void {
        $dir = sys_get_temp_dir() . '/wb-scan-order-' . uniqid();
        try {
            foreach (self::LAYOUT as $name) {
                $file = "$dir/$name.$ext";
                @mkdir(dirname($file), 0777, true);
                file_put_contents($file, '');
            }
            $this->assertSame(
                array_map(fn(string $n) => "$n.$ext", self::EXPECTED),
                array_column($scan($dir), 'relative')
            );
        } finally {
            foreach (self::LAYOUT as $name) {
                @unlink("$dir/$name.$ext");
                @rmdir(dirname("$dir/$name.$ext"));
            }
            @rmdir($dir);
        }
    }
}
