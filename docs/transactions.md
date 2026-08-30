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
        migrations:
            enabled: false
        doctrine:
            entityPaths:
                - /path/to/defaultdb/entities
            isDevMode: false

    -   name: admindb
        url: "mysql:host=localhost;port=3307;dbname=testdb"
        username: xxxxx
        password: xxzzz
        migrations:
            enabled: true
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

> **Note:** The `-template` suffix is part of the framework's automatic bean naming convention for each configured datasource (`<datasource-name>-template`). It is only required when injecting a specific non-primary `PdbcTemplate` to disambiguate between multiple datasource templates in the container. For a single or primary datasource, `#[Autowired]` without a name or suffix is sufficient.

### PPA (PHP Persistence API)

PPA is Winter Framework's lightweight ORM layer (analogous to JPA in Java). Entity classes annotated with [`#[Table]`](src/stereotype/ppa/Table.php) implement [`PpaEntity`](src/ppa/PpaEntity.php) (or use [`PpaEntityTrait`](src/ppa/PpaEntityTrait.php)) and are automatically mapped to database rows. The framework generates INSERT/UPDATE/DELETE SQL and maps query results to PHP objects.

```phpt
```
#[Table("users")]
class User implements PpaEntity {
    use PpaEntityTrait;

    private int $id;
    private string $name;
    private string $email;
    // getters/setters...
}
```

### Using `BindVars`

In addition to standard arrays (both positional `?` and named `:name`), you can use the `BindVars` collection class to build typed query parameters explicitly:

```phpt
use dev\winterframework\pdbc\core\BindVars;
use dev\winterframework\pdbc\core\BindType;

$binds = (new BindVars())
    ->add('status', 'active')
    ->add('minAge', 18, BindType::INT);

$users = $this->pdbc->queryForObjects(
    "SELECT * FROM users WHERE status = :status AND age >= :minAge", 
    $binds, 
    User::class
);
```

### PdbcTemplate Methods
#### `execute`

Execute a SQL statement with optional bind variables and an optional callback that receives the `PreparedStatement`.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$sql` | `string` | The SQL statement to execute |
| `$bindVars` | `array\|BindVars` | Optional bind variables |
| `$action` | `PreparedStatementCallback\|null` | Optional callback receiving the `PreparedStatement` |

| Output | Type | Description |
|--------|------|-------------|
| Return | `mixed` | Result of execution or callback |

**Example:**
```phpt
$affected = $this->pdbc->execute(
    "INSERT INTO users (name, email) VALUES (?, ?)", 
    ["Alice", "alice@example.com"]
);
```

---

#### `query`

Execute a query and process results via a callback, `ResultSetExtractor`, `RowCallbackHandler`, or `RowMapper`.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$sql` | `string` | The SQL query string |
| `$bindVars` | `array\|BindVars` | Bind variables |
| `$processor` | `callable\|ResultSetExtractor\|RowCallbackHandler\|RowMapper` | Result processor |

| Output | Type | Description |
|--------|------|-------------|
| Return | `mixed` | Processed result |

**Example:**
```phpt
$names = $this->pdbc->query(
    "SELECT name FROM users WHERE active = ?", 
    [1], 
    fn($rs) => $rs->getString('name')
);
```

---

#### `queryForList`

Returns all rows as an array of associative arrays: `[ ['col' => 'val', ...], ... ]`.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$sql` | `string` | The SQL query string |
| `$bindVars` | `array\|BindVars` | Optional bind variables |

| Output | Type | Description |
|--------|------|-------------|
| Return | `array` | Array of associative rows |

**Example:**
```phpt
$rows = $this->pdbc->queryForList("SELECT * FROM users WHERE status = ?", ["active"]);
foreach ($rows as $row) {
    echo $row['name'];
}
```

---

#### `queryForMap`

Returns a single row as an associative array. Throws an exception if the result is not exactly one row.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$sql` | `string` | The SQL query string |
| `$bindVars` | `array\|BindVars` | Optional bind variables |

| Output | Type | Description |
|--------|------|-------------|
| Return | `array` | Single row as an associative array |

**Example:**
```phpt
$user = $this->pdbc->queryForMap("SELECT * FROM users WHERE id = ?", [1]);
echo $user['email'];
```

---

#### `queryForScalar`

Returns a single scalar value from the first column of the first row.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$sql` | `string` | The SQL query string |
| `$bindVars` | `array\|BindVars` | Optional bind variables |

