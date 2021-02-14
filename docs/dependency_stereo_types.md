# Dependency Injection

Winter managed bean dependencies are just handled by Annotations (Attributes).

## 1. Autowired

Any class property annotated with **#[Autowired]** will be injected by the framework.

This is a class Property level attribute.

#### Example:

```phpt

#[Service]
class FooService {
    
    #[Autowired]
    private BarService $bar;
    
}


$obj = $appCtx->beanByClass(FooService::class);

var_dump($obj->bar);


```

## 2. Service

This is a class-level attribute. **#[Service]** indicates that an annotated class is a service class.

- Service classes can be distinguished with a optional name.
- Two classes cannot be annotated with same service Name.

#### Example:

```phpt

#[Service]
class Car implements Vehicle {
}

$car = $appCtx->beanByClass(Car::class);
var_dump($car);

-------------------------------------------------

#[Service("bus")]
class Bus implements Vehicle {
}

$bus = $appCtx->beanByName("bus");
var_dump($bus);


```



## 3. Component

Classes may be annotated with **#[Component]**, this works as same as #[Service]

This is a class-level attribute.

## 4. Bean

**#[Bean]** indicates that a method produces a bean to be managed by the framework.

This is a method-level attribute.


#### Example:

```phpt

#[Configuration]
class AppBeanConfig {

    #[Bean]
    public function getFoo(): Foo {
        $foo = new Foo();
        $foo->init(/*....*/);
        $foo->doSomething();
        return $foo;
    }
}

$foo = $appCtx->beanByClass(Foo::class);
var_dump($foo);

------------------------------------------------------------


#[Bean("bar")]
public function getBarSerivce(): BarService {
    $obj = new FineBarSerivce();
    return $obj;
}


#[Bean("restaurent")]
public function getRestaurentSerivce(): BarService {
    $obj = new RestBarSerivce();
    return $obj;
}

$bar = $appCtx->beanByName("bar");
var_dump($bar);


$restaurent = $appCtx->beanByName("restaurent");
var_dump($restaurent);



```


## 5. Configuration

This is a class-level attribute.
**#[Configuration]** indicates that a class is a configuration class that may contain bean definitions.

see above example.

More examples:

```phpt

#[Configuration]
class AppConfigProperties {

    #[Value('${myApp.db.host}')]
    private string $dbHost;
    
    #[Value('${myApp.db.user}')]
    private string $dbUser;

}

```


## 6. Value

all properties defined in **application.yml** can be accessed using **#[Value]** attribute.

see above example.


## 7. WinterBootApplication

Starter class must be defined with **#[WinterBootApplication]** attribute.

#### Example:

```phpt


#[WinterBootApplication(
	// List of config directories
	configDirectory: [],
	
	// array of records in format [NamespacePrefix, BaseDirectory]
	scanNamespaces: [],
	
	// autoload, if class not loaded 
	autoload: false,
	
	// List of Namespaces to exclude from scanning
	scanExcludeNamespaces: [],
	
	// Eagerly create Beans/Objects on start-up, slowdown the application
	eager = false
)]
class MyApplication {
}


```


# Caching

----

Caching related attributes

## 1. EnableCaching

This is a class-level attribute.

When you annotate your Application class with #[EnableCaching] annotation, this scan the beans for the presence of caching annotations on public methods. If such an annotation is found, a proxy is automatically created to intercept the method call and handle the caching behavior accordingly.

#### Example:

```phpt

#[EnableCaching]
class MyApplication {
}

```

## 2. Cacheable

This is a method-level attribute.
Attribute **#[Cacheable]** is used on the method level to let the framework know that the response of the method are cacheable. Framework manages the request/response of this method to the cache specified in annotation attribute. 

#### Example:

```phpt

#[Cacheable(cacheNames: ["cache-name1", "cache-name2"]))
public function foo(): mixed {
}

```

Attribute **#[Cacheable]** has more options.

Name | Required | Default Value | Description
------------ | ------------ | ------------ | ------------
cacheNames | Yes | "default" | Cache names
key |  | default to Method Name | Key to cache this value
keyGenerator |  | default to framework Managed | Bean derived from KeyGenerator interface
cacheManager |  | default to framework Managed | Bean derived from CacheManager interface
cacheResolver |  | default to framework Managed | Bean derived from KeyResolver interface

