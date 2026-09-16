<?php

declare(strict_types=1);

namespace winterBootTests;

use dev\winterframework\core\app\WinterMigrationApplication;
use dev\winterframework\core\app\WinterApplicationRunner;
use dev\winterframework\core\context\ApplicationContextData;
use dev\winterframework\core\context\WinterApplicationContext;
use dev\winterframework\core\context\WinterApplicationContextBuilder;
use dev\winterframework\core\context\WinterBeanProviderContext;
use dev\winterframework\core\context\WinterPropertyContext;
use dev\winterframework\exception\IllegalStateException;
use dev\winterframework\exception\ModuleException;
use dev\winterframework\pdbc\pdo\PdoTemplateProvider;
use dev\winterframework\reflection\ClassResource;
use dev\winterframework\reflection\ClassResources;
use dev\winterframework\reflection\MethodResources;
use dev\winterframework\reflection\VariableResources;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\type\AttributeList;
use winterBootTests\Support\TestCase;

final class ArcFixRegressionTest extends TestCase {

    private string $tmpDir = '';

    private function makePropCtx(): WinterPropertyContext {
        $this->tmpDir = sys_get_temp_dir() . '/arc-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/application.yml', "winter:\n  application:\n    name: arctest\n");
        return new WinterPropertyContext([$this->tmpDir]);
    }

    protected function tearDownFiles(): void {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') as $file) {
                unlink($file);
            }
            rmdir($this->tmpDir);
            $this->tmpDir = '';
        }
    }

    private function builderProp(string $name): \ReflectionProperty {
        $prop = new \ReflectionProperty(WinterApplicationContextBuilder::class, $name);
        $prop->setAccessible(true);
        return $prop;
    }

    // ARC-001: completed builds short-circuit; re-entry during a build fails fast.
    public function testBuildContextGuards(): void {
        try {
            $ctx = (new \ReflectionClass(WinterApplicationContext::class))->newInstanceWithoutConstructor();
            $built = $this->builderProp('built');
            $building = $this->builderProp('building');
            $method = new \ReflectionMethod(WinterApplicationContextBuilder::class, 'buildContext');

            $built->setValue($ctx, true);
            $method->invoke($ctx);

            $built->setValue($ctx, false);
            $building->setValue($ctx, true);
            $this->assertThrows(IllegalStateException::class, function () use ($ctx, $method) {
                $method->invoke($ctx);
            });
        } finally {
            $this->tearDownFiles();
        }
    }

    // ARC-002: every datasource gets its own named txn/template providers.
    public function testNamedProvidersForEveryDatasource(): void {
        try {
            $propCtx = $this->makePropCtx();
            $propCtx->set('datasource', [
                ['name' => 'alpha', 'url' => 'sqlite::memory:', 'isPrimary' => true],
                ['name' => 'beta', 'url' => 'sqlite::memory:'],
            ]);

            $ctxData = new ApplicationContextData();
            $ctx = (new \ReflectionClass(WinterApplicationContext::class))->newInstanceWithoutConstructor();
            $beanProvider = new WinterBeanProviderContext($ctxData, $ctx);

            // ofArray: mutable container; emptyList: immutable stub parts.
            $resources = ClassResources::ofArray([]);
            $stub = new ClassResource();
            $stub->setClass(RefKlass::getInstance(PdoTemplateProvider::class));
            $stub->setMethods(MethodResources::emptyList());
            $stub->setVariables(VariableResources::emptyList());
            $resources->offsetSet(PdoTemplateProvider::class, $stub);

            $this->builderProp('propertyContext')->setValue($ctx, $propCtx);
            $this->builderProp('beanProvider')->setValue($ctx, $beanProvider);
            $this->builderProp('resources')->setValue($ctx, $resources);
            $this->builderProp('contextData')->setValue($ctx, $ctxData);

            $method = new \ReflectionMethod(WinterApplicationContextBuilder::class, 'registerDataSources');
            $method->setAccessible(true);
            $method->invoke($ctx);

            $this->assertTrue($beanProvider->hasBeanByName('alpha-txn'));
            $this->assertTrue($beanProvider->hasBeanByName('alpha-template'));
            $this->assertTrue($beanProvider->hasBeanByName('beta-txn'));
            $this->assertTrue($beanProvider->hasBeanByName('beta-template'));
        } finally {
            $this->tearDownFiles();
        }
    }

    private function validatedDefs(array $modules): array {
        $propCtx = $this->makePropCtx();
        $propCtx->set('modules', $modules);
        $runner = (new \ReflectionClass(WinterMigrationApplication::class))->newInstanceWithoutConstructor();
        $prop = new \ReflectionProperty(WinterApplicationRunner::class, 'propertyCtx');
        $prop->setAccessible(true);
        $prop->setValue($runner, $propCtx);
        $method = new \ReflectionMethod(WinterApplicationRunner::class, 'validatedModuleDefs');
        $method->setAccessible(true);
        try {
            return $method->invoke($runner);
        } finally {
            $this->tearDownFiles();
        }
    }

    // ARC-009: malformed module entries fail fast with config location.
    public function testModuleDefValidation(): void {
        $defs = $this->validatedDefs([
            ['module' => \stdClass::class, 'enabled' => true],
            ['module' => 'Missing\\Bogus', 'enabled' => false],
        ]);
        $this->assertSame(2, count($defs));

        $this->assertThrows(ModuleException::class, function () {
            $this->validatedDefs(['oops']);
        });
        $this->assertThrows(ModuleException::class, function () {
            $this->validatedDefs([['enabled' => true]]);
        });
        $this->assertThrows(ModuleException::class, function () {
            $this->validatedDefs([['module' => 'Missing\\Bogus', 'enabled' => true]]);
        });
    }

    // ArrayList::emptyList() must not leak one subtype into another.
    public function testEmptyListsArePerType(): void {
        $this->assertTrue(ClassResources::emptyList() instanceof ClassResources);
        $this->assertTrue(MethodResources::emptyList() instanceof MethodResources);
        $this->assertTrue(AttributeList::emptyList() instanceof AttributeList);
        $this->assertTrue(ClassResources::emptyList() instanceof ClassResources);
    }

    // ARC-010: relative banner resolves against the config directory.
    public function testBannerRelativeToConfigDir(): void {
        try {
            $this->tmpDir = sys_get_temp_dir() . '/arc-' . uniqid();
            mkdir($this->tmpDir, 0777, true);
            file_put_contents($this->tmpDir . '/application.yml', "banner.location: banner.txt\n");
            file_put_contents($this->tmpDir . '/banner.txt', 'hi');
            $ctx = new WinterPropertyContext([$this->tmpDir]);
            $this->assertSame($this->tmpDir . '/banner.txt', $ctx->get('banner.location'));
        } finally {
            $this->tearDownFiles();
        }
    }
}
