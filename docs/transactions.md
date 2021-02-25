# Transaction Management

Framework comes-up with a annotation to manage transactions.



## Transactional

Attribute **#[Transactional]** allowed on methods that want to be executed within a transaction.

Framework has a default PdoTransactionManager (derived from PlatformTransactionManager ) that handles the transactions.
Nested transactions are not allowed by the Pdo.


If you want to extend and create your own transaction manager, then implement PlatformTransactionManager interface and create your manager.


#### Examples

```phpt

#[Transactional]
public function executeInTransaction(): void {
    // do something here
}



// with a custom Transaction Manager
// You need to define a bean that returns PlatformTransactionManager

#[Bean("myTxnMgr")]
public function getMyTransactionManager(DataSource $dataSource): PlatformTransactionManager {
    return new MyTransactionManager($dataSource);
}



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







