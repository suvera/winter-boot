<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\context\WinterPropertyContext;
use dev\winterframework\exception\PropertyException;
use dev\winterframework\migrations\OpenSearchMigrationService;
use winterBootTests\Support\TestCase;

/**
 * The OpenSearch migrator reads module config files (opensearch-config.yml)
 * itself. They must get the same `$env.X` / `$ini.key` / `a || b`
 * resolution ConfigFileLoader gives modules at app boot; otherwise the
 * migrator sends literal "$ini.password" credentials and treats the
 * string "$env.VERIFY || false" as a truthy ssl_verification.
 */
final class OpenSearchMigrationConfigTest extends TestCase {

    private function fixture(string $osYaml, string $ini): string {
        $dir = sys_get_temp_dir() . '/osmig-' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/secrets.ini', $ini);
        file_put_contents($dir . '/application.yml', <<<YML
propertySources:
  - name: env
    provider: dev\\winterframework\\io\\EnvPropertySource
  - name: ini
    provider: dev\\winterframework\\io\\IniPropertySource
    filePath: {$dir}/secrets.ini
winter:
  application:
    name: osmigtest
YML);
        file_put_contents($dir . '/opensearch-config.yml', $osYaml);
        return $dir;
    }

    private function scan(string $dir, bool $withPropertyCtx): array {
        $service = (new \ReflectionClass(OpenSearchMigrationService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($service, 'configDir'))->setValue($service, $dir);
        (new \ReflectionProperty($service, 'propertyCtx'))->setValue(
            $service, $withPropertyCtx ? new WinterPropertyContext([$dir]) : null);
        $method = new \ReflectionMethod($service, 'scanConfigDirForOpenSearchConfigs');
        return $method->invoke($service);
    }

    private const OS_YAML = <<<'YML'
opensearch:
  - name: opensearch
    hosts:
      - "$env.WB_OSMIG_TEST_HOST || https://os.example"
    username: "$ini.osUser"
    password: "$ini.osPassword"
    ssl_verification: "$env.WB_OSMIG_TEST_VERIFY || false"
    migrations:
      enabled: true
YML;

    public function testConfigFileValuesResolveLikeModuleConfig(): void {
        unset($_ENV['WB_OSMIG_TEST_HOST'], $_ENV['WB_OSMIG_TEST_VERIFY']);
        $dir = $this->fixture(self::OS_YAML, "osUser=admin\nosPassword=Strong!Pass=1\n");

        $found = $this->scan($dir, true);

        $this->assertSame(1, count($found));
        $this->assertSame('admin', $found[0]['username']);
        $this->assertSame('Strong!Pass=1', $found[0]['password']);
        $this->assertSame(false, $found[0]['ssl_verification']);
        $this->assertSame(['https://os.example'], $found[0]['hosts']);
    }

    public function testEnvironmentWinsOverDefault(): void {
        // EnvPropertySource reads $_ENV.
        $_ENV['WB_OSMIG_TEST_HOST'] = 'https://os.cluster:9200';
        try {
            $dir = $this->fixture(self::OS_YAML, "osUser=admin\nosPassword=x\n");
            $this->assertSame(['https://os.cluster:9200'], $this->scan($dir, true)[0]['hosts']);
        } finally {
            unset($_ENV['WB_OSMIG_TEST_HOST']);
        }
    }

    public function testMissingSecretFailsInsteadOfDroppingTheConnection(): void {
        $dir = $this->fixture(self::OS_YAML, "osUser=admin\n");
        $this->assertThrows(PropertyException::class, fn() => $this->scan($dir, true));
    }

    public function testWithoutPropertyContextValuesStayRaw(): void {
        $dir = $this->fixture(self::OS_YAML, "osUser=admin\nosPassword=x\n");
        $this->assertSame('$ini.osUser', $this->scan($dir, false)[0]['username']);
    }
}
