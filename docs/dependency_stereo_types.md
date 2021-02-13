# Dependency Injection

Winter managed bean dependencies are just handled by Annotations (Attributes).

## 1. Autowired

Any class property annotated with **#[Autowired]** will be injected by framework.

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

Any class annotated with **#[Service]** is managed by the framework.

- Service classes can be distinguished with a optional name.
- Two classes cannot be annotated with not have same service Name.

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



## 4. Bean

Any class that is not annotated with either Service or Component , and want them managed by the framework then **#[Bean]** attribute helps there.

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

Any additional Beans and Properties handling classes must be declared with **#[Configuration]** attribute.

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
## 2. Cacheable
## 3. CacheEvict
## 4. CachePut



# REST API

----

REST API Development related attributes

## 1. RestController
## 2. RequestMapping
## 3. RequestParam
## 4. RequestBody
## 5. PathVariable
## 6. JsonProperty


# Misc

----

## 1. Lockable

## 2. StereoTyped