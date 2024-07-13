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

Check the PdbcTemplate interface for the available methods.


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
public function executeInTransaction(): void {
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







