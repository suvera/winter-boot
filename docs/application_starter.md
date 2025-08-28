# Your Application's Heart: The Winter Boot Application Starter!

Every great Winter Boot application begins with a powerful starter! A class annotated with the `#[WinterBootApplication]` attribute serves as the entry point and orchestrator for your entire application. This flexible design allows you to define multiple application starters for various purposes, tailoring your application's behavior to specific needs.

**Why multiple starters? Imagine these scenarios:**

-   **`TestApplication` Starter:** A lean and focused starter for lightning-fast unit and integration testing.
-   **`WebApplication` Starter:** The robust core for handling all your incoming web requests.
-   **`CliApplication` Starter:** A dedicated entry point for executing powerful command-line interface (CLI) commands.


## Mastering `#[WinterBootApplication]` Configuration

The `#[WinterBootApplication]` attribute offers a rich set of configuration options to fine-tune your application's startup behavior. Let's explore how you can customize it to perfectly fit your project!

#### Example: Configuring Your `MyApplication`

```phpt


#[WinterBootApplication(
	// List of config directories: Specify where your application should look for configuration files.
	configDirectory: [],
	
	// array of records in format [NamespacePrefix, BaseDirectory]: Define custom namespaces for component scanning.
	scanNamespaces: [],
	
	// autoload, if class not loaded: Enable or disable class autoloading during startup.
	autoload: false,
	
	// List of Namespaces to exclude from scanning: Optimize startup by excluding unnecessary namespaces from component scanning.
	scanExcludeNamespaces: [],
	
	// Eagerly create Beans/Objects on start-up, slowdown the application: Control bean instantiation strategy. Set to `true` for eager loading (may impact startup time), `false` for lazy loading.
	eager = false
)]
class MyApplication {

    public static function main() {
        (new WinterWebSwooleApplication())->run(MyApplication::class);
    }
}


```

## See It in Action: Live Example!

Curious to see a fully configured `#[WinterBootApplication]` in a real-world project?
Check out the example application here: [ExampleServiceApplication.php](https://github.com/suvera/winter-example-service/blob/master/src/ExampleServiceApplication.php)