| Output | Type | Description |
|--------|------|-------------|
| Return | `int\|string\|float\|bool\|null` | Scalar value |

**Example:**
```phpt
$count = $this->pdbc->queryForScalar("SELECT COUNT(*) FROM users");
echo "Total users: " . $count;
```

---

#### `queryForObject`

Returns a single row mapped to an object via `RowMapper` or class name.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$sql` | `string` | The SQL query string |
| `$bindVars` | `array\|BindVars` | Bind variables |
| `$classOrMapper` | `string\|RowMapper\|null` | Target class name or row mapper |

| Output | Type | Description |
|--------|------|-------------|
| Return | `object` | Mapped object instance |

**Example:**
```phpt
$userObj = $this->pdbc->queryForObject(
    "SELECT id, name, email FROM users WHERE id = ?", 
    [1], 
    User::class
);
```

---

#### `queryForObjects`

Returns an array of PPA entity objects populated from the query result. `$ppaClass` is the fully-qualified class name of a PPA entity (e.g., `User::class`).

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$sql` | `string` | The SQL query string |
| `$bindVars` | `array\|BindVars` | Bind variables |
| `$ppaClass` | `string` | Fully-qualified PPA entity class name |

| Output | Type | Description |
|--------|------|-------------|
| Return | `array` | Array of PPA entity objects |

**Example:**
```phpt
$users = $this->pdbc->queryForObjects(
    "SELECT id, name, email FROM users WHERE status = ?", 
    ["active"], 
    User::class
);
```

---

#### `update`

Execute an INSERT/UPDATE/DELETE statement. Returns number of affected rows. Supports OUT bind variables and generated keys.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$sql` | `string` | The SQL statement |
| `$bindVars` | `array\|BindVars` | Bind variables |
| `$outBindVars` | `array\|OutBindVars` | Optional OUT bind variables |
| `$generatedKeys` | `array` | Optional reference array for generated keys |

| Output | Type | Description |
|--------|------|-------------|
| Return | `int` | Number of affected rows |

**Example:**
```phpt
$affected = $this->pdbc->update(
    "UPDATE users SET email = ? WHERE id = ?", 
    ["newemail@example.com", 1]
);
```

---

#### `batchUpdate`

Execute a batch of updates. `$arrayBindVars` is an array of bind-variable arrays, one per batch entry.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$sql` | `string` | The SQL batch statement |
| `$arrayBindVars` | `array` | Array of bind-variable arrays |

| Output | Type | Description |
|--------|------|-------------|
| Return | `array` | Batch update results |

**Example:**
```phpt
$batchParams = [
    ["Alice", "alice@example.com"],
    ["Bob", "bob@example.com"]
];
$results = $this->pdbc->batchUpdate(
    "INSERT INTO users (name, email) VALUES (?, ?)", 
    $batchParams
);
```

---

#### `updateObjects`

Create or update PPA entity objects in the database. Each argument must implement [`PpaEntity`](src/ppa/PpaEntity.php).

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `...$ppaObjects` | `object` | PPA entity objects to create or update |

| Output | Type | Description |
|--------|------|-------------|
| Return | `void` | None |

**Example:**
```phpt
$user = new User();
$user->setName("Charlie");
$user->setEmail("charlie@example.com");

$this->pdbc->updateObjects($user);
```

---

#### `deleteObjects`

Delete PPA entity objects from the database. Each argument must implement [`PpaEntity`](src/ppa/PpaEntity.php).

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `...$ppaObjects` | `object` | PPA entity objects to delete |

| Output | Type | Description |
|--------|------|-------------|
| Return | `void` | None |

**Example:**
```phpt
$user = $this->pdbc->queryForObject("SELECT * FROM users WHERE id = ?", [1], User::class);
$this->pdbc->deleteObjects($user);
```


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

> **Note:** The `-txn` suffix is part of the framework's automatic bean naming convention for each configured datasource's transaction manager (`<datasource-name>-txn`). It is only required when specifying a non-primary transaction manager via `#[Transactional("...")]` to target a specific secondary datasource. For the primary datasource or default transaction manager, plain `#[Transactional]` is sufficient.

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

