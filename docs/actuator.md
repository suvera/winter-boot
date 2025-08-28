# Winter Boot Actuator: Gain Deep Insights into Your Applications!

The Winter Boot Actuator is your go-to feature for monitoring and interacting with your running application. It exposes crucial operational information—like health, metrics, environment details, and more—through easily accessible HTTP endpoints. Get ready to observe and manage your microservices with unprecedented clarity!

## Unleash the Power of Actuator Endpoints: Configuration

To harness the full potential of the Actuator, you need to enable its HTTP endpoints in your `application.yml`. By default, all endpoints are disabled, giving you complete control over what information is exposed.

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

**Accessing an Endpoint:**

Once configured, you can easily query any enabled endpoint using a simple `curl` command:

```shell

curl https://your.service.domain/acme/health

```

# Monitor Application Health with `HealthIndicator`

Keep a pulse on your application's well-being! The health endpoint, powered by classes annotated with `#[HealthInformer]`, allows you to expose the current health status of various components within your application.

#### Example: Custom Database Health Check

Easily define custom health checks for your critical services, like your database connection.

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


# Provide Application Information with `InfoContributor`

Share vital application details through the info endpoint! By creating classes annotated with `#[InfoInformer]`, you can contribute custom information about your application, such as its name, version, or any other relevant metadata.

This information will be beautifully exposed via the `/info` endpoint.

```
curl https://your.service.domain/acme/info
```


#### Example: Custom Application Info

Add custom details to your application's info endpoint, providing valuable context for operations and monitoring.

```phpt

#[InfoInformer]
class ApplicationInfoInformer implements InfoContributor {

    public function contribute(InfoBuilder $info): void {
        $info->withDetail('appName', 'ExampleMicroService')
            ->withDetail('appVersion', '1.0.0-1');

    }
}

```