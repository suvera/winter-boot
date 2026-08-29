# OpenTelemetry: Observe Your Winter Boot Applications!

Winter Boot offers seamless, pluggable support for **OpenTelemetry** (OTel), empowering you to gain deep observability into your microservices across all three pillars: **Traces**, **Metrics**, and **Logs**.

---

## ⚠️ Prerequisites & Extension Requirement

The OpenTelemetry module requires the **OpenTelemetry PHP extension** and SDK packages to be installed in your environment. If the extension is not loaded, the module will throw a `MissingExtensionException` upon initialization.

### 1. Install the PHP Extension
Ensure the `opentelemetry` extension is installed and enabled in your `php.ini`:
```ini
extension=opentelemetry.so
```

### 2. Install Composer Dependencies
Add the required OpenTelemetry packages to your `composer.json`:
```bash
composer require open-telemetry/opentelemetry open-telemetry/api open-telemetry/exporter-otlp
```

> **Note on Composer Plugins (`tbachert/spi`)**:
> When installing `open-telemetry/opentelemetry`, Composer may prompt you about `tbachert/spi`:
> ```text
> tbachert/spi contains a Composer plugin which is currently not in your allow-plugins config.
> Do you trust "tbachert/spi" to execute code and wish to enable it now?
> ```
> The OpenTelemetry SDK uses this plugin to provide its extensible configuration and Service Provider Interface (SPI) autoconfiguration. You should allow it when prompted or add it to your `composer.json`:
> ```json
> "config": {
>     "allow-plugins": {
>         "tbachert/spi": true
>     }
> }
> ```

---

## 3. Getting Started

To enable OpenTelemetry in your project, add the module to your `modules` configuration in `application.yml`:

```yaml
modules:
    -   module: 'dev\winterframework\telemetry\OpenTelemetryModule'
        enabled: true
        configFile: /path/to/opentelemetry.yaml
```

---

## 4. Configuration

Configure your OTel module in `opentelemetry.yaml` (or the path defined in your module configuration):

```yaml
winter:
    telemetry:
        serviceName: 'my-winter-application'
        exporter:
            type: 'otlp'
            endpoint: 'http://localhost:4317'
        sampler:
            type: 'parent_based_always_on'
            ratio: 1.0
```

---

## 5. Web Request Tracing

Automatically trace incoming HTTP requests by registering the `OpenTelemetryWebInterceptor` in your `WebMvcConfigurer` implementation:

```phpt
use dev\winterframework\telemetry\web\OpenTelemetryWebInterceptor;

#[Configuration]
class MyWebConfigurer implements WebMvcConfigurer {

    public function addInterceptors(InterceptorRegistry $registry): void {
        // Trace all web requests
        $registry->addInterceptor(new OpenTelemetryWebInterceptor(), '.*');
    }
}
```

---

## 6. Method-Level Tracing

Easily trace business logic methods using the `#[Traceable]` attribute. The framework will automatically handle span creation, completion, and exception recording.

```phpt
#[Service]
class OrderService {

    #[Traceable]
    public function processOrder(int $orderId): void {
        // This method execution will be traced
    }
}
```

---

## 7. Metrics & Counters

Record metrics declaratively using the `#[Countable]` attribute or by autowiring `OpenTelemetryMetrics`:

```phpt
use dev\winterframework\telemetry\metrics\Countable;
use dev\winterframework\telemetry\metrics\OpenTelemetryMetrics;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

#[Service]
class PaymentService {

    #[Autowired]
    private OpenTelemetryMetrics $metrics;

    #[Countable(name: 'payments.processed', value: 1)]
    public function processPayment(float $amount): void {
        // Increment counter automatically on invocation
    }

    public function recordLatency(float $durationMs): void {
        $this->metrics->record('payment.latency', $durationMs, ['currency' => 'USD']);
    }
}
```

---

## 8. Structured Logging

`OpenTelemetryLogs` and `OpenTelemetryMetrics` are automatically registered as beans by the `OpenTelemetryModule` and can be injected into any service:

```phpt
use dev\winterframework\telemetry\logs\OpenTelemetryLogs;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Service;

#[Service]
class AuditService {

    #[Autowired]
    private OpenTelemetryLogs $logs;

    public function auditAction(string $action): void {
        $this->logs->info('User action performed', ['action' => $action]);
    }
}
```
