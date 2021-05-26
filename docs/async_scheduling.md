# Async & Scheduling Support

----

1. Asynchronous execution support by **#[Async]** attribute.
2. Scheduling tasks just by using **#[Scheduled]** attribute


## Pre-requisites

**swoole** extension is required

```shell
pecl install swoole
```

Add **extension=swoole.so** to your **php.ini**


## Your Application Starter

```phpt

#[WinterBootApplication(...)]
#[EnableAsync]
#[EnableScheduling]
class MyApplication {

    public static function main(): void {
        (new WinterWebSwooleApplication())->run(self::class);
    }

}

```


## 1. Async Support

`#[EnableAsync] is required on your main application`

Annotating a method of a *service/component* bean with **#[Async]** will make it execute in a separate worker process. In other words, the caller will not wait for the completion of the called method.


```phpt

#[Async]
public function someAsyncMethodName(): void {
    echo "method Executed asynchronously by " . getmypid();
}

```

## 2. Scheduled Support

`#[EnableScheduling] is required on your main application`

Annotating a method of a *service/component* bean with **#[Scheduled]** will make it scheduled and executed on interval(s) by a separate worker process.


```phpt

#[Scheduled(fixedDelay: 20, initialDelay: 10)]
public function someScheduledMethodName(): void {
    echo 'I did generated a unique Id on every 20 seconds ' . uniqid();
}

```


## Configuration

in your **application.yml**

```yaml

winter:
    task:
        async:
            poolSize: 1
            queueCapacity: 50
            argsSize: 2048
        scheduling:
            poolSize: 1
            queueCapacity: 50
```

**poolSize** Total number of backend workers needed
**argsSize** Maximum allowed size of total arguments that are passed to a Async method (in bytes)
**queueCapacity** how many concurrent async/schedule requests can be queued.


## Example

Check out the example application [MyApp](../examples/README.md)

