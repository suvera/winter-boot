# Supercharge Your Winter Boot Application with Caching!

Caching is a powerful technique to significantly boost your application's performance by storing frequently accessed data in memory, reducing the need to recompute or re-fetch it. Winter Boot makes integrating caching into your PHP applications incredibly easy and intuitive, leveraging simple annotations (attributes).

Let's dive into the exciting world of Winter Boot caching attributes!

## 1. EnableCaching: Unleash the Caching Power!

This is a class-level attribute that acts as your caching gateway.

By simply annotating your main Application class with `#[EnableCaching]`, you tell Winter Boot to scan your beans for caching annotations on public methods. When found, Winter Boot intelligently creates a proxy to intercept these method calls, seamlessly handling all the caching magic for you!

#### Example:

```phpt
#[EnableCaching]
class MyApplication {
    // Your amazing application logic goes here!
}
```

## 2. Cacheable: Make Your Method Results Fly!

This method-level attribute is your go-to for making method responses cacheable. When you mark a method with `#[Cacheable]`, Winter Boot takes care of storing and retrieving its results from the specified cache, dramatically speeding up subsequent calls.

#### Example:

```phpt
// Default Cache Container (in-memory is the default)
#[Cacheable]
public function getExpensiveCalculationResult(): mixed {
    // Imagine a complex, time-consuming calculation here!
    return "some_calculated_value";
}

// Using a Single Named Cache Container
#[Cacheable("product-prices")]
public function getProductPrice(string $productId): mixed {
    // Fetches product price from a database or external service
    return "19.99";
}

// Leveraging Multiple Cache Containers
#[Cacheable(cacheNames: ["user-sessions", "api-tokens"])]
public function getUserSessionData(string $userId): mixed {
    // Retrieves user session and API token data
    return ["session_id" => "abc", "token" => "xyz"];
}
```

The `#[Cacheable]` attribute comes with powerful options to fine-tune your caching strategy:

| Name         | Required | Default Value          | Description                                                              |
|--------------|----------|------------------------|--------------------------------------------------------------------------|
| `cacheNames` | Yes      | `"default"`            | The names of the caches where the method's result will be stored.        |
| `key`        | No       | Method Name            | The key under which the value will be cached. Defaults to the method name and its arguments. |
| `keyGenerator`| No       | Framework Managed      | A bean derived from `KeyGenerator` interface for custom key generation.  |
| `cacheManager`| No       | Framework Managed      | A bean derived from `CacheManager` interface to manage cache containers. |
| `cacheResolver`| No       | Framework Managed      | A bean derived from `CacheResolver` interface for dynamic cache resolution. |

### Cache Containers: Your Data's Home!

Cache containers are the actual storage units for your cached data, and they are expertly managed by a `CacheManager`. A `CacheManager` is essential for creating and overseeing these containers.

By default, Winter Boot provides a **default** `CacheManager` that utilizes an efficient in-memory cache. But that's not all! Winter Boot also offers robust mechanisms for local and distributed caching.

#### SharedKvCache Implementation: Local Node Power!

The `SharedKvCache` provides a high-performance caching mechanism built upon a local Key-Value store. This cache is local to your node, meaning all processes on that node share the same KV store, making it incredibly efficient for single-node deployments or for data that doesn't need to be distributed across a cluster.

```phpt
#[Configuration]
class CacheConfig {

    #[Bean]
    public function getCacheManager(KvTemplate $kvTemplate): CacheManager {

        $cache1 = new SharedKvCache(
            $kvTemplate,
            "stock-prices",                  // Cache Name: A unique identifier for this cache
            CacheConfiguration::get(
                maximumSize: 5000,           // Max 5000 entries. Once reached, LRU (Least Recently Used) eviction kicks in!
                expireAfterWriteMs: 600000,  // Items expire 10 minutes (600,000 ms) after being written
            )
        );

        $manager = new SimpleCacheManager();
        $manager->addCache($cache1);
        return $manager;
    }

}
```

#### RedisCache Implementation: Scale with Distributed Caching!

For applications requiring distributed caching across multiple nodes in a cluster, `RedisCache` is your ultimate solution! All nodes can seamlessly refer to the same Redis store, ensuring data consistency and high availability.

- **RedisCache is available in the [RedisModule](https://github.com/suvera/winter-modules/tree/master/winter-data-redis)**. Make sure to include it in your project!

```phpt
#[Configuration]
class CacheConfig {

    #[Bean("redisCacheManager")] // Give your Redis CacheManager a unique name!
    public function getRedisCacheManager(PhpRedisTemplate $redisTpl): CacheManager {
        $cache1 = new RedisCache(
            $redisTpl,
            "stock-prices",                  // Cache Name
            CacheConfiguration::get(
                maximumSize: 5000,           // Max 5000 entries, then LRU eviction
                expireAfterWriteMs: 600000,  // Items expire after 10 minutes
            )
        );

        $cache2 = new RedisCache(
            $redisTpl,
            "company-names",            // Cache Name
            CacheConfiguration::get(
                maximumSize: -1,         // Unlimited entries!
                expireAfterWriteMs: -1,  // Never expires!
            )
        );

        $manager = new SimpleCacheManager();

        $manager->addCache($cache1);
        $manager->addCache($cache2);

        return $manager;
    }

}

// How to use your custom Redis CacheManager:
#[Cacheable(cacheNames: "stock-prices", cacheManager: "redisCacheManager")]
public function getStockPrice(string $symbol): mixed {
    return "some_stock_price";
}
```

## 3. CachePut: Always Update, Always Cache!

This powerful method-level attribute is perfect when you need to update the cache *without* skipping the method execution. With `#[CachePut]`, your method will *always* run, and its fresh result will *always* be cached. This ensures your cache is always up-to-date with the latest data!

**Important:** Using `#[CachePut]` and `#[Cacheable]` on the same method is generally discouraged as they both influence the execution flow in potentially conflicting ways. Choose the one that best fits your update strategy!

`#[CachePut]` supports the same flexible options as `#[Cacheable]`. Refer to the table above for details!

## 4. CacheEvict: Keep Your Cache Clean and Fresh!

This method-level attribute is your broom for sweeping out stale or unwanted items from your caching system. When a method annotated with `#[CacheEvict]` is executed, it will clear the specified cache entries.

You can target specific items by using the `key` parameter, or you can perform a full sweep by setting `allEntries=true` to remove *all* entries from the designated cache(s).

`#[CacheEvict]` supports the same core options as `#[Cacheable]`. See the table above for details.

#### Additional Powerful Options for CacheEvict:

| Name             | Required | Default Value | Description                                                              |
|------------------|----------|---------------|--------------------------------------------------------------------------|
| `allEntries`     | No       | `false`       | Set to `true` to remove all items from the specified caches.             |
| `beforeInvocation`| No       | `false`       | If `true`, cache items are deleted *before* the method is invoked. Otherwise, deletion happens *after* invocation. |

#### Example:

```phpt
#[CacheEvict(allEntries: true, beforeInvocation: false, cacheNames: "stock-prices", cacheManager: "redisCacheManager")]
public function clearAllStockPricesCache(): void {
    // This method will clear all entries from the "stock-prices" cache in the "redisCacheManager"
    // after its execution.
}
```

Winter Boot's caching mechanism provides a robust and flexible way to optimize your application's performance. Start leveraging these powerful attributes today and watch your application fly!