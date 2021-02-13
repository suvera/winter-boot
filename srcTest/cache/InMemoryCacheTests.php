<?php
declare(strict_types=1);

namespace test\winterframework\cache;

use dev\winterframework\cache\CacheConfiguration;
use dev\winterframework\cache\impl\InMemoryCache;
use PHPUnit\Framework\TestCase;

class InMemoryCacheTests extends TestCase {

    public function testInMemoryCache01() {
        $cache = new InMemoryCache('test');
        $cache->put('key1', 1);
        $this->assertSame($cache->get('key1')->get(), 1);
        $this->assertSame($cache->get('key1')->get(), 1);
        $this->assertNull($cache->get('key2')->get());

        $cache->put('key2', 2);
        $this->assertSame($cache->get('key2')->get(), 2);

        $cache->put('key23', 33);
        $this->assertSame($cache->get('key23')->get(), 33);

        $cache->evict('key2');
        $this->assertNull($cache->get('key2')->get());

        $cache->clear();
        $this->assertNull($cache->get('key1')->get());
        $this->assertNull($cache->get('key23')->get());
    }

    public function testInMemoryCache02() {
        $cache = new InMemoryCache('test', new CacheConfiguration(3, 1000));
        $cache->put('key1', 11);
        $this->assertSame($cache->get('key1')->get(), 11);
        $this->assertSame($cache->get('key1')->get(), 11);
        $this->assertNull($cache->get('key2')->get());

        $cache->put('key2', 2);
        $this->assertSame($cache->get('key2')->get(), 2);

        $cache->put('key23', 33);
        $this->assertSame($cache->get('key23')->get(), 33);

        $cache->put('key24', 33);
        $this->assertSame($cache->get('key24')->get(), 33);
        $this->assertNull($cache->get('key1')->get());

        $cache->put('key25', 33);
        $this->assertSame($cache->get('key25')->get(), 33);
        $this->assertNull($cache->get('key2')->get());

        sleep(1);
        $this->assertNull($cache->get('key25')->get());
        $this->assertNull($cache->get('key24')->get());
        $this->assertNull($cache->get('key23')->get());

    }
}