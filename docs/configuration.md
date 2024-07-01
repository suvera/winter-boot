# Configuration

There are two types of configuration

- Properties Configuration
- Bean Configuration (Logic Configuration)

## Application Properties

All properties can be defined in **application.yml** , and it must be there in **config**
directory mentioned by **#[WinterBootApplication]** attribute.

#### Example:

```yaml
server:
    port: 80
    address: 127.0.0.1
    context-path: /
winter:
    application:
        name: My Demo Application
        id: MyApp
        version: 1.0.0-DEV
    namespaces:
        cacheTime: 10
    route:
        cacheTime: 10
myApp:
    value1: This is string
    value2: 99
    value3: true
    value4: 10.89

datasource:
    -   name: default
        url: "sqlite:/opt/databases/mydb.sq3"
        username:
        password:
        validationQuery: SELECT 'Databse Connected'
        driverClass: dev\winterframework\pdbc\pdo\PdoDataSource
        connection:
            persistent: true
            errorMode: ERRMODE_EXCEPTION
            columnsCase: CASE_NATURAL
            idleTimeout: 300
            autoCommit: true
            defaultrowprefetch: 100

```

# Bean Configuration

This is more like Logic configuration, it has to be done with **#[Configuration]** attribute.

Define classes with **#[Configuration]** attribute that produces as many managed beans by **#[Bean]**

- DataSource beans
- Transaction Beans
- Caching Managers
- Application Services
- Application Components
- etc ...

```phpt

#[Configuration]
class DatabaseConfig {

    #[Bean]
    public function getDataSource(): DataSource {
        new PdoDataSource(...$args_here);
    }
    
    #[Bean]
    public function getTransactionManager(DataSource $ds): PlatformTransactionManager {
        new PdoTransactionManager($ds);
    }

}

```

# Additional Property Sources

Along with application.yml, framework provides a way to add more property sources, such as

- from Additional Files (.properties, .ini, .json, .xml etc ...)
- from Vault
- from Consul
- From ENV

Just implement [PropertySource](../src/io/PropertySource.php) interface.

```phpt
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
            throw new PropertyException('could not found property ' . $name . '');
        }
        
        return $_ENV[$name];
    }
}
```

and in **application.yml** add these lines

```yaml
propertySources:
    -   name: env
        provider: dev\winterframework\io\EnvPropertySource

    -   name: vault
        provider: some\org\namespace\VaultPropertySource  # demo class name, This is not implemented in framework
        url: https://127.0.0.1:443/
        token: some-text
        more: some-more

some:
    property1: $env.SOME_VALUE
    property2: $vault.some_value2
```

