# Logging

[Monolog](https://github.com/Seldaek/monolog) is used for Logging

# Usage


A trait **Wlf4p** will do all magic for you.


#### Example:

```phpt

class MyClass {
    use Wlf4p;
    
    public function foo(): void {
        self::logInfo('in foo() method');
        
        $this->logInfo('exiting foo() method');
    }
}

```

all types such as debug, info, error etc ... logging available by **Wlf4p**

Please check [Wlf4p.php](/src/util/log/Wlf4p.php) for more info



# Configuration

Add a file named **logger.yml** in your **config** direction mentioned by WinterBootApplication

#### Example:

```yaml

loggers:
    myLogger:
        handlers: [ info_file_handler ]
        processors: [ web_processor ]
        custom_level:
            some\namepsace\SomeClass: DEBUG
            another\namepsace\AnotherClass: ERROR
            other\namespace: INFO
            other\namespace\Class: NONE
formatters:
    dashed:
        class: Monolog\Formatter\LineFormatter
        format: "%datetime%-%channel%.%level_name% - %message%\n"
handlers:
    console:
        class: Monolog\Handler\StreamHandler
        level: DEBUG
        formatter: dashed
        processors: [ memory_processor ]
        stream: php://stdout
    info_file_handler:
        class: Monolog\Handler\StreamHandler
        level: INFO
        formatter: dashed
        stream: ../logs/MyApp.log
processors:
    web_processor:
        class: Monolog\Processor\WebProcessor
    memory_processor:
        class: Monolog\Processor\MemoryUsageProcessor


```

**custom_level** can be configured per class, to control the logging levels at class level. 

More info about logging configuration can be found here [monolog-cascade](https://github.com/suvera/monolog-cascade)

