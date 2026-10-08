<?php

declare(strict_types=1);

namespace winterBootTests\swoole;

use Swoole\Coroutine;
use winterBootTests\ReviewFix210Test;
use function Swoole\Coroutine\run;

/** Bean factory that yields mid-build, like a DataSource opening a hooked connection. */
final class YieldingBeanFactory {
    public int $calls = 0;

    public function build(): \ArrayObject {
        $this->calls++;
        Coroutine::sleep(0.01);
        return new \ArrayObject();
    }
}

class BeanConcurrencyTest {

    // Two requests needing a bean that is still being built: the second
    // waits for the first build instead of failing with a false cycle.
    public function testConcurrentFirstLookupWaitsForBuild(): void {
        $provider = ReviewFix210Test::beanProvider();
        $factory = new YieldingBeanFactory();
        $provider->registerInternalBeanMethod('slow', '', $factory, 'build');
        $beans = [];
        $errors = [];
        run(function () use ($provider, &$beans, &$errors): void {
            for ($i = 0; $i < 2; $i++) {
                Coroutine::create(function () use ($provider, &$beans, &$errors): void {
                    try {
                        $beans[] = $provider->beanByName('slow');
                    } catch (\Throwable $e) {
                        $errors[] = get_class($e) . ': ' . $e->getMessage();
                    }
                });
            }
        });
        if (!empty($errors)) {
            throw new \Exception('concurrent lookup failed: ' . substr($errors[0], 0, 160));
        }
        if (count($beans) !== 2 || $beans[0] !== $beans[1]) {
            throw new \Exception('expected both coroutines to get the same bean');
        }
        if ($factory->calls !== 1) {
            throw new \Exception('expected the bean to be built once, got ' . $factory->calls);
        }
    }
}
