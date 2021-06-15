# Winter Boot - PHP 8.0+ Framework

Inspired by Spring Boot, you can build PHP applications in that style.

**Application Startup:**

```phpt

#[WinterBootApplication]
class MyApplication {

    public static function main() {
        (new WinterWebApplication())->run(MyApplication::class);
    }

}

MyApplication::main();


```

This framework gives advantage to people already know Spring Boot, and want to jump into PHP8.

- Dependency Injection is managed by the framework with PHP8 Attributes (Annotations)

## Sample Service

```phpt
#[Service]
class UserServiceImpl implements UserService {

    #[Autowired]
    private PdbcTemplate $pdbc;

    public function createUser(string $name, string $email) {
        $this->pdbc->update(/* ... */);
    }
}

--------------------------------------------------------------------

#[RestController]
class MyController {

    #[Autowired]
    private UserService $userService;


    #[RequestMapping(path: "/api/v2/users", method: [RequestMethod::POST]]
    public function createUser(
        #[RequestParam] string $name,
        #[RequestParam] string $email
    ): ResponseEntity {
        $this->userService->createUser($name, $email);
        
        return ResponseEntity::ok()->withJson($someJsonArray);
    }
}


# curl command
curl "http://localhost/api/v2/users" -d "name=Abc&email=mail"

```

# Example Application


Check out the example application here [MyApp](examples/)


# Documentation


Documentation

- [StereoTypes & Dependency Injection](docs/dependency_stereo_types.md)
- [Configuration](docs/configuration.md)
- [Logging](docs/logging.md)
- [Application Start/Booting](docs/application_starter.md)
- [REST API Development](docs/rest_api.md)
- [Caching](docs/caching.md)
- [Custom StereoTypes & Aspect Oriented Magic](docs/custom_aop.md)
- [Transaction Management](docs/transactions.md)
- [Actuator](docs/actuator.md)
- [Locking](docs/locking.md)
- [Json and XML](docs/json_xml.md)
- [Async and Scheduling support](docs/async_scheduling.md)


# Installation


1) Install PHP 8.0 (or greater)

2) Install following extensions - they are optional but required for few features to work

- [Asynchronous functions](https://github.com/suvera/winter-boot/blob/master/docs/async_scheduling.md) #[Async] and #[Scheduled] to work, swoole extension needed.
    
```shell
pecl install swoole
```
    
- Rdkafka extension is needed for  [Kafka module](https://github.com/suvera/winter-modules/tree/master/winter-kafka) to work

```shell
pecl install rdkafka
```

- Rdkafka extension is needed for  [Redis module](https://github.com/suvera/winter-modules/tree/master/winter-redis) to work

```shell
pecl install redis
```




## composer.json
Add following repositories into **composer.json**

```json

"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/suvera/winter-boot"
    },
    {
        "type": "vcs",
        "url": "https://github.com/suvera/winter-modules"
    }
]


```

Now install framework with below commands

```shell

composer require suvera/winter-boot

composer require suvera/winter-modules

```

You're Done!
