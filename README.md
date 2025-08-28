# Winter Boot - Unleash the Power of PHP 8.0+ Microservices!

Inspired by the elegance of Spring Boot, Winter Boot empowers you to build robust and scalable **microservices** in PHP 8 with unparalleled ease and familiarity. If you're a Spring Boot enthusiast looking to dive into the world of PHP, Winter Boot is your perfect gateway!

**Effortless Application Setup:**

Get your Winter Boot application up and running in no time. The `@WinterBootApplication` attribute simplifies your main application class, making bootstrapping a breeze.

```phpt

#[WinterBootApplication]
class MyApplication {

    public static function main() {
        (new WinterWebSwooleApplication())->run(MyApplication::class);
    }

}

MyApplication::main();


```

**Key Features that will make you love Winter Boot:**

-   **Dependency Injection with PHP 8 Attributes:** Say goodbye to complex configurations! Winter Boot leverages the power of PHP 8 Attributes (Annotations) for seamless and intuitive dependency injection.

## Build Powerful Services with Ease

Craft your services and REST APIs with a clean, attribute-driven approach.

**Example Service Implementation:**

Define your services with the `@Service` attribute and inject dependencies effortlessly using `@Autowired`.

```phpt
#[Service]
class UserServiceImpl implements UserService {

    #[Autowired]
    private PdbcTemplate $pdbc;

    public function createUser(string $name, string $email) {
        $this->pdbc->update(/* ... */);
    }
}
```

**Crafting RESTful APIs:**

Transform your classes into powerful REST controllers using `@RestController` and map your endpoints with `@RequestMapping`. Handling requests and returning `ResponseEntity` has never been this elegant!

```phpt

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
```

**Test Your API Instantly:**

Quickly test your newly created API endpoint with a simple `curl` command.

```shell
curl "http://localhost/api/v2/users" -d "name=Abc&email=mail"

```

# 1. Explore a Live Example Microservice

Dive into a complete, working example of a Winter Boot microservice to see it in action!
Check out the example application here [example-service](https://github.com/suvera/winter-example-service)

# 2. Getting Started: Installation

Ready to build amazing things with Winter Boot? Follow these simple steps to get started!

1)  **Prerequisite:** Ensure you have PHP 8.0 (or greater) installed.

2)  **Unleash Asynchronous Power (Optional but Recommended):** For blazing-fast asynchronous functions (`#[Async]`) and scheduled tasks (`#[Scheduled]`), the `swoole` extension is highly recommended.
    
```shell
pecl install swoole
```


## Seamless Installation with Composer

Integrate Winter Boot into your project effortlessly using Composer.

```shell

composer require suvera/winter-boot

composer require suvera/winter-modules

```

You're Done! Get ready to code!

# 3. Build & Deploy Your Winter Boot Applications

