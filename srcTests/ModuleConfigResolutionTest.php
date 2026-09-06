<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterPropertyContext;
use dev\winterframework\stereotype\Module;
use dev\winterframework\stereotype\WinterBootApplication;
use dev\winterframework\util\ConfigFileLoader;
use winterBootTests\Support\TestCase;

final class ModuleConfigResolutionTest extends TestCase {

    private string $tmpDir = '';

    private function buildDir(string $moduleYaml): string {
        $tmp = sys_get_temp_dir() . '/wb-modcfg-' . uniqid();
        mkdir($tmp, 0777, true);
        file_put_contents($tmp . '/application.yml', <<<YML
propertySources:
    - name: env
      provider: dev\\winterframework\\io\\EnvPropertySource
YML);
        file_put_contents($tmp . '/redis-config.yml', $moduleYaml);
        $this->tmpDir = $tmp;
        return $tmp;
    }

    private function loadModuleConfig(string $tmp): array {
        $propCtx = new WinterPropertyContext([$tmp]);
        $ctxData = new ApplicationContextData();
        $ctxData->setPropertyContext($propCtx);
        $ctxData->setBootConfig(new WinterBootApplication(configDirectory: [$tmp]));

        $module = new Module(title: 'Redis');
        $rp = new \ReflectionProperty(Module::class, 'className');
        $rp->setAccessible(true);
        $rp->setValue($module, 'TestRedisModule');
        $module->setConfig(['configFile' => 'redis-config.yml']);

        return ConfigFileLoader::retrieveConfiguration($ctxData, $module);
    }

    public function __destruct() {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            array_map('unlink', glob($this->tmpDir . '/*'));
            rmdir($this->tmpDir);
        }
    }

    public function testFallbackWhenEnvMissing(): void {
        $tmp = $this->buildDir(<<<YML
phpredis:
    singles:
        - name: default
          host: "\$env.REDIS_HOST || localhost"
YML);
        unset($_ENV['REDIS_HOST']);
        putenv('REDIS_HOST');

        $data = $this->loadModuleConfig($tmp);
        $this->assertSame('localhost', $data['phpredis.singles'][0]['host']);
    }

    public function testEnvValueWins(): void {
        $tmp = $this->buildDir(<<<YML
phpredis:
    singles:
        - name: default
          host: "\$env.REDIS_HOST || localhost"
YML);
        $_ENV['REDIS_HOST'] = 'redis-prod';
        try {
            $data = $this->loadModuleConfig($tmp);
            $this->assertSame('redis-prod', $data['phpredis.singles'][0]['host']);
        } finally {
            unset($_ENV['REDIS_HOST']);
        }
    }
}
