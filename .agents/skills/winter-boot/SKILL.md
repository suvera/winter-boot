---
name: winter-boot
description: Build PHP 8.5 microservices with Winter Boot (Swoole) — DI, REST, DB, caching, async, modules.
---

# Winter Boot

Spring-Boot-style PHP 8.5 framework. Requires PHP 8.5 + `swoole` extension (`pecl install swoole`, enable `extension=swoole.so`). Install via `composer require suvera/winter-boot` (+ `suvera/winter-modules`, `suvera/winter-doctrine` as needed). Run web apps with `WinterWebSwooleApplication`.

## 1. Application Starter

One class per entry point, annotated `#[WinterBootApplication(configDirectory:[], scanNamespaces:[], scanExcludeNamespaces:[], autoload:false, eager:false)]`. `configDirectory` holds `application.yml`. Use separate starters for web/CLI/test.

```php
#[WinterBootApplication(configDirectory: ['config'])]
class MyApplication {
    public static function main() { (new WinterWebSwooleApplication())->run(self::class); }
}
MyApplication::main();
```

Custom module: extend `dev\winterframework\core\app\WinterModule`.

## 2. DI Stereotypes

- `#[Service]` / `#[Component]` (optional name, unique) on class; `#[Configuration]` on config class; `#[Bean]` / `#[Bean("name")]` on factory method (method params autowired).
- `#[Autowired]` / `#[Autowired("beanName")]` on property. `ApplicationContext` itself is autowirable: `beanByClass()`, `beanByName()`, `beanByNameClass()`, `hasBeanByClass/Name()`, `getProperty()/getPropertyStr/Bool/Int/Float()`, `getProperties()`.
- `#[Value('${myApp.db.host}')]` injects `application.yml` property.

## 3. Configuration (`application.yml` in config dir)

Top keys: `server: {port, address, context-path}`, `winter: {application:{name,id,version}, task:{async,scheduling}, kv, queue}`, `datasource: [...]`, `multitenant-datasource: [...]`, `modules: [...]`, `management: {endpoints...}`, `propertySources: [...]`.
Bean config: `#[Configuration]` class with `#[Bean]` methods (e.g. DataSource, PlatformTransactionManager, CacheManager). Custom property source: implement `dev\winterframework\io\PropertySource` (`has/get/getAll`), register under `propertySources:` with `provider:`, reference as `$env.VAR`, `$ini.key`.

## 4. REST API

- `#[RestController]` class + `#[Autowired]` services. Routes: `#[RequestMapping(path, method:[RequestMethod::GET|POST|PUT|DELETE|PATCH], name, consumes:[MediaType::APPLICATION_JSON])]` on class (base URI) and method. Shorthands `#[GetMapping(path:...)]`, `#[PostMapping]`, `#[PutMapping]`, `#[DeleteMapping]`, `#[PatchMapping]`.
- Params: `#[PathVariable]` (URI `/users/{id}`), `#[RequestParam(name, required:true, source:'request', defaultValue)]` where `source` = `request` (query+POST) | `get` | `post` | `cookie` | `header`; `#[RequestBody]` whole body (JSON/XML/urlencoded) into class.
- Returns: `array` → JSON, `string` → text, `ResponseEntity` for control (`ResponseEntity::ok()->withJson($x)`, `::notFound()->build()`).
- JSON/XML mapping: `#[JsonProperty(name, required)]`, `#[XmlElement]`, `#[XmlAttribute]`, `#[XmlValue]`, `#[XmlRootElement]`, `#[XmlAnyElement/Attribute]`, `#[XmlPropertyOrder]`; manual `ObjectCreator::createObject($cls,$arr)` / `createObjectXml($cls,$xml)`.
- Errors: `#[Component('errorController')] class X implements ErrorController` or `#[Bean('errorController')]` factory.
- Interceptors: controller-level `implements ControllerInterceptor` (`preHandle($req,$res,$handler):bool`, `postHandle(...)` skipped on exception); app-level `implements HandlerInterceptor` (`preHandle:bool` false stops chain, `postHandle`, `afterCompletion($req,$res,$ex=null)`), registered in `#[Configuration(name:"webMvcConfigurer")] class C implements WebMvcConfigurer { addInterceptors($r){ $r->addInterceptor(new X(), '.*'); } }`.

## 5. Database, Transactions, Doctrine

