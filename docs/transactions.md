# Databases

Configure datasources in your application.yml

## application.yml

In below example, there are two datasources configured here with names.

1. defaultdb  (**isPrimary: true**)
2. admindb

```yaml

datasource:
    -   name: defaultdb
        isPrimary: true
        url: "sqlite::memory:"
        username: xxxxx
        password: xxzzz
        doctrine:
            entityPaths:
                - /path/to/defaultdb/entities
            isDevMode: false

    -   name: admindb
        url: "mysql:host=localhost;port=3307;dbname=testdb"
        username: xxxxx
        password: xxzzz
        connection:
            persistent: true
            errorMode: ERRMODE_EXCEPTION
            columnsCase: CASE_NATURAL
            idleTimeout: 300
            autoCommit: true
            defaultrowprefetch: 100

```

## PdbcTemplate

This is a template of database operations created automatically by the framework.

Below PdbcTemplate created for the Primary datasource (defaultdb)
```phpt

#[Autowired]
private PdbcTemplate $pdbc;

```

Below PdbcTemplate created for the any datasource (ex: admindb)
```phpt

#[Autowired("admindb-template")]
private PdbcTemplate $adminPdbc;

```

### PPA (PHP Persistence API)

PPA is Winter Framework's lightweight ORM layer (analogous to JPA in Java). Entity classes annotated with [`#[Table]`](src/stereotype/ppa/Table.php) implement [`PpaEntity`](src/ppa/PpaEntity.php) (or use [`PpaEntityTrait`](src/ppa/PpaEntityTrait.php)) and are automatically mapped to database rows. The framework generates INSERT/UPDATE/DELETE SQL and maps query results to PHP objects.

```phpt
#[Table("users")]
class User implements PpaEntity {
    use PpaEntityTrait;

    private int $id;
    private string $name;
    private string $email;
    // getters/setters...
}
```

### PdbcTemplate Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `execute(string $sql, array\|BindVars $bindVars = [], PreparedStatementCallback $action = null)` | `mixed` | Execute a SQL statement with optional bind variables and an optional callback that receives the `PreparedStatement` |
| `query(string $sql, array\|BindVars $bindVars, callable\|ResultSetExtractor\|RowCallbackHandler\|RowMapper $processor)` | `mixed` | Execute a query and process results via a callback, `ResultSetExtractor`, `RowCallbackHandler`, or `RowMapper` |
| `queryForList(string $sql, array\|BindVars $bindVars = [])` | `array` | Returns all rows as an array of associative arrays: `[ ['col' => 'val', ...], ... ]` |
| `queryForMap(string $sql, array\|BindVars $bindVars = [])` | `array` | Returns a single row as an associative array. Throws exception if result is not exactly one row |
| `queryForScalar(string $sql, array\|BindVars $bindVars = [])` | `int\|string\|float\|bool\|null` | Returns a single scalar value from the first column of the first row |
| `queryForObject(string $sql, array\|BindVars $bindVars, string\|RowMapper $classOrMapper = null)` | `object` | Returns a single row mapped to an object via `RowMapper` or class name |
| `queryForObjects(string $sql, array\|BindVars $bindVars, string $ppaClass)` | `array` | Returns an array of PPA entity objects populated from the query result. `$ppaClass` is the fully-qualified class name of a PPA entity (e.g., `User::class`) |
| `update(string $sql, array\|BindVars $bindVars, array\|OutBindVars $outBindVars = [], array &$generatedKeys = [])` | `int` | Execute an INSERT/UPDATE/DELETE statement. Returns number of affected rows. Supports OUT bind variables and generated keys |
| `batchUpdate(string $sql, array $arrayBindVars)` | `array` | Execute a batch of updates. `$arrayBindVars` is an array of bind-variable arrays, one per batch entry |
| `updateObjects(object ...$ppaObjects)` | `void` | Create or update PPA entity objects in the database. Each argument must implement [`PpaEntity`](src/ppa/PpaEntity.php) |
| `deleteObjects(object ...$ppaObjects)` | `void` | Delete PPA entity objects from the database. Each argument must implement [`PpaEntity`](src/ppa/PpaEntity.php) |


# Transaction Management

Framework comes-up with a annotation to manage transactions.

To enable transactions , you need enable it on your application using **#[EnableTransactionManagement]** annotation.

