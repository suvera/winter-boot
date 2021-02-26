# Winter Boot - PHP 8.0+ Framework

**(Under Dev)**

### Can you do it in PHP8 ?

Inspired by Spring Boot, So, you can build PHP applications in that style.

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

## Example Service

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


    #[RequestMapping(path: "/api/v2/createUser", method: [RequestMethod::POST]]
    public function createUser(
        #[RequestParam] string $name,
        #[RequestParam] string $email
    ) {
        $this->userService->createUser($name, $email);
        
        return $someJson;
    }
}

```

# Examples

----

Check out the example application here [MyApp](examples/MyApp)

- Example [Controllers](examples/MyApp/src/controller)
- Example [Services](examples/MyApp/src/service)
- Example [StereoTypes](examples/MyApp/src/stereotype)
- Example [Config YML's](examples/MyApp/config)

**MyAPP API URLs:**

```
curl -v "http://localhost/hello/world"


curl -v "http://localhost/calc/add" -d "a=10&b=30"

```

# Documentation

----

Documentation

- [StereoTypes & Dependency Injection](docs/dependency_stereo_types.md)
- [Configuration](docs/configuration.md)
- [Logging](docs/logging.md)
- [Application Start/Booting](docs/application_starter.md)
- [REST Controller](docs/rest_api.md)
- [Caching](docs/caching.md)
- [Custom StereoTypes & Aspect Oriented Magic](docs/custom_aop.md)
- [Transaction Management](docs/transactions.md)
- [Actuator](docs/actuator.md)
- [Locking](docs/locking.md)

