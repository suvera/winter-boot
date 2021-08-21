# Caching

Winter managed bean dependencies are just handled by Annotations (Attributes).

Here is Caching related attributes

## 1. EnableCaching

This is a class-level attribute.

When you annotate your Application class with #[EnableCaching] annotation, this scan the beans for the presence of
caching annotations on public methods. If such an annotation is found, a proxy is automatically created to intercept the
method call and handle the caching behavior accordingly.

#### Example:

```phpt

#[EnableCaching]
class MyApplication {
}

```

## 2. Cacheable

This is a method-level attribute. Attribute **#[Cacheable]** is used on the method level to let the framework know that
the response of the method are cacheable. Framework manages the request/response of this method to the cache specified
in annotation attribute.

#### Example:

```phpt

// default Cache container - inMemory is default
#[Cacheable]
public function foo(): mixed {
    return some_value;
}

// Single cache container
#[Cacheable("cache-name")]
public function getStockPrice(stirng $symbol): mixed {
    return some_value;
}

# Multiple Cache containers
#[Cacheable(cacheNames: ["cache-name2", "cache-name3"]))
public function foo(): mixed {
    return some_value;
}


```

Attribute **#[Cacheable]** has more options.

Name | Required | Default Value | Description
------------ | ------------ | ------------ | ------------
cacheNames | Yes | "default" | Cache names
key |  | default to Method Name | Key to cache this value
keyGenerator |  | default to framework Managed | Bean derived from KeyGenerator interface
cacheManager |  | default to framework Managed | Bean derived from CacheManager interface
cacheResolver |  | default to framework Managed | Bean derived from CacheResolver interface

### Cache Container

Cache containers are managed by *CacheManager* , so, CacheManager is mandatory to create cache containers.

by default, framework provides **default** cache manager which uses in-memory cache by default.

Framework also provides Local KV Caching mechanism.

#### SharedKvCache Implementation 

Caching mechanism implemented using local KV store. This is local to node only. All processes share same KV store.


```phpt

#[Configuration]
class CacheConfig {

    #[Bean]
    public function getCacheManager(KvTemplate $kvTemplate): CacheManager {
    
        $cache1 = new SharedKvCache(
            $kvTemplate,
            "stock-prices",                  // Cache Name
            CacheConfiguration::get(
                maximumSize: 5000,           // only 5000 entries allowed, once reached LRU evict happens
                expireAfterWriteMs: 600000,  // Milliseconds, expires item after 10 mins after written
            )
        );
        
        $manager = new SimpleCacheManager();
        $manager->addCache($cache1);
        return $manager;
    }

}
```

#### RedisCache Implementation

RedisCache is distributed, all nodes in your cluster may refer to same redis store.

- RedisCache is available in [RedisModule](https://github.com/suvera/winter-modules/tree/master/winter-data-redis)


```phpt
#[Configuration]
class CacheConfig {

    #[Bean("redisCacheManager")]
    public function getRedisCacheManager(PhpRedisTemplate $redisTpl): CacheManager {
        $cache1 = new RedisCache(
            $redisTpl,
            "stock-prices",                  // Cache Name
            CacheConfiguration::get(
                maximumSize: 5000,           // only 5000 entries allowed, once reached LRU evict happens
                expireAfterWriteMs: 600000,  // Milliseconds, expires item after 10 mins after written
            )
        );
        
        $cache2 = new RedisCache(
            $redisTpl,
            "company-names",            // Cache Name
            CacheConfiguration::get(
                maximumSize: -1,         // Unlimted entries
                expireAfterWriteMs: -1,  // Unlimited time
            )
        );
        
        $manager = new SimpleCacheManager();
        
        $manager->addCache($cache1);
        $manager->addCache($cache2);
        
        return $manager;
    }

}

// Above can be used like this
#[Cacheable(cacheNames: "stock-prices", cacheManager: "redisCacheManager")]
public function getStockPrice(stirng $symbol): mixed {
    return some_value;
}
```

## 3. CachePut

This is a method-level attribute.

This attribute is used on methods. Whenever you need to update the cache without interfering the method execution, you
can use the **#[CachePut]** annotation. That is, the method will always be executed, and the result is cached.

Using **#[CachePut]** and **#[Cacheable]** on the same method is strongly discouraged as both changes the flow of
execution.

It supports the same options as **#[Cacheable]** , see above table.

## 4. CacheEvict

This is a method-level attribute.

It is used to evict (remove) the cache items from the caching system. i.e. when **#[CacheEvict]** attributed methods
will be executed, it will clear the cache.

We can specify **key** parameter to remove single item from the Cache, If we need to remove all the entries of the cache
then we need to use **allEntries=true**.

It supports the same options as **#[Cacheable]** , see above table.

#### Additional Options

Name | Required | Default Value | Description
------------ | ------------ | ------------ | ------------
allEntries |  | false | Remove all items from caches mentioned
beforeInvocation |  | false | Whether to delete items before Method invocation 


#### Example:

```phpt

#[CacheEvict(allEntries: true, beforeInvocation: false, cacheNames: "stock-prices", cacheManager: "redisCacheManager")]
public function clearAll(): void {
}

```