# Winter Boot - Unleash the Power of PHP 8.5+ Microservices!

> **Documentation: https://suvera.github.io/winter-boot/** — full guides, references, and module docs live there.

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

2)  **Install the required extensions:** the `swoole` extension (built-in HTTP server, `#[Async]`, `#[Scheduled]`) and the bundled `winter_boot` native extension (required since 2.1.0 — the application stops at boot without it).

```shell
pecl install swoole
```

Build `winter_boot` from the `php-ext/` directory (needs PHP dev headers with `phpize`, `php-config`):

```shell
cd php-ext
phpize
./configure --enable-winter_boot
make
make install
```

Then enable both in `php.ini` (`extension=swoole.so`, `extension=winter_boot.so`). Full guide with optional per-module extensions: https://suvera.github.io/winter-boot/installation


## Seamless Installation with Composer

Integrate Winter Boot into your project effortlessly using Composer.

```shell

composer require suvera/winter-boot

composer require suvera/winter-modules

```

You're Done! Get ready to code!

# 3. Build & Deploy Your Winter Boot Applications

Winter Boot provides robust support for building and deploying your services, Explore our comprehensive guide on **[Building Services](https://suvera.github.io/winter-boot/advanced/build-deploy)** to learn how to:

-   Generate optimized Phar files for easy distribution.
-   Effortlessly build Docker Images for containerized deployments. See a practical example in the [example-service](https://github.com/suvera/winter-example-service) repository:
    -   [Dockerfile](https://github.com/suvera/winter-example-service/blob/master/Dockerfile)

# 4. In-Depth Documentation

Full documentation lives at **https://suvera.github.io/winter-boot/** — same content and structure as below.

## Framework

### Getting Started

-   [**Introduction**](https://suvera.github.io/winter-boot/introduction)
-   [**Quickstart**](https://suvera.github.io/winter-boot/quickstart)
-   [**Configuration**](https://suvera.github.io/winter-boot/configuration)

### Core Concepts

-   [**Dependency Injection**](https://suvera.github.io/winter-boot/core/dependency-injection)
-   [**AOP**](https://suvera.github.io/winter-boot/core/aop)
-   [**App Lifecycle**](https://suvera.github.io/winter-boot/core/application-lifecycle)
-   [**Module System**](https://suvera.github.io/winter-boot/core/module-system)
-   [**Sessions**](https://suvera.github.io/winter-boot/core/request-sessions)

### Web & REST

-   [**REST Controllers**](https://suvera.github.io/winter-boot/web/rest-controllers)
-   [**Request Mapping**](https://suvera.github.io/winter-boot/web/request-mapping)
-   [**Interceptors**](https://suvera.github.io/winter-boot/web/interceptors)
-   [**RestTemplate**](https://suvera.github.io/winter-boot/web/rest-template)

### Data

-   [**Database**](https://suvera.github.io/winter-boot/data/database)
-   [**Transactions**](https://suvera.github.io/winter-boot/data/transactions)
-   [**Migrations**](https://suvera.github.io/winter-boot/data/migrations)
-   [**OpenSearch Migrations**](https://suvera.github.io/winter-boot/data/opensearch-migrations)

### Async & Concurrency

-   [**Async Tasks**](https://suvera.github.io/winter-boot/async/async-tasks)
-   [**Scheduling**](https://suvera.github.io/winter-boot/async/scheduling)
-   [**Daemon Threads**](https://suvera.github.io/winter-boot/async/daemon-threads)
-   [**Locking**](https://suvera.github.io/winter-boot/ops/locking)

### Operations

-   [**Caching**](https://suvera.github.io/winter-boot/ops/caching)
-   [**Logging**](https://suvera.github.io/winter-boot/ops/logging)
-   [**Actuator**](https://suvera.github.io/winter-boot/ops/actuator)
-   [**Telemetry**](https://suvera.github.io/winter-boot/ops/telemetry)

### Building Applications

-   [**CLI Commands**](https://suvera.github.io/winter-boot/building/cli-commands)
-   [**Testing**](https://suvera.github.io/winter-boot/building/testing)
-   [**JSON & XML**](https://suvera.github.io/winter-boot/advanced/json-xml)
-   [**Local Stores**](https://suvera.github.io/winter-boot/advanced/local-stores)
-   [**Utilities**](https://suvera.github.io/winter-boot/building/utilities)
-   [**Build & Deploy**](https://suvera.github.io/winter-boot/advanced/build-deploy)
-   [**Native Extension**](https://suvera.github.io/winter-boot/advanced/native-extension)

## Libraries

### Overview

-   [**Overview**](https://suvera.github.io/winter-boot/modules/overview)

### Data

-   [**Doctrine**](https://suvera.github.io/winter-boot/modules/doctrine)
-   [**Redis**](https://suvera.github.io/winter-boot/modules/data-redis)
-   [**Memcache**](https://suvera.github.io/winter-boot/modules/data-memcache)

### Messaging

-   [**Kafka**](https://suvera.github.io/winter-boot/modules/kafka)
-   [**SQS**](https://suvera.github.io/winter-boot/modules/sqs)

### Storage & Search

-   [**S3**](https://suvera.github.io/winter-boot/modules/s3)
-   [**OpenSearch**](https://suvera.github.io/winter-boot/modules/opensearch)

### Distributed Systems

-   [**Eureka**](https://suvera.github.io/winter-boot/modules/eureka)
-   [**DTCE**](https://suvera.github.io/winter-boot/modules/dtce)

### Planned

-   [**Security**](https://suvera.github.io/winter-boot/modules/security)

### Embedded In-Memory Servers

-   [**Memdb - *EXPERIMENTAL* **](https://suvera.github.io/winter-boot/modules/memdb)

## Examples

Examples built with Winter Boot and Winter Modules

- [Examples](https://suvera.github.io/winter-boot/examples/overview)
    - [Redis Example](https://suvera.github.io/winter-boot/examples/redis-app)
    - [Redis Queue Example](https://suvera.github.io/winter-boot/examples/redis-queue)
    - [Doctrine Example](https://suvera.github.io/winter-boot/examples/doctrine-app)
    - [SQS Consumer Example](https://suvera.github.io/winter-boot/examples/sqs-consumer)
    - [Kafka Consumer Example](https://suvera.github.io/winter-boot/examples/kafka-app)
    - [S3 Example](https://suvera.github.io/winter-boot/examples/s3-app)
    - [Opensearch Example](https://suvera.github.io/winter-boot/examples/opensearch-app)
    - [Daemon Threads Example](https://suvera.github.io/winter-boot/examples/daemon-app)
    - [Scheduler Example](https://suvera.github.io/winter-boot/examples/scheduler-app)
    - [DTCE Example](https://suvera.github.io/winter-boot/examples/dtce-app)
    - [AOP Example](https://suvera.github.io/winter-boot/examples/aop-app)

## How To

Step-by-step guides for common tasks in Winter Boot applications

- [Overview](https://suvera.github.io/winter-boot/howto/overview)
    - [RBAC](https://suvera.github.io/winter-boot/howto/rbac)
    - [ABAC](https://suvera.github.io/winter-boot/howto/abac)
    - [Build UI](https://suvera.github.io/winter-boot/howto/build-ui)
    - [Email](https://suvera.github.io/winter-boot/howto/email)
    - [PDF](https://suvera.github.io/winter-boot/howto/pdf)
    - [Images](https://suvera.github.io/winter-boot/howto/images)
    - [Gemini AI](https://suvera.github.io/winter-boot/howto/gemini)

## Reference

-   [**Attributes**](https://suvera.github.io/winter-boot/reference/attributes)
-   [**application.yml**](https://suvera.github.io/winter-boot/reference/application-yml)

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