Winter Boot provides robust support for building and deploying your services, leveraging the powerful **[Phing](https://www.phing.info/)** build system.

Explore our comprehensive guide on **[Building Services](docs/build.md)** to learn how to:

-   Generate optimized Phar files for easy distribution.
-   Effortlessly build Docker Images for containerized deployments. See a practical example in the [example-service](https://github.com/suvera/winter-example-service) repository:
    -   [Dockerfile](https://github.com/suvera/winter-example-service/blob/master/Dockerfile)
    -   [build.xml](https://github.com/suvera/winter-example-service/blob/master/build.xml)

# 4. In-Depth Documentation

Unlock the full potential of Winter Boot with our detailed documentation. Each guide is crafted to help you master specific aspects of the framework:

-   [**StereoTypes & Dependency Injection**](docs/dependency_stereo_types.md): Master the art of dependency management.
-   [**Configuration**](docs/configuration.md): Learn how to configure your applications with flexibility.
-   [**Logging**](docs/logging.md): Implement effective logging for monitoring and debugging.
-   [**Application Start/Booting**](docs/application_starter.md): Understand the lifecycle of your Winter Boot application.
-   [**REST API Development**](docs/rest_api.md): Build powerful and efficient RESTful services.
-   [**Caching**](docs/caching.md): Optimize performance with intelligent caching strategies.
-   [**Custom StereoTypes & Aspect Oriented Magic**](docs/custom_aop.md): Discover advanced AOP techniques.
-   [**Databases & Transactions**](docs/transactions.md): Manage your data with robust database and transaction support.
-   [**Actuator**](docs/actuator.md): Gain insights into your application's health and metrics.
-   [**Locking**](docs/locking.md): Implement concurrency control for critical sections.
-   [**Json and XML**](docs/json_xml.md): Seamlessly handle data serialization and deserialization.
-   [**Async and Scheduling support**](docs/async_scheduling.md): Harness the power of asynchronous operations and scheduled tasks.
-   [**Shared In-Memory Stores**](docs/local_store.md): Utilize high-performance in-memory data stores.
-   [**Daemon Threads**](docs/daemon_threads.md): Run background processes efficiently.
-   [**Building & Deployment**](docs/build.md): Comprehensive guide to packaging and deploying your applications.

# 5. Extend Your Horizons with Module Extensions

Winter Boot is designed for extensibility! Expand its capabilities even further by integrating powerful modules from the **[Winter Modules project](https://github.com/suvera/winter-modules)**.

### Craft Your Own Modules!

Want to contribute or build a custom integration? Creating a new module is straightforward! Simply extend `[WinterModule](src/core/app/WinterModule.php)`.
Check out these existing modules for inspiration and reference:

-   [**Doctrine ORM/DBAL Module**](https://github.com/suvera/winter-doctrine): Robust database abstraction and ORM.
-   [**Redis Module**](https://github.com/suvera/winter-modules/tree/master/winter-data-redis): High-performance caching and data structures.
-   [**Apache Kafka Module**](https://github.com/suvera/winter-modules/tree/master/winter-kafka): Stream processing with Kafka.
-   [**DTCE Module**](https://github.com/suvera/winter-modules/tree/master/winter-dtce): Distributed Transaction Coordination.
-   [**S3 Module**](https://github.com/suvera/winter-modules/tree/master/winter-s3): Seamless integration with Amazon S3.
-   [**Memdb Module**](https://github.com/suvera/winter-memdb): Integrate with popular in-memory databases like Apache Ignite, Redis, Memcached, Hazelcast, and more!
-   [**Service Discovery**](https://github.com/suvera/winter-eureka): Connect with Consul, Netflix Eureka, and other service discovery solutions.


# 6. Frequently Asked Questions (FAQ)

Got questions? We've got answers!

#### 1. Can I integrate components from other PHP frameworks into my Winter Boot project?

Absolutely! Winter Boot is designed to be highly interoperable. You can seamlessly incorporate any component from popular PHP frameworks like Symfony, Yii2, Laravel, CodeIgniter, and more, simply by using Composer.

**Examples:**
```phpt
# Symfony Security component
composer require symfony/security


# Laravel illuminate events component
composer require illuminate/events


# Yii Arrays Component
composer require yiisoft/arrays --prefer-dist
```

**Important:** Always ensure that the components you integrate are compatible with PHP 8.0+.

#### 2. Is it possible to use RoadRunner or Workerman with Winter Boot?

Yes, indeed! Winter Boot's flexible architecture allows you to extend the framework and create your own core Application runner classes to support different server environments.

**Current Swoole Integration:**

Winter Boot already provides a robust integration with Swoole:

```phpt
class WinterWebSwooleApplication extends WinterApplicationRunner implements WinterApplication {
}
```

**Your Custom Integrations:**

You can follow the same pattern to integrate with RoadRunner, Workerman, or any other server:

```phpt
class WinterWebWorkermanApplication extends WinterApplicationRunner implements WinterApplication {
}

class WinterRoadRunnerApplication extends WinterApplicationRunner implements WinterApplication {
}
```