Winter Boot provides native support for multi-tenancy via `MultiTenantManager` and `TenantDataSourceProvider`, allowing per-tenant `PdbcTemplate` and `PlatformTransactionManager` instances.

## Configuration via `application.yml`

You can configure multi-tenant data sources directly in your `application.yml` by registering a provider class:

```yaml
multitenant-datasource:
    - name: "tenantdb"
      url: "mysql:host=localhost;port=3306"
      providerClass: "App\\Config\\MyTenantDataSourceProvider"
```

When configured, Winter Boot automatically initializes and registers a `MultiTenantManager` bean named `<name>-manager` (e.g. `tenantdb-manager`) in the application context.


## Step 1: Implement TenantDataSourceProvider

Create a `#[Configuration]` class that returns your implementation of [`TenantDataSourceProvider`](src/pdbc/multitenant/TenantDataSourceProvider.php).

```phpt
package App\Config;

#[Configuration]
class MyTenantDataSourceProvider {

    #[Autowired("admindb-template")]
    private PdbcTemplate $adminPdbc;

    public function getTenantDataSourceConfig(string $tenantId): DataSourceConfig {
        // You need to define Your YourTenantEntity class as PpaEntity
        // @var YourTenantEntity $tenant
        $tenant = $this->adminPdbc->queryForObject("SELECT * FROM tenants WHERE tenant_id = :tid", 
        ['tid' => $tenantId], YourTenantEntity::class);
        $dbHost = $tenant->dbHost;
        $dbPort = $tenant->dbPort;
        $username = $tenant->username;
        $dbName = $tenant->database;
        
        $config = new DataSourceConfig();
        $config->setName($tenantId);
        $config->setUrl("mysql:host=$dbHost;port=$dbPort;dbname=$dbName");
        $config->setUsername($username);
        $config->setPassword("tenant_pass");
        // Optional settings:
        // $config->setPersistent(true);
        // $config->setAutoCommit(true);
        // $config->setTimeoutSecs(30);
        // $config->setIdleTimeout(600);
        
        return $config;
    }

    public function getTenantDataSourceConfigs(int $offset, int $limit): array {
        $tenants = $this->adminPdbc->queryForObjects("SELECT * FROM tenants WHERE status = 'Active' LIMIT :offset, :limit", 
            ['offset' => $offset, 'limit' => $limit], YourTenantEntity::class);
        
        $dbConfigs = [];
        foreach ($tenants as $tenant) {
            $config = new DataSourceConfig();
            $config->setName($tenant->tenantId);
            $config->setUrl("mysql:host={$tenant->dbHost};port={$tenant->dbPort};dbname={$tenant->database}");
            $config->setUsername($tenant->username);

            // ... other settings here ...

            $dbConfigs[] = $config;
        }
        return $dbConfigs;
    }
}
```

## Step 2: Use in Business Classes

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

    #[Autowired("regionDb-manager")]
    private MultiTenantManager $regionMt;

    #[Autowired("productDb-manager")]
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

The `MultiTenantManager` class manages tenant-specific data sources, `PdbcTemplate` instances, and transaction managers.

#### `getTenantDataSourceProvider`

Returns the configured tenant data source provider instance.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| None | - | - |

| Output | Type | Description |
|--------|------|-------------|
| Return | [`TenantDataSourceProvider`](src/pdbc/multitenant/TenantDataSourceProvider.php) | The underlying tenant datasource provider |

---

#### `getPdbcTemplate`

Returns a cached per-tenant `PdbcTemplate` backed by a tenant-specific `PdoDataSource`.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$tenantId` | `string` | The unique identifier of the tenant |

| Output | Type | Description |
|--------|------|-------------|
| Return | [`PdbcTemplate`](src/pdbc/PdbcTemplate.php) | Per-tenant PdbcTemplate instance |

---

#### `getTransactionManager`

Returns a cached per-tenant `PlatformTransactionManager` backed by the tenant-specific `PdoDataSource`.

| Input Parameter | Type | Description |
|-----------------|------|-------------|
| `$tenantId` | `string` | The unique identifier of the tenant |

| Output | Type | Description |
|--------|------|-------------|
| Return | [`PlatformTransactionManager`](src/txn/PlatformTransactionManager.php) | Per-tenant transaction manager instance |

