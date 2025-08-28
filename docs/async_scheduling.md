# Unleash Concurrency: Async & Scheduling Support in Winter Boot!

Supercharge your Winter Boot applications with powerful asynchronous execution and intelligent task scheduling!

1.  **Asynchronous Execution:** Effortlessly run methods in the background using the magical `#[Async]` attribute, ensuring your main application thread remains responsive.
2.  **Smart Scheduling:** Automate repetitive tasks with precision using the intuitive `#[Scheduled]` attribute, making cron jobs a thing of the past!


## Essential Pre-requisites: Get Ready for Speed!

To unlock the full potential of Async and Scheduling, the blazing-fast **`swoole`** extension is a must-have!

```shell
pecl install swoole
```

Don't forget to enable it! Add `extension=swoole.so` to your `php.ini` file.


## Empower Your Application Starter

To activate these incredible features, simply add `#[EnableAsync]` and `#[EnableScheduling]` to your main application starter class.

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


## 1. Asynchronous Magic with `#[Async]`

**`#[EnableAsync]` is crucial for your main application to enable this feature!**

Transform any method of a *service* or *component* bean into an asynchronous powerhouse by annotating it with `#[Async]`. This means the calling code will *not* wait for the method's completion, allowing your application to perform non-blocking operations and dramatically improve responsiveness!

```phpt
#[Service]
class FooService {
    
    #[Async]
    public function someAsyncMethodName(): void {
        echo "Method executed asynchronously by " . getmypid();
    }
}
```

### Fine-Tune Your Async Operations: Configuration

Customize the behavior of your asynchronous tasks in your `application.yml` under the `winter.task.async` section:

```yaml

winter:
    task:
        async:
            poolSize: 1
            queueCapacity: 50
            argsSize: 2048
```
-   **`poolSize`**: Define the total number of backend worker processes dedicated to executing your async tasks.
-   **`argsSize`**: Set the maximum allowed size (in bytes) for all arguments passed to an asynchronous method.
-   **`queueCapacity`**: Control how many concurrent asynchronous requests can be queued and processed.


By default, Winter Boot utilizes shared memory for its internal async queue. For enhanced persistence and the ability to execute pending async calls even after an application restart, consider integrating the Redis module as your async queue storage!

Discover more about this powerful integration in the [Redis module documentation](https://github.com/suvera/winter-modules/tree/master/winter-data-redis).

```yaml
winter:
    task:
        async:
            # ... other async configurations ...
            queueStorage:
                handler: dev\winterframework\data\redis\async\AsyncRedisQueueStore

```


## 2. Precision Scheduling with `#[Scheduled]`

**Remember: `#[EnableScheduling]` is essential on your main application to activate scheduling!**

Automate your recurring tasks with incredible precision! By annotating a method of a *service* or *component* bean with `#[Scheduled]`, you can define intervals for its execution, offloading the burden of manual triggering. These tasks run in a dedicated worker process, ensuring your main application remains focused.

```phpt
#[Component]
class SomeScheduler {

    #[Scheduled(fixedDelay: 20, initialDelay: 10)]
    public function someScheduledMethodName(): void {
        echo 'I generate a unique ID every 20 seconds: ' . uniqid();
    }
}
```


### Configure Your Scheduled Tasks

Manage your scheduled tasks efficiently within your `application.yml` under the `winter.task.scheduling` section:

```yaml

winter:
    task:
        scheduling:
            poolSize: 1
            queueCapacity: 50
```

-   **`poolSize`**: Specify the total number of backend worker processes allocated for executing your scheduled tasks.
-   **`queueCapacity`**: Determine the maximum number of concurrent scheduled requests that can be queued.


## Dive Deeper: Explore the Example!

Ready to see Async and Scheduling in action?
Check out the comprehensive example application: [Example Service](https://github.com/suvera/winter-example-service)

```

