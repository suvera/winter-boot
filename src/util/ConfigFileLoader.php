<?php
declare(strict_types=1);

namespace dev\winterframework\util;

use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\exception\FileNotFoundException;
use dev\winterframework\io\file\DirectoryScanner;
use dev\winterframework\stereotype\Module;
use dev\winterframework\util\log\Wlf4p;

class ConfigFileLoader {
    use Wlf4p;

    public static function parseConfStyled(string $filePath): array {
        $lines = file($filePath);
        $data = array();

        $matches = [];
        foreach ($lines as $l) {
            $matches['key'] = null;
            preg_match("/^(?P<key>\w+)\s+(?P<value>.*)/", $l, $matches);
            if (isset($matches['key'])) {
                $data[$matches['key']] = trim($matches['value']);
            }
        }
        return $data;
    }

    public static function retrieveConfigurationFile(
        ApplicationContextData $ctxData,
        string $confFile
    ): string {
        if (!$confFile) {
            throw new FileNotFoundException('No configuration file found ' . $confFile);
        }

        if ($confFile[0] == '/') {
            return $confFile;
        }

        $configFiles = DirectoryScanner::scanFileInDirectories($ctxData->getBootConfig()->configDirectory, $confFile);

        if (empty($configFiles)) {
            throw new FileNotFoundException('Could not find Config file' . $confFile);
        }

        return array_shift($configFiles);
    }

    public static function retrieveConfiguration(
        ApplicationContextData $ctxData,
        Module $module
    ): array {

        $moduleConfig = $module->getConfig();

        $confFile = $moduleConfig['configFile'] ?? null;

        if (!$confFile) {
            self::logError('Empty configuration found '
                . ' for module ' . $module->getClassName());
            throw new FileNotFoundException('Empty configuration found '
                . ' for module ' . $module->getClassName());
        }

        self::logInfo("Loading config from file '$confFile'" . ' for module ' . $module->getClassName());

        if ($confFile[0] != '/') {
            $configFiles = DirectoryScanner::scanFileInDirectories($ctxData->getBootConfig()->configDirectory, $confFile);
        } else {
            $configFiles = [$confFile];
        }

        if (empty($configFiles)) {
            self::logError('Could not find  config file ' . json_encode($confFile)
                . ' for module ' . $module->getClassName());
            throw new FileNotFoundException('Could not find Config file'
                . ' for module ' . $module->getClassName());
        }

        $data = [];
        foreach ($configFiles as $configFile) {
            $conf = PropertyLoader::loadProperties($configFile);
            $data = array_merge($data, $conf);
        }

        return $data;
    }
}