# Application Starter

A class annotated with **#[WinterBootApplication]** attribute will be become your application starter.

You may need to annotate many classes as WinterBootApplication for different purposes.

ex:

- TestApplication starter - for Unit testing
- WebApplication starter - to handle web requests
- CliApplication Starter - to handle cli commands


## WinterBootApplication

#### Example:

```phpt


#[WinterBootApplication(
	// List of config directories
	configDirectory: [],
	
	// array of records in format [NamespacePrefix, BaseDirectory]
	scanNamespaces: [],
	
	// autoload, if class not loaded 
	autoload: false,
	
	// List of Namespaces to exclude from scanning
	scanExcludeNamespaces: [],
	
	// Eagerly create Beans/Objects on start-up, slowdown the application
	eager = false
)]
class MyApplication {

    public static function main() {
        (new WinterWebSwooleApplication())->run(MyApplication::class);
    }
}


```

## Live Example

Checkout the example Application here is [ExampleServiceApplication.php](https://github.com/suvera/winter-example-service/blob/master/src/ExampleServiceApplication.php)