<?php
declare(strict_types=1);

namespace dev\winterframework\io\file;

use dev\winterframework\type\TypeAssert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveRegexIterator;
use RegexIterator;

class DirectoryScanner {

    public static function scanFileInDirectories(
        array $dirs, string $filePath
    ): array {
        $files = [];
        foreach ($dirs as $dir) {
            TypeAssert::string($dir);
            $dir = rtrim($dir, '/');

            $file = $dir . '/' . $filePath;
            if (is_file($file)) {
                $files[$file] = $file;
            }
        }
        return $files;
    }

    public static function scanForPhpClasses(
        string $baseDir,
        string $namespace,
        array $excludeNamespaces = []
    ): array {

        $namespace = trim($namespace, '\\');

        $files = [];
        $dir = new RecursiveDirectoryIterator($baseDir);
        $itr = new RecursiveIteratorIterator($dir);
        $regex = new RegexIterator($itr, '/^.+\.php$/', RecursiveRegexIterator::GET_MATCH);
        foreach ($regex as $f) {
            $file = $f[0];
            $className = str_replace($baseDir, '', $file);
            $className = trim($className, '/\\');
            $className = substr($className, 0, -4);
            $className = str_replace('/', '\\', $className);

            $fileName = basename($className);
            if ($fileName === 'index' || $fileName === 'home' || $fileName === 'router') {
                continue;
            }

            $fqns = $namespace . '\\' . $className;
            foreach ($excludeNamespaces as $excludeNs) {
                $excludeNs = trim($excludeNs, '\\');
                if (str_starts_with($fqns, $excludeNs)) {
                    continue 2;
                }
            }
            $files[$fqns] = $file;
        }

        return $files;
    }

}