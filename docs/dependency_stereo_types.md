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

see (Application Starter)[application_starter.md] for more details
