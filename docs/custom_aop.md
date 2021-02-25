# Custom StereoTypes with Aspect Oriented Magic

Create custom StereoTypes and Attributes

# StereoTyped

This Attribute **#[StereoTyped]** gives ability to extend the framework to some other level.

- You can define your own AOP type of Attributes

Two things are needed to create a AOP type Attribute

1) Attribute class - Must implement **AopStereoType**
2) AOP Interceptor - Must implement **WinterAspect**

Framework already provides many AOP type of Attributes

- Locking
    - Attribute [Lockable.php](/src/stereotype/concurrent/Lockable.php)
    - Interceptor [LockableAspect.php](/src/util/concurrent/LockableAspect.php)
- Caching
    - Attribute [Cacheable.php](/src/cache/stereotype/Cacheable.php)
    - Interceptor [CacheableAspect.php](/src/cache/aop/CacheableAspect.php)
- Transactions
    - Attribute [Transactional.php](/src/txn/stereotype/Transactional.php)
    - Interceptor [TransactionalAspect.php](/src/txn/aop/TransactionalAspect.php)





# Example

Let's build an example to check whether user role is **Admin** then allow method execution


## Attribute:  MustBeAdmin
```phpt


#[Attribute(Attribute::TARGET_METHOD)]
#[StereoTyped]
class MustBeAdmin implements AopStereoType {
    use StereoTypeValidations;
    
    private MustBeAdminInterceptor $interceptor;

    public function isPerInstance(): bool {
        return false;
    }

    public function getAspect(): WinterAspect {
        if (!isset($this->interceptor)) {
            $this->interceptor = new MustBeAdminInterceptor();
        }
        return $this->interceptor;
    }

    public function init(object $ref): void {
        /** @var RefMethod $ref */
        TypeAssert::typeOf($ref, RefMethod::class);
        
        $this->validateAopMethod($ref, 'MustBeAdmin');
    }

}


```

Now code for Interceptor


## Interceptor:  MustBeAdminInterceptor


```phpt


class MustBeAdminInterceptor implements WinterAspect {
    use Wlf4p;

    public function begin(AopContext $ctx, AopExecutionContext $exCtx): void {
        
        // This is your code, your classes
        $user = UserAuthenticationContext::currentLoggedInUser();
        
        if (is_null($user) || !$user->isAdmin()) {
            $exCtx->stopExecution(null);
            throw new SomeException('User is not an Admin');
        }
    }

    public function beginFailed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
        // Log incident
        self::logError('MustBeAdmin failed for method ' . $ctx->getMethod()->getName());
    }

    public function commit(AopContext $ctx, AopExecutionContext $exCtx, mixed $result): void {
        // nothing to commit
    }

    public function commitFailed(AopContext $ctx, AopExecutionContext $exCtx, mixed $result, Throwable $ex): void {
        // nothing to commit
    }

    public function failed(AopContext $ctx, AopExecutionContext $exCtx, Throwable $ex): void {
        // Log incident
        self::logError('MustBeAdmin failed');
    }

}

```


## Your services/components

Now in your services and components, you can do something like this.


```phpt

#[Service]
class MyService {

    #[MustBeAdmin]
    public function doBackendOperation(): void {
        // admin only operation, this method won't be exceuted witout Admin role.
    }

}


```

