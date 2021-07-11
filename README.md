# Winter Boot - PHP 8.0+ Framework

Inspired by Spring Boot, you can build micro-services in PHP 8 in that style.

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

# Example Micro-service

Check out the example application here [example-service](https://github.com/suvera/winter-example-service)


# Documentation

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
- [Shared In-Memory Stores](docs/local_store.md)
- [Building Service](docs/build.md)



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

- Redis extension is needed for  [Redis module](https://github.com/suvera/winter-modules/tree/master/winter-redis) to work

```shell
pecl install redis
```




## Composer

Install framework with composer

```shell

composer require suvera/winter-boot

composer require suvera/winter-modules

```

You're Done!

# Module Extensions

This framework can be extended even further by using 
**[https://github.com/suvera/winter-modules](https://github.com/suvera/winter-modules)**

## How to create new module

- Create a new module by extending [WinterModule](src/core/app/WinterModule.php)
- Check the existing module for more information.


# Build & Deployment

Framework Support **[Phing](https://www.phing.info/)** build system

- Phing Version >= 3.0.0

- Download **Phar** file from [https://github.com/phingofficial/phing/releases/](https://github.com/phingofficial/phing/releases/)

- Copy Phar file to 
```shell
cp phing-3.0.0-RC2.phar /usr/local/bin/
```
- Create symlink to bin directory

```shell
ln -s /usr/local/bin/phing-3.0.0-RC2.phar /usr/bin/phing
```

- Now **phing** command should work!


### How to build service

- Phar binary support for your micro-service
- RPM binary support
- init.d script support

Go through this document **[Building Service](docs/build.md)**
