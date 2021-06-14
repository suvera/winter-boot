# Locking

Synchronized access to a resource, i.e. only one process can execute a method at any time (across a node, or cluster)

## Local Locking

Only one process can execute the method at a time at node level.

Framework out-of-box support this type of locking.

Annotation **#[Lockable]** used for this purpose

#### Example: Node level locking

```phpt

#[Lockable(name: "order-#{id}")]
public function updateOrderStatus(int $id): void {
}

```

No two processes in same node can execute **updateOrderStatus** per OrderId.

## Distributed Locking

Only one process across cluster of nodes can execute the method at a time.

Following technologies allows distributed locking

- Redis Lock
- Database Locks
- Apache ZooKeeper
- Consul
- etc...

```phpt

#[Lockable(name: "order-#{id}", lockManager: "redisLockManager")]
public function updateOrderStatus(int $id): void {
}
// No two processes in acorss cluster of nodes can execute **updateOrderStatus** per OrderId.




// Bean that provides redis LockManager

#[Bean("redisLockManager")]
public function  getRedisLockManager(): LockManager {
    // return LockManager object
}

```

### **Symfony Framework**

Symfony Framework has Lock component that supports many backend stores.

- https://symfony.com/doc/current/components/lock.html



## Attribute Options

Attribute **#[Lockable]** has more options.

Name | Required | Default Value | Description
------------ | ------------ | ------------ | ------------
name | Yes | | Name of the Lock, must be unique
lockManager |  |  | Bean name, that implements LockManager
waitMilliSecs |  | 0 | Wait for milli seconds to get the Lock, then fails.
ttlSeconds |  | Unlimited | Time for Lock to Live


## LockException

**LockException** will be thrown whenever Lock cannot be acquired any process.


