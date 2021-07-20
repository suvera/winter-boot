# Actuator

Actuator is mainly used to expose operational information about the running application — health, metrics, info, dump, env, etc. It uses HTTP endpoints to enable us to interact with it.

## Configuration

Http endpoints must be enabled in the application.yml.
By default, all endpoints are disabled.


```yaml

management:
    endpoints:
        enabled: true
    endpoint:
        beans:
            enabled: true,
            path: "acme/beans"
        configprops:
            enabled: true,
            path: "acme/configprops"
        env:
            enabled: true,
            path: "acme/env"
        health:
            enabled: true,
            path: "acme/health"
        info:
            enabled: true,
            path: "acme/info"
        mappings:
            enabled: true,
            path: "acme/mappings"
        scheduledtasks:
            enabled: true,
            path: "acme/scheduledtasks"
        heapdump:
            enabled: true,
            path: "acme/heapdump"
```

Calling an endPoint

```shell

curl https://your.service.domain/acme/health

```

# HealthInformer

Health of application can be exposed via **health** endPoint, and by building classes 
that are annotated with **#[HealthInformer]**

#### Example:

```phpt

#[HealthInformer]
class DatabaseHealthIndicator implements HealthIndicator {

    #[Autowired]
    private PdbcTemplate $pdbc;

    public function health(): Health {
        $success = $this->pdbc->queryForScalar('select 1 from dual');

        return $success ? Health::up() : Health::down()->withDetail('database', 'down');
    }
}

```


# InfoInformer

Application information can be reported by a class annotated with **#[InfoInformer]**

This information will be exposed via **info** endPoint.

```
curl https://your.service.domain/acme/info
```


#### Example:

```phpt

#[InfoInformer]
class ApplicationInfoInformer implements InfoContributor {

    public function contribute(InfoBuilder $info): void {
        $info->withDetail('appName', 'ExampleMicroService')
            ->withDetail('appVersion', '1.0.0-1');

    }
}

```