## 3. CachePut

This is a method-level attribute.

This attribute is used on methods. Whenever you need to update the cache without interfering the method execution, you can use the **#[CachePut]** annotation. That is, the method will always be executed, and the result is cached.

Using **#[CachePut]** and **#[Cacheable]** on the same method is strongly discouraged as both changes the flow of execution.

It supports the same options as **#[Cacheable]** , see above table.


## 4. CacheEvict

This is a method-level attribute.

It is used to evict (remove) the cache items from the caching system. i.e. when **#[CacheEvict]** attributed methods will be executed, it will clear the cache.

We can specify **key** parameter to remove single item from the Cache, If we need to remove all the entries of the cache then we need to use **allEntries=true**.

It supports the same options as **#[Cacheable]** , see above table.

#### Additional Options

Name | Required | Default Value | Description
------------ | ------------ | ------------ | ------------
allEntries |  | false | Remove all items from caches mentioned
beforeInvocation |  | false | Whether to delete items before Method invocation 



# REST API

----

REST API Development related attributes

## 1. RestController

This attribute is used at the class level. The **#[RestController]** annotation marks the class as a controller that handles REST requests.

## 2. RequestMapping

This attribute is used both at class and method level. The **#[RequestMapping]** attribute is used to map web requests onto specific handler classes and handler methods.

When **#[RequestMapping]** is used on class level it creates a base URI for all methods defined in that class. When this attribute is used on methods it will give you the URI on which the handler methods will be executed.

#### Options:

Name | Required | Default Value | Description
------------ | ------------ | ------------ | ------------
path | Yes | | URI value of the request 
method |  | All Methods | List of HTTP methods
name |  | | Name of the request mapping
consumes |  | | List of Media Types

#### Example:

```phpt
#[RequestMapping(path: "/multiply", method: [RequestMethod::POST])]


#[RequestMapping(path: "/multiply", method: [RequestMethod::POST, RequestMethod::GET])]


#[RequestMapping(path: "/Users/{id}", method: [RequestMethod::GET])]


```



## 3. RequestParam

Attribute **#[RequestParam]** is used to bind request parameters to a method parameter in your controller.
i.e. $_GET and $_POST values.

#### Example:

```phpt

#[RequestMapping(path: "/divide", method: [RequestMethod::POST])]
public function divide(
    #[RequestParam] int $a,
    #[RequestParam] int $b,
): float {

}

```


## 4. RequestBody

The **#[RequestBody]** attribute indicates that a method parameter should be bound to the value of the whole HTTP request body.

Using this you can map whole JSON body to a Class.

#### Example:

```phpt

#[RequestMapping(path: "/cacle/add", method: [RequestMethod::POST])]
public function sum(
    #[RequestBody] AddRequest $request
): int {
    return $this->service->add($request->a, $request->b);
}

-----

class AddRequest {
   private int $a;
   private int $b;
}

```


## 5. PathVariable

Attribute **#[PathVariable]** indicates that a method parameter should be bound to a URI template variable.

#### Example:

```phpt

#[RequestMapping(path: "/hello/{name}", method: [RequestMethod::GET])]
public function sayHello2(
    #[PathVariable] string $name
): string {
    return 'Hello, ' . $name;
}

```


# Misc

----

## 1. Lockable

This is a Aspect Oriented Type of Attribute.
This is a method-level attribute.

When you want to execute a method by locking it exclusively, no other instance can execute this method until lock released.

There are two types of Exclusive Locks

- Local Node Lock (no two processes in same node can execute this method at same time)
- Distributed Lock (no two processes in whole cluster can execute this method at same time) - This can be done using RedisLock, Database Locks, ZooKeeper/Consul etc...


```phpt

#[Lockable(name: "order-#[id]", provider: "redisLock")]
public function updateOrderStatus(int $id): void {
}

```

## 2. StereoTyped

This Attribute gives ability to extend the framwork to some other level.

- You can define your own AOP type of Attributes