- `datasource: [{name, isPrimary:true, url, username, password, validationQuery, driverClass, connection:{persistent, errorMode, columnsCase, idleTimeout, autoCommit, defaultrowprefetch}, migrations:{enabled,useCli}}]`.
- `#[Autowired] PdbcTemplate $pdbc` (primary); `#[Autowired("admindb-template")]` for named (`<ds>-template`). Methods: `execute`, `query($sql,$binds,$processor)`, `queryForList/Map/Scalar`, `queryForObject/Objects($sql,$binds,Class::class)`, `update`, `batchUpdate`, `updateObjects(...$ppa)`, `deleteObjects`. Binds: positional `?`, named `:n`, or `BindVars()->add('k',$v,BindType::INT)`.
- PPA lightweight ORM: `#[Table("users")] class U implements PpaEntity { use PpaEntityTrait; }`.
- Enable `#[EnableTransactionManagement]` on app; `#[Transactional]` / `#[Transactional("admindb-txn")]` / `#[Transactional(transactionManager:"myTxnMgr", propagation, readOnly, rollbackFor, noRollbackFor)]` (`<ds>-txn` naming). Custom mgr: `#[Bean]` returning `PlatformTransactionManager`. Programmatic: `$st=$mgr->getTransaction(new DefaultTransactionDefinition()); try{...;$mgr->commit($st);}catch(\Throwable $e){$mgr->rollback($st);throw $e;}`.
- Doctrine module (`composer require suvera/winter-doctrine`, `modules: [{module: dev\winterframework\doctrine\DoctrineModule, enabled:true}]`, per-datasource `doctrine:{entityPaths:[], isDevMode:false}`): beans `<ds>-doctrine-em` (EntityManager), `<ds>-doctrine-emtxn`, `<ds>-doctrine-dbal`, `<ds>-doctrine-dbaltxn`; `#[Transactional(transactionManager:"admindb-doctrine-emtxn")]`. Multi-tenant: `multitenant-datasource: [{name, providerClass}]` implementing `TenantDataSourceProvider`, mgr bean `<name>-manager`.

## 6. Cross-cutting