```phpt
#[EnableTransactionManagement]
class MyApplication {
    public static function main() {
        (new WinterWebApplication())->run(MyApplication::class);
    }
}

```

## Transactional

Attribute **#[Transactional]** allowed on methods that want to be executed within a transaction.

Framework has a default PdoTransactionManager (derived from PlatformTransactionManager ) that handles the transactions.
Nested transactions are not allowed by the Pdo.


If you want to extend and create your own transaction manager, then implement PlatformTransactionManager interface and create your manager.


#### Example (default transaction manager)

Below transaction is handled by the Default transaction manager (defaultdb)
```phpt

#[Transactional]
public function executeInTransaction(): void {
    // do something here
    
}

```

Below transaction is handled by the other transaction manager (admindb)
```phpt

#[Transactional("admindb-txn")]
public function executeInTransaction(): void {
    // do something here
    
}

```

#### Example (custom transaction manager)
```phpt

// You need to define a bean that returns PlatformTransactionManager, in your #[Configuration] annotated classes
#[Bean("myTxnMgr")]
public function getMyTransactionManager(DataSource $dataSource): PlatformTransactionManager {
    return new MyTransactionManager($dataSource);
}




// -------------------------------------------------------------------------------

// and then,  in your business class


#[Transactional(transactionManager: "myTxnMgr")]
public function withdrawMoney(float $amount): void {
    // do something here
}



```



Attribute **#[Transactional]** has more options.

Name | Required | Default Value | Description
------------ | ------------ | ------------ | ------------
transactionManager | No | "default" | Transaction Manager bean name
propagation |  |  | Way of Transaction Propagation
readOnly |  | default to 'false' | is ReadOnly transaction ?  rollback happens at last.
rollbackFor |  | default all exceptions | List of exception classes when occurred RollBack happens 
noRollbackFor |  | None | List of exception classes when occurred RollBack Does not happen.


# Multi-Tenant Support

Framework provides a `MultiTenantManager` class that can provide per-tenant `PdbcTemplate` and `PlatformTransactionManager` instances. This is useful when your application serves multiple tenants, each with their own database.

## Architecture

The framework defines a provider interface [`TenantDataSourceProvider`](src/pdbc/multitenant/TenantDataSourceProvider.php) that **you must implement**. Your implementation is responsible for returning a [`DataSourceConfig`](src/pdbc/datasource/DataSourceConfig.php) for a given tenant ID.

[`MultiTenantManager`](src/pdbc/multitenant/MultiTenantManager.php) takes your provider via constructor and lazily creates/caches per-tenant `PdbcTemplate`, `PlatformTransactionManager`, and `PdoDataSource` instances.

```
┌──────────────────────────────────────────────────────┐
│  Your Application                                    │
│                                                      │
│  TenantDataSourceProvider (you implement)            │
│    └─ getTenantDataSourceConfig($tenantId)           │
│         │                                            │
│         ▼                                            │
│  MultiTenantManager                                  │
│    ├─ getPdbcTemplate($tenantId) → PdbcTemplate      │
│    └─ getTransactionManager($tenantId) → TxManager   │
└──────────────────────────────────────────────────────┘
```

## Step 1: Implement TenantDataSourceProvider

Create a `#[Configuration]` class with a `#[Bean]` method that returns your implementation of [`TenantDataSourceProvider`](src/pdbc/multitenant/TenantDataSourceProvider.php).

```phpt
#[Configuration]
class MyTenantConfig {

    #[Autowired("admindb-template")]
    private PdbcTemplate $adminPdbc;

    #[Bean]
    public function tenantDataSourceProvider(): TenantDataSourceProvider {
        return new class implements TenantDataSourceProvider {

            public function getTenantDataSourceConfig(string $tenantId): DataSourceConfig {
                $tenantInfo = $this->adminPdbc->fetchOne("SELECT * FROM tenants WHERE tenant_id = ?", [$tenantId]);
                $dbHost = $tenantInfo['db_host'];
                $dbPort = $tenantInfo['db_port'];
                $username = $tenantInfo['username'];
                $dbName = $tenantInfo['database'];
                
                $config = new DataSourceConfig();
                $config->setName($tenantId);
                $config->setUrl("mysql:host=$dbHost;port=$dbPort;dbname=$dbName");
                $config->setUsername($username);
                $config->setPassword("tenant_pass");
                // Optional settings:
                // $config->setDriverClass('dev\\winterframework\\pdbc\\pdo\\PdoDataSource');
                // $config->setPersistent(true);
                // $config->setAutoCommit(true);
                // $config->setTimeoutSecs(30);
                // $config->setIdleTimeout(600);
                
                return $config;
            }
        };
    }
}
```

