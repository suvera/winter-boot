# Master Your Application's Brain: Configuration in Winter Boot!

Configuration is the heart of any robust application, dictating how it behaves, connects, and performs. Winter Boot empowers you with a flexible and intuitive configuration system, allowing you to define both static properties and dynamic bean configurations with ease!

Let's explore the two powerful ways to configure your Winter Boot application:

- **Properties Configuration**: For defining static, externalized settings.
- **Bean Configuration (Logic Configuration)**: For programmatically defining and wiring your application's components.

## 1. Application Properties: Your App's Blueprint (`application.yml`)

All your application's externalized properties find their home in the `application.yml` file. This crucial file *must* reside within the `config` directory, which is typically specified by your `#[WinterBootApplication]` attribute. This centralizes your settings, making your application highly adaptable and easy to manage across different environments.

#### Example `application.yml`:

```yaml
server:
    port: 80
    address: 127.0.0.1
    context-path: / # Define your server's port, address, and context path
winter:
    application:
        name: My Awesome Winter App # Give your application a friendly name
        id: MyApp                  # A unique identifier for your application
        version: 1.0.0-DEV         # Track your application's version
    namespaces:
        cacheTime: 10              # Configure caching durations for namespaces
    route:
        cacheTime: 10              # Set caching times for routes
myApp:
    value1: This is a string property
    value2: 99                     # Numeric property
    value3: true                   # Boolean property
    value4: 10.89                  # Floating-point property

datasource:
    -   name: default
        url: "sqlite:/opt/databases/mydb.sq3" # Database connection URL
        username:                            # Database username (can be empty)
        password:                            # Database password (can be empty)
        validationQuery: SELECT 'Database Connected' # Query to validate connections
        driverClass: dev\winterframework\pdbc\pdo\PdoDataSource # The PDO driver class
        connection:
            persistent: true                 # Use persistent connections
            errorMode: ERRMODE_EXCEPTION     # Set PDO error mode
            columnsCase: CASE_NATURAL        # Column case handling
            idleTimeout: 300                 # Connection idle timeout in seconds
            autoCommit: true                 # Enable auto-commit
            defaultrowprefetch: 100          # Default number of rows to prefetch

```

## 2. Bean Configuration: Crafting Your Application's Logic with Code!

Beyond static properties, Winter Boot allows you to define your application's core logic and components programmatically using **Bean Configuration**. This is achieved through the powerful `#[Configuration]` and `#[Bean]` attributes.

Simply define classes annotated with `#[Configuration]`. Within these classes, methods annotated with `#[Bean]` will produce managed beans that Winter Boot will automatically wire into your application context. This is where you define the building blocks of your application, such as:

-   **DataSource beans**: For managing database connections.
-   **Transaction Beans**: For controlling transactional behavior.
-   **Caching Managers**: To integrate and manage various caching strategies.
-   **Application Services**: Your core business logic components.
-   **Application Components**: Reusable parts of your application.
-   ...and any other custom components your application needs!

```phpt
#[Configuration]
class DatabaseConfig {

    #[Bean]
    public function getDataSource(): DataSource {
        // Here, you'd typically read properties from application.yml to configure your DataSource
        return new PdoDataSource(...$args_here);
    }

    #[Bean]
    public function getTransactionManager(DataSource $ds): PlatformTransactionManager {
        // Winter Boot automatically injects the DataSource bean defined above!
        return new PdoTransactionManager($ds);
    }

}
```

## 3. Additional Property Sources: Expand Your Configuration Horizons!

Winter Boot isn't limited to just `application.yml`! It provides an incredibly flexible mechanism to integrate additional property sources, allowing you to pull configuration from various external locations. This means you can effortlessly manage settings from:

-   **Additional Files**: `.properties`, `.ini`, `.json`, `.xml`, and more!
-   **Vault**: Securely fetch secrets and configurations.
-   **Consul**: Leverage a distributed key-value store for dynamic configurations.
-   **Environment Variables (ENV)**: Easily configure your application via system environment variables.

To integrate a custom property source, all you need to do is implement the elegant `[PropertySource](../src/io/PropertySource.php)` interface. This gives you full control over how properties are loaded and accessed.

```phpt
use dev\winterframework\io\PropertySource;
use dev\winterframework\io\PropertyContext;
use dev\winterframework\exception\PropertyException;

class EnvPropertySource implements PropertySource {
    public function __construct(private array $source, private PropertyContext $defaultProps) {
    }

    public function has(string $name): bool {
        return isset($_ENV[$name]);
    }

    public function getAll(): array {
        return $_ENV;
    }

    public function get(string $name): mixed {
        if (!isset($_ENV[$name])) {
            throw new PropertyException('Could not find property ' . $name . '');
        }

        return $_ENV[$name];
    }
}
```

Once you've implemented your custom `PropertySource`, you can easily register it in your `application.yml`:

```yaml
propertySources:
    -   name: env # A unique name for your property source
        provider: dev\winterframework\io\EnvPropertySource # The fully qualified class name of your PropertySource implementation

    -   name: vault # Example for a hypothetical Vault property source
        provider: some\org\namespace\VaultPropertySource  # (Note: This is a demo class, not implemented in framework)
        url: https://127.0.0.1:443/ # Vault server URL
        token: some-secret-token    # Access token for Vault
        more: some-more-config      # Additional custom properties for your VaultPropertySource

# Now, effortlessly reference properties from your custom sources!
some:
    property1: $env.SOME_VALUE # Fetches 'SOME_VALUE' from your environment variables
    property2: $vault.some_value2 # Fetches 'some_value2' from your hypothetical VaultPropertySource
```

With Winter Boot's comprehensive configuration capabilities, you have all the tools you need to build highly adaptable, maintainable, and powerful PHP applications. Configure with confidence and unleash your creativity!

