# Daemon Threads

Daemon Thread ( _is not OS thread or Java thread_ ) is a back-end process started as a Daemon to monitor, supervise or process things that matter in the application.

#### Use-cases

- Monitoring
- Supervising
- Stream Processing
- Any back-end processing

> Daemon Threads cannot be killed explicitly, they'll be running for lifetime. Framework will restart these threads in-case of oom/crash.

> This requires `swoole` php extension.

## #[DaemonThread]

This annotation **#[DaemonThread]** is used to denote a Class as a daemon process.


```phpt
#[DaemonThread]
class SomeBackendProcessing extends ServerWorkerProcess {

    #[Autowired]
    protected PdbcTemplate $pdbc;
    
    public function getProcessType(): int {
        return ProcessType::OTHER;
    }

    public function getProcessId(): string {
        return 'my-backend-processor';
    }

    protected function run(): void {
        while (1) {
            $to_process = $this->pdbc->queryForList("select * from SOME_TABLE where STATUS = 'PENDING'");
            
            foreach ($to_process as $row) {
                // do something
            }
            
            // sleep for 5 seconds
            \Co::sleep(5);
        }
    }
    
}
```

Framework would start this class as a back-end process and monitor it.

#### Properties

**#[DaemonThread]** has the following properties.

 Property  | Description 
---------- | ------------
 name       | (optional) Name of the Daemon, default to class name
 coreSize   | (optional) How many daemon processes to start, default is 1.  Setting it to 0 or negative values do not start any daemon process
 

