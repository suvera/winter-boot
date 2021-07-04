# Shared In-Memory Stores

Framework provides following out-of-box shared in-memory stores local to each node. Multiple processes can share it.

1. **Key-Value Store**

2. **Queue Store**

## 1. Key-Value(KV) Store

To enable KV store, update **application.yml** with following configuration.

```yaml
winter:
    kv:
        port: 7880
        address:
```

Framework provides **[KvTemplate](../src/io/kv/KvTemplate.php)** as a bean autowired. 

```phpt
#[Autowired]
private KvTemplate $kvTemplate;

// Usage
$this->kvTemplate->put('domain-name', 'key', 'value');
$val = $this->kvTemplate->get('domain-name', 'key');
```



## 2. Queue Store

To enable Queue store, update **application.yml** with following configuration.

```yaml
winter:
    queue:
        port: 7881
        address:
```


Framework provides **[QueueSharedTemplate](../src/io/queue/QueueSharedTemplate.php)** as a bean autowired.

```phpt
#[Autowired]
private QueueSharedTemplate $queue;

// Usage
$this->queue->enqueue('queue-name', mixed $value);
$item = $this->queue->dequeue('queue-name');
```