### Multiple MultiTenantManagers (e.g., region-based + product-based)

```phpt
#[Configuration]
class MyMultiTenantConfig {

    // Provider for region-based tenants
    #[Bean("regionTenantProvider")]
    public function regionTenantProvider(): TenantDataSourceProvider {
        return new class implements TenantDataSourceProvider {
            public function getTenantDataSourceConfig(string $tenantId): DataSourceConfig {
                // query region_tenants table
            }
        };
    }

    // Provider for product-line tenants
    #[Bean("productTenantProvider")]
    public function productTenantProvider(): TenantDataSourceProvider {
        return new class implements TenantDataSourceProvider {
            public function getTenantDataSourceConfig(string $tenantId): DataSourceConfig {
                // query product_tenants table
            }
        };
    }

    #[Bean("regionMt")]
    public function regionMultiTenantManager(
        #[Qualifier("regionTenantProvider")] TenantDataSourceProvider $provider
    ): MultiTenantManager {
        return new MultiTenantManager($provider);
    }

    #[Bean("productMt")]
    public function productMultiTenantManager(
        #[Qualifier("productTenantProvider")] TenantDataSourceProvider $provider
    ): MultiTenantManager {
        return new MultiTenantManager($provider);
    }
}
```

## Step 3: Use in Business Classes

```phpt
#[Component]
class OrderService {

    #[Autowired]
    private MultiTenantManager $mt;

    public function createOrder(string $tenantId, array $orderData): void {
        // Get tenant-specific PdbcTemplate
        $pdbc = $this->mt->getPdbcTemplate($tenantId);

        // Get tenant-specific TransactionManager
        $txnMgr = $this->mt->getTransactionManager($tenantId);

        $txnDef = new DefaultTransactionDefinition();
        $status = $txnMgr->getTransaction($txnDef);
        try {
            $pdbc->update(
                "INSERT INTO orders (customer, amount) VALUES (:customer, :amount)",
                ['customer' => $orderData['customer'], 'amount' => $orderData['amount']]
            );
            $txnMgr->commit($status);
        } catch (\Throwable $e) {
            $txnMgr->rollback($status);
            throw $e;
        }
    }
}
```

### With Multiple MultiTenantManagers

```phpt
#[Component]
class CrossTenantService {

    #[Autowired("regionMt")]
    private MultiTenantManager $regionMt;

    #[Autowired("productMt")]
    private MultiTenantManager $productMt;

    public function process(string $regionTenantId, string $productTenantId): void {
        $regionPdbc = $this->regionMt->getPdbcTemplate($regionTenantId);
        $productTxn = $this->productMt->getTransactionManager($productTenantId);
        // ...
    }
}
```

## API Reference

### MultiTenantManager

| Method | Returns | Description |
|--------|---------|-------------|
| `getPdbcTemplate(string $tenantId)` | [`PdbcTemplate`](src/pdbc/PdbcTemplate.php) | Returns a cached per-tenant `PdoTemplate` backed by a tenant-specific `PdoDataSource` |
| `getTransactionManager(string $tenantId)` | [`PlatformTransactionManager`](src/txn/PlatformTransactionManager.php) | Returns a cached per-tenant `PdoTransactionManager` backed by the same tenant-specific `PdoDataSource` |

### TenantDataSourceProvider

| Method | Returns | Description |
|--------|---------|-------------|
| `getTenantDataSourceConfig(string $tenantId)` | [`DataSourceConfig`](src/pdbc/datasource/DataSourceConfig.php) | Must return the connection configuration for the given tenant. Throw `RuntimeException` if tenant not found. |

### Caching Behavior

All three layers are lazily created on first access and cached per `$tenantId` within each `MultiTenantManager` instance:

- `PdoDataSource` — created once per tenant
- `PdoTemplate` — created once per tenant
- `PdoTransactionManager` — created once per tenant