- Caching: `#[EnableCaching]` on app; `#[Cacheable(cacheNames:"n", key, keyGenerator, cacheManager, cacheResolver)]`, `#[CachePut]` (always run+store, never with Cacheable), `#[CacheEvict(cacheNames, key, allEntries:false, beforeInvocation:false)]`. Managers: default in-memory; `SharedKvCache($kvTemplate,$name,CacheConfiguration::get(maximumSize,expireAfterWriteMs))` via `SimpleCacheManager`, or `RedisCache` (redis module) with `#[Bean("redisCacheManager")]`.
- Async/scheduling (needs Swoole): `#[EnableAsync]`, `#[EnableScheduling]` on app; `#[Async]` on service/component method; `#[Scheduled(fixedDelay:20, initialDelay:10)]`. Tune `winter.task.async:{poolSize,queueCapacity,argsSize,queueStorage.handler}` and `winter.task.scheduling:{poolSize,queueCapacity}` in yml.
- Daemon: `#[DaemonThread(name, coreSize:1)] class X extends ServerWorkerProcess` with `getProcessType()/getProcessId()/run()` loop + `\Co::sleep()`; needs Swoole; auto-restarted.
- Locking: `#[Lockable(name:"order-#{id}", lockManager:"redisLockManager", waitMilliSecs:0, ttlSeconds)]`; `LockException` on failure; distributed via `LockManager` bean (e.g. Redis, Symfony Lock).
- Logging (Monolog via monolog-cascade): add `use dev\winterframework\util\log\Wlf4p;` to any class, then `self::logInfo('msg')` or `$this->logInfo('msg')`. Methods: `logDebug/Info/Notice/Warning/Error/Critical/Alert/Emergency($msg,$ctx=[])`, `logEx($e,$msg='')`, `logException($e,$msg='')`. Example: `class Foo { use Wlf4p; public function bar(): void { self::logInfo('in bar'); } }`. Config file `logger.yml` in config dir: `loggers: {myLogger: {handlers:[console], processors:[], custom_level:{'ns\Class': DEBUG}}}` + `handlers: {console: {class: Monolog\Handler\StreamHandler, level: DEBUG, stream: php://stdout}}` + `formatters/processors`; `custom_level` per class/namespace prefix (longest wins, `NONE` silences).
- Actuator: `management:{endpoints:{enabled:true}, endpoint:{health:{enabled:true, path:"acme/health"}, beans, configprops, env, info, mappings, scheduledtasks, heapdump}}`; custom `#[HealthInformer] class X implements HealthIndicator { health(): Health }` (`Health::up()/down()->withDetail()`), `#[InfoInformer] class X implements InfoContributor { contribute(InfoBuilder $b) }`.
- Migrations: enable `migrations:{enabled:true, useCli:false}` per datasource/tenant/opensearch conn; layout `/migrations/{dsName}/001-x.sql` (alpha order, subfolders ok), tracked in `winter_migrations` table (SQL) or index (OpenSearch); run `php bin/migrate.php -c <config> --sqlPath <migrations> [-m sql|opensearch]` or built PHAR (`build/sqlmigrator/build.sh`); first failure stops, no auto-rollback. OpenSearch files: `*-template.json` → index template, `*-policy.json` → ISM policy, else content-driven (index_patterns/policy/settings+mappings); `{name}` = filename minus suffix, lowercased; re-run skips unchanged hash, re-applies changed.
- Telemetry (needs `opentelemetry` ext + SDK, allow `tbachert/spi` plugin): `modules: [{module:'dev\winterframework\telemetry\OpenTelemetryModule', enabled:true, configFile: opentelemetry.yaml}]` with `winter.telemetry:{serviceName, exporter:{type:otlp, endpoint}, sampler:{type,ratio}}`; web tracing via `OpenTelemetryWebInterceptor` in `WebMvcConfigurer`; `#[Traceable]` methods, `#[Countable(name,value)]` or autowired `OpenTelemetryMetrics->record()`, `OpenTelemetryLogs->info()`.
- Local stores: `winter.kv:{port:7880,address}` + autowired `KvTemplate->put/get($domain,$k,$v)`; `winter.queue:{port:7881}` + `QueueSharedTemplate->enqueue/dequeue($q,$v)`.
- Custom AOP: attribute `#[Attribute(TARGET_METHOD)] #[StereoTyped] class X implements AopStereoType { getAspect(): WinterAspect; init($ref); isPerInstance(); }` + `class Y implements WinterAspect { begin/beginFailed/commit/commitFailed/failed(AopContext $c, AopExecutionContext $e, ...) }` (call `$e->stopExecution(null)` to block).
- Utils: `bombok\Data` trait (auto get/set + type check), `Objects::hash()`, `ArrayList/StringList::ofValues/ofArray/emptyList()`, `ImmutableMap::of([...])`, `IntegerMinHeap`, `TypeAssert::string/integer/.../typeOf/notNull/state()`, `MurmurHash3Provider`.
- Build: box-project PHAR (`https://github.com/box-project/box`), Dockerfile + build.xml pattern in winter-example-service.

## 7. Modules (`composer require suvera/winter-modules`, `modules: [{module, enabled:true, configFile}]`)

- Redis (`RedisModule`, needs phpredis ext): `PhpRedisTemplate` (`phpredis.singles:[{name,host,port}]`), `PhpRedisClusterTemplate` (`phpredis.clusters:[{name,clusterName,hosts:[]}]`); also `RedisCache`, async queue store `AsyncRedisQueueStore`.
- Memcache (`MemcacheModule`, needs memcache/memcached ext): `MemcacheTemplate`/`MemcachedTemplate` via `memcache-config.yml`.
- Kafka (`KafkaModule`, needs swoole+rdkafka): `kafka-config.yml` (`bootstrap.servers`, `consumers:[{name,topics,workerNum,workerClass}]`, `producers:[{name,topic,...}]`); autowired `KafkaService->produce()`; consumer workers auto-start.
- DTCE (`DtceModule`, needs swoole; redis/kafka optional backends): Task Queue + Store + Worker; persistent backends survive restarts.
- S3 (`S3Module` + `aws/aws-sdk-php`): `s3-config.yml` (`s3:[{name,region,version,endpoint,retries,credentials:[{key,secret,token}]}]`, omit credentials for IAM-role chain); autowired `S3Template`.
- SQS (`SqsModule` + `aws/aws-sdk-php`, PSR-4 `dev\winterframework\sqs\` → `winter-sqs/src/`): `sqs-config.yml` (`sqs.connections:[{name,region,version,retries,credentials}]`); send/receive like Kafka/S3.
- OpenSearch (`OpenSearchModule`): `opensearch-config.yml` (`opensearch:[{name,hosts:[],username,password,ssl_verification,timeout,proxy,aws:{...SigV4},migrations:{enabled}}]`); autowired `OpenSearchTemplate`.
- Doctrine (separate package, see §5).
