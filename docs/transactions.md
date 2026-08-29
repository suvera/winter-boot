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


## Programmatic Transaction Management

In addition to the declarative `#[Transactional]` annotation, you can manage transactions programmatically using [`PlatformTransactionManager`](src/txn/PlatformTransactionManager.php). This is useful when you need fine-grained control or when working with dynamically-resolved transaction managers (e.g., from [`MultiTenantManager`](src/pdbc/multitenant/MultiTenantManager.php)).

### PlatformTransactionManager Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `getTransaction(TransactionDefinition $definition)` | [`TransactionStatus`](src/txn/TransactionStatus.php) | Begin a new transaction (or join an existing one, depending on propagation). Returns a status object representing the current transaction |
| `commit(TransactionStatus $status)` | `void` | Commit the transaction represented by the given status |
| `rollback(TransactionStatus $status)` | `void` | Roll back the transaction represented by the given status |

### TransactionDefinition

[`TransactionDefinition`](src/txn/TransactionDefinition.php) controls transaction properties like propagation, isolation, timeout, and read-only mode. Use [`DefaultTransactionDefinition`](src/txn/support/DefaultTransactionDefinition.php) for standard cases:

| Property | Default | Description |
|----------|---------|-------------|
| `propagationBehavior` | `PROPAGATION_REQUIRED` (0) | How the transaction relates to existing transactions |
| `isolationLevel` | `ISOLATION_DEFAULT` | Database isolation level |
| `timeout` | `TIMEOUT_DEFAULT` | Transaction timeout in seconds |
| `readOnly` | `false` | Whether the transaction is read-only |

**Propagation constants** (defined in [`Transaction`](src/txn/Transaction.php)):

| Constant | Value | Behavior |
|----------|-------|----------|
| `PROPAGATION_REQUIRED` | 0 | Support current tx; create new if none exists *(default)* |
| `PROPAGATION_SUPPORTS` | 1 | Support current tx; execute non-transactionally if none |
| `PROPAGATION_MANDATORY` | 2 | Support current tx; throw exception if none exists |
| `PROPAGATION_REQUIRES_NEW` | 3 | Create new tx, suspending current if one exists |
| `PROPAGATION_NOT_SUPPORTED` | 4 | Execute non-transactionally; suspend current tx |
| `PROPAGATION_NEVER` | 5 | Execute non-transactionally; throw exception if tx exists |
| `PROPAGATION_NESTED` | 6 | Execute within nested tx if current tx exists *(not supported by PDO)* |

### Example: Programmatic Transaction

Below is a real-world example from the PDBC sample application (`UserService`). It demonstrates programmatic transaction management using `PlatformTransactionManager` with `PdbcTemplate`:

```phpt
use dev\winterframework\pdbc\ex\EmptyResultDataAccessException;
use dev\winterframework\pdbc\PdbcTemplate;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;
use dev\winterframework\txn\PlatformTransactionManager;
use dev\winterframework\txn\support\DefaultTransactionDefinition;
use dev\winterframework\util\log\Wlf4p;

#[Service]
class UserService {
    use Wlf4p;

    #[Autowired]
    private PdbcTemplate $pdbc;

    #[Autowired]
    private PlatformTransactionManager $txnMgr;

    public function createUser(User $user): User
    {
        $status = $this->txnMgr->getTransaction(new DefaultTransactionDefinition());
        try {
            $sql = "INSERT INTO users (name, email, age) VALUES (:name, :email, :age) RETURNING id";
            $ret = [];
            $result = $this->pdbc->update($sql, [
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'age' => $user->getAge()
            ], [], $ret);

            if ($result) {
                $user->setId(intval($ret['id']));
            }

            $this->txnMgr->commit($status);
            return $user;
        } catch (\Throwable $e) {
            $this->txnMgr->rollback($status);
            throw $e;
        }
    }

    public function updateUser(User $user): User
    {
        $status = $this->txnMgr->getTransaction(new DefaultTransactionDefinition());
        try {
            $this->pdbc->updateObjects($user);
            $this->txnMgr->commit($status);
            return $user;
        } catch (\Throwable $e) {
            $this->txnMgr->rollback($status);
            throw $e;
        }
    }

    public function deleteUser(int $id): bool
    {
        $status = $this->txnMgr->getTransaction(new DefaultTransactionDefinition());
        try {
            $user = $this->findById($id);
            if ($user) {
                $this->pdbc->deleteObjects($user);
                $this->txnMgr->commit($status);
                return true;
            }
            $this->txnMgr->commit($status);
            return false;
        } catch (\Throwable $e) {
            $this->txnMgr->rollback($status);
            throw $e;
        }
    }

    public function findById(int $id): ?User
    {
        $sql = "SELECT * FROM users WHERE id = :id";
        try {
            return $this->pdbc->queryForObject($sql, ['id' => $id], User::class);
        } catch (EmptyResultDataAccessException $e) {
            return null;
        }
    }

    public function findAll(): array
    {
        $sql = "SELECT * FROM users ORDER BY id";
        return $this->pdbc->queryForObjects($sql, [], User::class);
    }

    public function findByEmail(string $email): ?User
    {
        $sql = "SELECT * FROM users WHERE email = :email";
        try {
            return $this->pdbc->queryForObject($sql, ['email' => $email], User::class);
        } catch (EmptyResultDataAccessException $e) {
            return null;
        }
    }
}
```

### TransactionStatus

[`TransactionStatus`](src/txn/TransactionStatus.php) represents the state of an active transaction:

| Method | Returns | Description |
|--------|---------|-------------|
| `isNewTransaction()` | `bool` | Whether this is a newly created transaction |
| `isCompleted()` | `bool` | Whether the transaction has been committed or rolled back |
| `isRollbackOnly()` | `bool` | Whether the transaction is marked for rollback only |
| `hasTransaction()` | `bool` | Whether an actual transaction is active |
| `hasSavepoint()` | `bool` | Whether a savepoint has been created |
| `getTransaction()` | `?TransactionObject` | The underlying transaction object |


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







