# Winter Boot - Unleash the Power of PHP 8.5+ Microservices!

> **Documentation: https://suvera.mintlify.site/** — full guides, references, and module docs live there.

Inspired by the elegance of Spring Boot, Winter Boot empowers you to build robust and scalable **microservices** in PHP 8.5 with unparalleled ease and familiarity. If you're a Spring Boot enthusiast looking to dive into the world of PHP, Winter Boot is your perfect gateway!

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

1)  **Prerequisite:** Ensure you have PHP 8.5 (or greater) installed.

2)  **Unleash Asynchronous Power:** For blazing-fast asynchronous functions (`#[Async]`) and scheduled tasks (`#[Scheduled]`), the `swoole` extension is highly recommended.
    
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

Winter Boot provides robust support for building and deploying your services, Explore our comprehensive guide on **[Building Services](https://suvera.mintlify.site/advanced/build-deploy)** to learn how to:

-   Generate optimized Phar files for easy distribution.
-   Effortlessly build Docker Images for containerized deployments. See a practical example in the [example-service](https://github.com/suvera/winter-example-service) repository:
    -   [Dockerfile](https://github.com/suvera/winter-example-service/blob/master/Dockerfile)

# 4. In-Depth Documentation

Full documentation lives at **https://suvera.mintlify.site/** — same content and structure as below.

## Framework

### Getting Started

-   [**Introduction**](https://suvera.mintlify.site/introduction)
-   [**Quickstart**](https://suvera.mintlify.site/quickstart)
-   [**Configuration**](https://suvera.mintlify.site/configuration)

### Core Concepts

-   [**Dependency Injection**](https://suvera.mintlify.site/core/dependency-injection)
-   [**AOP**](https://suvera.mintlify.site/core/aop)
-   [**App Lifecycle**](https://suvera.mintlify.site/core/application-lifecycle)
-   [**Module System**](https://suvera.mintlify.site/core/module-system)

### Web & REST

-   [**REST Controllers**](https://suvera.mintlify.site/web/rest-controllers)
-   [**Request Mapping**](https://suvera.mintlify.site/web/request-mapping)
-   [**Interceptors**](https://suvera.mintlify.site/web/interceptors)

### Data

-   [**Database**](https://suvera.mintlify.site/data/database)
-   [**Transactions**](https://suvera.mintlify.site/data/transactions)
-   [**Migrations**](https://suvera.mintlify.site/data/migrations)
-   [**OpenSearch Migrations**](https://suvera.mintlify.site/data/opensearch-migrations)

### Async & Concurrency

-   [**Async Tasks**](https://suvera.mintlify.site/async/async-tasks)
-   [**Scheduling**](https://suvera.mintlify.site/async/scheduling)
-   [**Daemon Threads**](https://suvera.mintlify.site/async/daemon-threads)
-   [**Locking**](https://suvera.mintlify.site/ops/locking)

### Operations

-   [**Caching**](https://suvera.mintlify.site/ops/caching)
-   [**Logging**](https://suvera.mintlify.site/ops/logging)
-   [**Actuator**](https://suvera.mintlify.site/ops/actuator)
-   [**Telemetry**](https://suvera.mintlify.site/ops/telemetry)

### Building Applications

-   [**CLI Commands**](https://suvera.mintlify.site/building/cli-commands)
-   [**Testing**](https://suvera.mintlify.site/building/testing)
-   [**JSON & XML**](https://suvera.mintlify.site/advanced/json-xml)
-   [**Local Stores**](https://suvera.mintlify.site/advanced/local-stores)
-   [**Utilities**](https://suvera.mintlify.site/building/utilities)
-   [**Build & Deploy**](https://suvera.mintlify.site/advanced/build-deploy)

## Libraries

### Overview

-   [**Overview**](https://suvera.mintlify.site/modules/overview)

### Data

-   [**Doctrine**](https://suvera.mintlify.site/modules/doctrine)
-   [**Redis**](https://suvera.mintlify.site/modules/data-redis)
-   [**Memcache**](https://suvera.mintlify.site/modules/data-memcache)

### Embedded In-Memory Servers

-   [**Memdb**](https://suvera.mintlify.site/modules/memdb)

### Messaging

-   [**Kafka**](https://suvera.mintlify.site/modules/kafka)
-   [**SQS**](https://suvera.mintlify.site/modules/sqs)

### Storage & Search

-   [**S3**](https://suvera.mintlify.site/modules/s3)
-   [**OpenSearch**](https://suvera.mintlify.site/modules/opensearch)

### Distributed Systems

-   [**Eureka**](https://suvera.mintlify.site/modules/eureka)
-   [**DTCE**](https://suvera.mintlify.site/modules/dtce)

### Planned

-   [**Security**](https://suvera.mintlify.site/modules/security)

## Reference

-   [**Attributes**](https://suvera.mintlify.site/reference/attributes)
-   [**application.yml**](https://suvera.mintlify.site/reference/application-yml)

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
-   [**SQS Module**](https://github.com/suvera/winter-modules/tree/master/winter-sqs): Seamless integration with Amazon SQS.
-   [**OpenSearch Module**](https://github.com/suvera/winter-modules/tree/master/winter-opensearch): Seamless integration with OpenSearch.
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
