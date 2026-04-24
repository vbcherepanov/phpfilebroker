# File Broker

**Filesystem-backed message broker for PHP 8.4 -- zero external dependencies.**

[![PHP](https://img.shields.io/badge/PHP-8.4%2B-blue)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-passing-brightgreen)](.)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-blue)](https://phpstan.org/)

File Broker brings battle-tested messaging patterns -- queues, exchanges, streams, dead letters, retries -- directly to your filesystem. No Docker, no broker daemon, no network config. Messages are JSON files on disk, which means your IDE, `grep`, `jq`, and any file tool become your debugging dashboard.

---

## Why File Broker?

| Problem | File Broker Solution |
|---------|---------------------|
| You need a message queue but cannot run RabbitMQ/Kafka | Zero external dependencies -- works on any PHP 8.4 host |
| You want to avoid containerizing yet another service | No broker process to manage -- just file I/O |
| Debugging message flows is painful | Every message is a JSON file -- open it, `cat` it, `jq` it |
| You are building embedded systems, daemons, or CLI tools | Perfect for co-located producer/consumer patterns |
| You need atomicity without a database | Atomic `write-to-tmp` then `rename()` for crash safety |
| You want production-grade patterns in a lightweight package | Exchanges, streams, DLQs, retries, metrics -- all included |

---

## Feature Comparison

| Feature | File Broker | RabbitMQ | Kafka | NATS JetStream |
|---------|:-----------:|:--------:|:-----:|:--------------:|
| Direct exchange (exact routing) | Yes | Yes | -- | -- |
| Topic exchange (pattern routing) | Yes | Yes | -- | -- |
| Fanout exchange (broadcast) | Yes | Yes | -- | -- |
| Headers exchange (x-match all/any) | Yes | Yes | -- | -- |
| Priority queues (0-255) | Yes | Yes | -- | -- |
| Dead Letter Queue | Yes | Yes | -- | Yes |
| Retry with backoff delay | Yes | Yes | -- | Yes |
| Per-message TTL | Yes | Yes | -- | -- |
| Batch produce / ack | Yes | Plugin | Yes | -- |
| Publisher confirms | Yes | Yes | -- | -- |
| Prefetch count | Yes | Yes | -- | -- |
| Deduplication (exactly-once) | Yes | Plugin | Yes | Yes |
| Persistent log (stream) | Yes | -- | Yes | Yes |
| Consumer groups | Yes | -- | Yes | Yes |
| Offset-based replay | Yes | -- | Yes | Yes |
| Retention (time/size/count) | Yes | -- | Yes | Yes |
| Atomic writes (rename) | Yes | -- | -- | -- |
| Flock-based concurrency | Yes | -- | -- | -- |
| Structured JSON logging | Yes | Plugin | -- | -- |
| Counters + histograms | Yes | Plugin | Yes | Yes |
| Auto-observability (metrics subscriber) | Yes | Plugin | -- | -- |
| Graceful shutdown (SIGTERM/SIGINT) | Yes | -- | Yes | -- |
| CLI tooling | 12 commands | CLI | CLI | CLI |
| External dependencies | None | Erlang VM | JVM + ZK | Go binary |

---

## Quick Start

```bash
composer require vbcherepanov/phpfilebroker
```

```php
use FileBroker\Broker\MessageBroker;
use FileBroker\Config\BrokerConfig;
use FileBroker\Config\QueueConfig;

$config = BrokerConfig::default()->withQueue(new QueueConfig(
    name: 'emails',
    basePath: '/tmp/broker',
));

$broker = new MessageBroker($config);
$broker->ensureInitialized();

// Produce
$msg = $broker->produce('emails', json_encode(['to' => 'user@example.com']));

// Consume
$msg = $broker->consume('emails');
if ($msg !== null) {
    echo "Got: {$msg->body}\n";
    $broker->acknowledge('emails', $msg->id);
}
```

---

## Standards Compliance

| Standard | Status | Implementation |
|---|---|---|
| **PSR-3** (Logger Interface) | Fully compliant | `Logger` implements `Psr\Log\LoggerInterface` — all 8 log levels |
| **PSR-4** (Autoloading) | Fully compliant | `FileBroker\` → `src/` |
| **PSR-14** (Event Dispatcher) | Fully compliant | `EventDispatcher` implements `Psr\EventDispatcher\EventDispatcherInterface` |
| **PER-CS 2.0** (Coding Style) | Fully compliant | Enforced via `php-cs-fixer` in CI |

---

## Installation

### Composer

```bash
composer require vbcherepanov/phpfilebroker
```

Requirements: PHP 8.4+, `ext-json`, `ext-spl`, `ext-ctype`, `ext-mbstring`. No other dependencies.

### Storage Setup

Create the storage directories:

```bash
make storage
# or manually:
mkdir -p storage/{queues,dead-letter,retry}
```

---

## Core Concepts

### Message

An **immutable** envelope carrying business data through the broker:

```php
final class Message
{
    public readonly string $id;
    public readonly string $body;
    public readonly array $headers;
    public readonly \DateTimeImmutable $createdAt;
    public readonly ?\DateTimeImmutable $expiresAt;
    public readonly int $deliveryCount;
    public readonly ?string $correlationId;
    public readonly ?string $replyTo;
    public readonly int $priority;
    public readonly ?string $key;
}
```

Messages are never mutated -- `withBody()`, `withHeaders()`, `incrementDeliveryCount()`, `withPriority()`, `withKey()` each return a new instance.

### Queue

A named directory on disk. Each queue lives under `storage/queues/<name>/` and stores messages as individual `.msg` JSON files.

### Exchange

Routes messages to one or more queues based on rules. Persistent -- stored as JSON files under `storage/exchanges/`.

### Stream

A persistent append-only log where messages survive acknowledgement. Consumer groups track their own offsets, enabling replay and fan-in.

---

## Usage Examples

### Basic Produce & Consume

```php
use FileBroker\Broker\MessageBroker;
use FileBroker\Config\BrokerConfig;
use FileBroker\Config\QueueConfig;

$broker = new MessageBroker(
    BrokerConfig::default()->withQueue(new QueueConfig(
        name: 'notifications',
        basePath: '/tmp/file-broker',
    ))
);
$broker->ensureInitialized();

// Send
$message = $broker->produce('notifications', '{"type":"welcome"}');

// Receive
$message = $broker->consume('notifications');
if ($message !== null) {
    try {
        processNotification($message->body);
        $broker->acknowledge('notifications', $message->id);
    } catch (\Throwable $e) {
        $broker->reject('notifications', $message->id, $e->getMessage());
    }
}
```

### Priority Messages

Priority 0 is highest (sent first), 255 is lowest. Consume sorts messages by priority ascending, then by creation time (FIFO within the same priority).

```php
// High priority
$broker->produce('orders', '{"id":1}', priority: 0);

// Normal priority (default)
$broker->produce('orders', '{"id":2}', priority: 100);

// Low priority
$broker->produce('orders', '{"id":3}', priority: 200);
```

### Batch Produce & Acknowledge

```php
// Produce many at once
$ids = $broker->produceBatch('notifications', [
    ['body' => '{"type":"email"}', 'headers' => ['channel' => 'email']],
    ['body' => '{"type":"sms"}',   'headers' => ['channel' => 'sms']],
    ['body' => '{"type":"push"}',  'headers' => ['channel' => 'push'], 'priority' => 50],
]);

// Acknowledge many at once
$broker->acknowledgeBatch('notifications', $ids);
```

### Exchanges

#### Direct Exchange -- exact routing key match

```php
use FileBroker\Exchange\Binding;
use FileBroker\Exchange\ExchangeRegistry;
use FileBroker\Exchange\ExchangeType;

$registry = $broker->getExchangeRegistry();
$ex = $registry->create('orders.direct', ExchangeType::Direct);

// Bind queues with routing keys
$registry->bind('orders.direct', new Binding(
    queueName: 'orders.usa',
    routingKey: 'order.usa',
));
$registry->bind('orders.direct', new Binding(
    queueName: 'orders.eu',
    routingKey: 'order.eu',
));

// Only orders.usa receives this
$broker->publish('orders.direct', 'order.usa', '{"item":"laptop"}');

// Only orders.eu receives this
$broker->publish('orders.direct', 'order.eu', '{"item":"monitor"}');
```

#### Topic Exchange -- pattern matching with `*` and `#`

```php
$ex = $registry->create('events.topic', ExchangeType::Topic);

$registry->bind('events.topic', new Binding('orders.usa',    'order.*.usa'));
$registry->bind('events.topic', new Binding('orders.log',    'order.#'));
$registry->bind('events.topic', new Binding('alerts.crit',   'alert.#.critical'));

// Matches orders.usa  (* = "created")
$broker->publish('events.topic', 'order.created.usa', '...');

// Matches orders.log  (# = "updated.europe")
$broker->publish('events.topic', 'order.updated.europe', '...');

// Matches alerts.crit  (# = "disk")
$broker->publish('events.topic', 'alert.disk.critical', '...');
```

Pattern rules: `*` matches exactly one dot-separated word, `#` matches zero or more words. Uses dynamic programming for O(m*n) matching.

#### Fanout Exchange -- broadcast to all bound queues

```php
$ex = $registry->create('events.fanout', ExchangeType::Fanout);

$registry->bind('events.fanout', new Binding('queue.one'));
$registry->bind('events.fanout', new Binding('queue.two'));
$registry->bind('events.fanout', new Binding('queue.three'));

// All three queues receive this
$broker->publish('events.fanout', '', '{"broadcast":"true"}');
```

#### Headers Exchange -- match by message headers

```php
$ex = $registry->create('events.headers', ExchangeType::Headers);

// "any" match (default) -- matches if at least one header pair matches
$registry->bind('events.headers', new Binding(
    queueName: 'logs.json',
    headerMatch: ['format' => 'json', 'app' => 'web'],
    xmatch: 'any',
));

// "all" match -- matches only if ALL header pairs match
$registry->bind('events.headers', new Binding(
    queueName: 'logs.json.web',
    headerMatch: ['format' => 'json', 'app' => 'web'],
    xmatch: 'all',
));

// This hits logs.json (format matches) but NOT logs.json.web (app missing)
$broker->publish('events.headers', '', '{"log":"data"}', [
    'headers' => ['format' => 'json'],
]);

// This hits both -- "all" satisfied
$broker->publish('events.headers', '', '{"log":"data"}', [
    'headers' => ['format' => 'json', 'app' => 'web'],
]);
```

### Streams -- Persistent Log with Consumer Groups

In stream mode, messages are **never deleted** after acknowledge. Each consumer group tracks its own offset and can replay from any position.

```php
use FileBroker\Stream\StreamConfig;

$broker->enableStream('audit.log', new StreamConfig(
    queueName: 'audit.log',
    enabled: true,
    maxRetentionSeconds: 86400,     // Keep 24 hours
    maxRetentionBytes: 1073741824,  // 1 GB
    maxRetentionMessages: 1000000,
));

// Produce normally
$broker->produce('audit.log', '{"user":"alice","action":"login"}');

// Consumer group "processor-1" reads from its offset
$record = $broker->streamConsume('audit.log', 'processor-1');

if ($record !== null) {
    echo "Offset {$record['offset']}: {$record['body']}\n";
    // Commit offset -- message stays on disk
    $broker->streamAcknowledge('audit.log', 'processor-1', $record['offset']);
}

// Consumer group "auditor" has its own independent offset
$record = $broker->streamConsume('audit.log', 'auditor');

// Replay from beginning for a specific consumer group
$history = $broker->streamReplay('audit.log', 'auditor', fromOffset: 0, limit: 50);

// Check which consumer groups exist
$stats = $broker->streamStats('audit.log');
print_r($stats['consumer_groups']); // ['auditor', 'processor-1']
```

**Load balancing within a consumer group:** multiple consumers in the same group use `flock` on a shared lock file, so each message is delivered to exactly one consumer in the group.

**Retention enforcement:** call `$stream->enforceRetention()` periodically (or on cron) to delete messages exceeding time/size/count limits.

### Publisher Confirms

RabbitMQ-style: register a callback, produce a message, and get notified when it is durably written.

```php
use FileBroker\Reliability\PublisherConfirm;

$confirm = new PublisherConfirm();
$confirm->register('msg-123', function (string $messageId) {
    echo "Message {$messageId} confirmed!";
});

$broker = new MessageBroker(publisherConfirm: $confirm);
$broker->produce('orders', '{"item":"laptop"}', messageId: 'msg-123');
// Callback fires after atomic rename completes

// Blocking variant -- wait for all pending confirms
$broker->produceWithConfirm('orders', '{"item":"monitor"}');
// Blocks until durable write completes (10s timeout)
```

### Prefetch Control

Limit how many unacknowledged messages a consumer can have in flight:

```php
use FileBroker\Flow\PrefetchController;

$prefetch = new PrefetchController(prefetchCount: 5);

$broker = new MessageBroker(prefetchController: $prefetch);

// Before each consume, check if the consumer can take more
if ($prefetch->canReceive($unackedCount)) {
    $msg = $broker->consume('orders');
    $unackedCount++;
}
```

### Worker with Callback

Long-running consumer that polls a queue and invokes your callback for every message:

```php
use FileBroker\Worker\Worker;

$worker = new Worker(
    queueName: 'orders',
    broker: $broker,
    handler: function (\FileBroker\Message\Message $msg, MessageBroker $broker): void {
        $data = json_decode($msg->body, true);

        // Your business logic
        OrdersService::process($data);

        $broker->acknowledge('orders', $msg->id);
    },
);

$worker->run(); // Blocks until stop() is called
```

### Graceful Shutdown

The Worker auto-installs `SIGTERM` and `SIGINT` handlers (if `pcntl` is available). On signal, `stop()` is called, and the worker exits its poll loop cleanly -- finishing the current message before returning.

```php
// Signal to stop (can be sent via kill, Ctrl+C, etc.)
$worker->stop();

// Check status
if ($worker->isRunning()) {
    // Still processing
}
```

### Worker Pool

Multiple workers consuming from the same queue. Each worker competes for the `flock`, so messages are distributed naturally:

```php
use FileBroker\Worker\WorkerPool;

$pool = new WorkerPool('orders', $broker, function (Message $msg, MessageBroker $broker): void {
    processMessage($msg->body);
    $broker->acknowledge('orders', $msg->id);
});

$pool->run(); // Starts up to maxWorkers (from config) workers

// Resize pool at runtime
$pool->resize(8);
$pool->stop();
```

Note: without `pcntl_fork`, workers run sequentially within a single process (each Worker blocks its own poll loop). For true parallelism, run multiple `php worker.php` processes or use the `watch` CLI command.

### Dead Letter Queue + Retry

Failed messages go through a retry cycle and end up in the DLQ:

```
produce → consume → fail → reject → retry/ (with delay) → consume → fail → ... → dead-letter/
```

```php
// Message that fails -- moves to retry with delay
$broker->reject('orders', $msg->id, 'External API timeout');

// After retry_delay seconds, consume picks it up from retry/
// If it fails again and max_retry is exceeded -- moves to DLQ
// (happens automatically in consume() when deliveryCount >= maxRetryAttempts)

// Manual DLQ move
$broker->deadLetter('orders', $msg->id, 'Data schema changed -- manual intervention needed');

// Inspect DLQ
$stats = $broker->getQueueStats('orders');
echo "Dead letters: {$stats['dead_letter_count']}\n";
```

DLQ messages carry metadata in headers:
- `_broker_dlq_reason` -- why it was moved
- `_broker_dlq_at` -- when it was moved

Retry messages:
- `_broker_retry_reason` -- failure reason
- `_broker_retry_at` -- when to retry (ISO 8601)

### Deduplication (Exactly-Once Produce)

Prevent duplicate messages by providing a deduplication key:

```php
// First produce -- creates the message
$first = $broker->produce('payments', '{"amount":100}', key: 'txn-abc123');

// Second produce with same key -- returns the first message, body is ignored
$second = $broker->produce('payments', '{"amount":100}', key: 'txn-abc123');

assert($first->id === $second->id); // Same message, idempotent
```

The deduplication cache lives in-memory for the lifetime of the MessageBroker instance. For cross-process deduplication, use a persistent key store.

### Observability

#### Metrics (Counters + Histograms)

```php
use FileBroker\Observability\MetricsCollector;

$metrics = new MetricsCollector();
$broker = new MessageBroker(metrics: $metrics);

// MetricsSubscriber auto-attaches to all broker events:
// messages_produced_total, messages_consumed_total, messages_acknowledged_total,
// messages_rejected_total, messages_retried_total, messages_dead_lettered_total
// message_size_bytes (histogram)

// Query snapshot
$snapshot = $metrics->getSnapshot();

echo "Produced: {$snapshot['counters']['messages_produced_total']}\n";
echo "Acknowledged: {$snapshot['counters']['messages_acknowledged_total']}\n";

// Histogram summary
$size = $snapshot['histograms']['message_size_bytes'];
echo "Size avg: {$size['avg']} bytes, max: {$size['max']}, p50: ~{$size['avg']}\n";

// Custom metric
$metrics->incrementCounter('business.orders.processed');

// Reset for the next interval
$metrics->reset();
```

#### Structured JSON Logging

```php
use FileBroker\Logging\Logger;

// Writes to STDERR by default; pass fopen('path/to/log.jsonl', 'w') for file
$logger = new Logger();

$broker = new MessageBroker(logger: $logger);
```

Every log line is a JSON object:

```json
{"timestamp":"2026-04-24T10:00:00+00:00","level":"info","message":"Message produced","context":{"id":"abc123","queue":"orders","body":"{\"item\":\"laptop\"}"}}
```

Log levels: `debug`, `info`, `warning`, `error`. Convenience methods on Logger: `info()`, `warning()`, `error()`, `debug()`.

---

## Configuration

### broker.json

```json
{
    "storage_path": "/var/lib/file-broker",
    "default_queue": null,
    "lock_timeout": 30,
    "poll_interval": 1,
    "max_workers": 4,
    "queues": {
        "orders": {
            "name": "orders",
            "base_path": "/var/lib/file-broker",
            "default_ttl": 86400,
            "max_retry": 5,
            "retry_delay": 120,
            "dead_letter": true,
            "dead_letter_queue": "orders.dlq",
            "max_message_size": 5242880
        }
    }
}
```

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `storage_path` | string | `/tmp/file-broker` | Root directory for all broker data |
| `default_queue` | string\|null | `null` | Queue used when none specified |
| `lock_timeout` | int | `30` | Max seconds to wait for flock |
| `poll_interval` | int | `1` | Interval between polls (seconds) |
| `max_workers` | int | `4` | Max parallel workers per pool |
| `queues.<name>.default_ttl` | int\|null | `null` | Default TTL in seconds |
| `queues.<name>.max_retry` | int | `3` | Max retry attempts before DLQ |
| `queues.<name>.retry_delay` | int | `60` | Seconds between retries |
| `queues.<name>.dead_letter` | bool | `true` | Enable DLQ for this queue |
| `queues.<name>.dead_letter_queue` | string\|null | `{name}.dlq` | DLQ directory name |
| `queues.<name>.max_message_size` | int | `10485760` | Max body size in bytes |

### Programmatic Configuration

```php
$config = BrokerConfig::default()
    ->withQueue(new QueueConfig(
        name: 'invoices',
        basePath: '/data/broker',
        defaultTtlSeconds: 7200,
        maxRetryAttempts: 10,
        retryDelaySeconds: 300,
        enableDeadLetter: true,
        deadLetterQueueName: 'invoices.dead',
        maxMessageSizeBytes: 1_048_576, // 1MB
    ))
    ->withQueue(new QueueConfig(
        name: 'logs',
        basePath: '/data/broker',
        enableDeadLetter: false, // Discard failed logs, don't DLQ
    ));

echo $config->lockTimeout; // 30 (default)
echo $config->maxWorkers;  // 4 (default)
```

Local overrides: `config/broker.local.json` is gitignored and loaded instead of `broker.json` when present.

---

## CLI Reference

```bash
bin/file-broker <command> [arguments] [options]
```

### Commands

| Command | Arguments | Description |
|---------|-----------|-------------|
| `produce` | `<queue> <body>` | Send a message to a queue |
| `consume` | `<queue>` | Receive and acknowledge the next message |
| `list` | -- | List all configured queues |
| `stats` | `[queue]` | Show statistics (all queues or a specific one) |
| `purge` | `<queue>` | Delete all messages from a queue |
| `create-queue` | `<name> [path]` | Register a new queue at runtime |
| `delete-queue` | `<name>` | Remove a queue and its messages |
| `dead-letter` | `<queue> <id> [reason]` | Move a message to the DLQ |
| `retry` | `<queue> <id>` | Retry a message immediately |
| `watch` | `<queue>` | Continuously watch for new messages |
| `health` | -- | Display broker health status |
| `help` | -- | Show help text |

### Options

| Option | Description |
|--------|-------------|
| `--config <path>` | Path to config file (default: `./config/broker.json`) |
| `--ttl <seconds>` | Message TTL in seconds |
| `--id <id>` | Custom message ID |
| `--headers <json>` | JSON object for message headers |
| `--limit <n>` | Limit output to N items (for `watch`) |
| `--once` | Exit after one message (for `watch`) |
| `--verbose` | Show detailed output |

### Examples

```bash
# Send a message
bin/file-broker produce orders '{"order_id": 123, "item": "laptop"}'

# Send with TTL and headers
bin/file-broker produce emails '{"to":"user@example.com"}' --ttl 3600 --headers '{"content_type":"application/json"}'

# Receive
bin/file-broker consume orders

# Watch queue live
bin/file-broker watch orders --limit 100

# Check health
bin/file-broker health

# Inspect DLQ
bin/file-broker stats orders
```

---

## Message Format

Every message is stored as a JSON file on disk:

```json
{
    "id": "a1b2c3d4-e5f6-4a7b-8c9d-e0f1a2b3c4d5",
    "body": "{\"order_id\": 123, \"item\": \"laptop\"}",
    "headers": {
        "content_type": "application/json",
        "x-trace-id": "abc123"
    },
    "created_at": "2026-04-24T10:00:00+00:00",
    "expires_at": "2026-04-25T10:00:00+00:00",
    "delivery_count": 1,
    "correlation_id": null,
    "reply_to": null,
    "priority": 0,
    "key": "dedup-abc123"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `id` | string | Pseudo-UUID v4, auto-generated |
| `body` | string | Arbitrary payload (JSON string recommended) |
| `headers` | object | Key-value metadata |
| `created_at` | string | ISO 8601 creation timestamp |
| `expires_at` | string\|null | ISO 8601 expiration (from TTL) |
| `delivery_count` | int | Times this message has been consumed |
| `correlation_id` | string\|null | For RPC / request-reply patterns |
| `reply_to` | string\|null | Queue name for replies |
| `priority` | int | 0-255, lower = higher priority |
| `key` | string\|null | Deduplication key |

---

## Architecture

```
┌──────────────────────────────────────────────────────────────────────┐
│                          Producer API                                │
│  produce() | produceBatch() | produceWithConfirm() | publish()       │
└───────────────────────────┬──────────────────────────────────────────┘
                            │
                            ▼
┌──────────────────────────────────────────────────────────────────────┐
│                      Exchange Registry                               │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐              │
│  │  Direct  │ │  Topic   │ │  Fanout  │ │ Headers  │              │
│  │ exact    │ │ *.us.#   │ │  all     │ │ x-match  │              │
│  └─────┬────┘ └─────┬────┘ └─────┬────┘ └─────┬────┘              │
│        └─────────────┴────────────┴───────────┘                     │
│                         │ publish() → routes to queues              │
└─────────────────────────┼────────────────────────────────────────────┘
                          │
                          ▼
┌──────────────────────────────────────────────────────────────────────┐
│                       MessageBroker                                  │
│                                                                      │
│  ┌────────────┐  ┌───────────┐  ┌──────────────┐  ┌─────────────┐ │
│  │  Producer  │  │  Consumer │  │   Stream     │  │   Events    │ │
│  │            │  │           │  │              │  │             │ │
│  │ Priority   │  │ Flock     │  │ Consumer     │  │ Produced    │ │
│  │ Batches    │  │ Prefetch  │  │ Groups       │  │ Consumed    │ │
│  │ Dedup      │  │ Retry     │  │ Offsets      │  │ Ack'd       │ │
│  │ Confirm    │  │ Expiry    │  │ Retention    │  │ Rejected    │ │
│  └─────┬──────┘  └─────┬─────┘  └──────┬───────┘  │ Retried     │ │
│        │               │               │           │ DLQ'd       │ │
│        └───────────────┼───────────────┘           └──────┬──────┘ │
│                        │                                  │        │
│                        ▼                                  │        │
│  ┌──────────────────────────────────────────────────┐     │        │
│  │                File System                        │     │        │
│  │                                                   │     │        │
│  │  storage/                                         │     │        │
│  │  ├── queues/<name>/   (.msg JSON files, .lock)   │     │        │
│  │  ├── retry/<name>/    (delayed messages)         │     │        │
│  │  ├── dead-letter/<dlq>/  (failed messages)       │     │        │
│  │  ├── exchanges/       (.json exchange defs)      │     │        │
│  │  └── streams/<name>/  (offsets/, groups/)        │     │        │
│  └──────────────────────────────────────────────────┘     │        │
│                                                            │        │
│  ┌─────────────────────────┐  ┌───────────────────────────┴──┐     │
│  │    Observability         │  │        Reliability           │     │
│  │  ┌───────────────────┐  │  │  ┌─────────────────────────┐ │     │
│  │  │ MetricsCollector  │  │  │  │   PublisherConfirm      │ │     │
│  │  │ Counters          │  │  │  │   waitForAll()          │ │     │
│  │  │ Histograms        │  │  │  │   register() + callback │ │     │
│  │  └───────────────────┘  │  │  └─────────────────────────┘ │     │
│  │  ┌───────────────────┐  │  └──────────────────────────────┘     │
│  │  │ MetricsSubscriber │  │                                       │
│  │  │ Auto-subscribes   │  │  ┌──────────────────────────────┐     │
│  │  │ to all events     │  │  │   Worker / WorkerPool         │     │
│  │  └───────────────────┘  │  │   SIGTERM/SIGINT handlers     │     │
│  │  ┌───────────────────┐  │  │   Exponential backoff         │     │
│  │  │ Logger             │  │  │   Callback-based processing  │     │
│  │  │ Structured JSON    │  │  └──────────────────────────────┘     │
│  │  │ STDERR / file      │  │                                       │
│  │  └───────────────────┘  │                                       │
│  └─────────────────────────┘                                       │
└──────────────────────────────────────────────────────────────────────┘
                          │
                          ▼
┌──────────────────────────────────────────────────────────────────────┐
│                          Consumer API                                │
│  consume() | acknowledge() | reject() | deadLetter()                 │
│  streamConsume() | streamAcknowledge() | streamReplay()              │
└──────────────────────────────────────────────────────────────────────┘
```

---

## Development

```bash
# Install dependencies
make install

# Run all tests
make test

# Unit tests only
make test:unit

# Integration tests only
make test:integration

# Coverage report (HTML in coverage/)
make test:coverage

# PHP CS Fixer (dry-run)
make lint

# PHP CS Fixer (auto-fix)
make fix

# PHPStan level 8, bleeding edge
make analyze

# Create storage directories
make storage

# Clean build artifacts
make clean
```

### Single test class

```bash
php vendor/bin/phpunit --filter=MessageTest --colors=always
```

### Docker

```bash
make docker-build   # Build image
make docker-test    # Run tests in container
make docker-shell   # Interactive shell
```

---

## Directory Structure

```
├── bin/
│   └── file-broker               # CLI entry point
├── config/
│   ├── broker.json               # Default configuration
│   └── broker.local.json         # Local overrides (gitignored)
├── src/
│   ├── Broker/
│   │   └── MessageBroker.php     # Central facade
│   ├── CLI/
│   │   ├── Console.php           # Arg parsing + dispatch
│   │   └── Command/              # 12 command classes
│   ├── Config/
│   │   ├── BrokerConfig.php      # Global config (immutable)
│   │   └── QueueConfig.php       # Per-queue config
│   ├── Consumer/
│   │   ├── ConsumerInterface.php
│   │   └── FileConsumer.php
│   ├── Event/
│   │   ├── EventDispatcher.php   # Synchronous dispatcher
│   │   ├── MessageProducedEvent.php
│   │   ├── MessageConsumedEvent.php
│   │   ├── MessageAcknowledgedEvent.php
│   │   ├── MessageRejectedEvent.php
│   │   ├── MessageRetryEvent.php
│   │   ├── MessageDeadLetteredEvent.php
│   │   ├── WorkerStartedEvent.php
│   │   └── WorkerStoppedEvent.php
│   ├── Exception/
│   │   ├── BrokerException.php
│   │   ├── QueueNotFoundException.php
│   │   ├── MessageExpiredException.php
│   │   ├── MessageTooLargeException.php
│   │   ├── LockException.php
│   │   └── DeserializationException.php
│   ├── Exchange/
│   │   ├── Exchange.php          # Routing logic
│   │   ├── ExchangeRegistry.php  # CRUD + persistence
│   │   ├── ExchangeType.php      # enum: Direct/Topic/Fanout/Headers
│   │   └── Binding.php           # Queue-to-exchange binding
│   ├── Flow/
│   │   └── PrefetchController.php
│   ├── Logging/
│   │   ├── Logger.php            # Structured JSON logger
│   │   └── LogLevel.php          # enum: Debug/Info/Warning/Error
│   ├── Message/
│   │   ├── Message.php           # Immutable envelope
│   │   └── MessagePayloadFactory.php
│   ├── Observability/
│   │   ├── MetricsCollector.php  # Counters + histograms
│   │   └── MetricsSubscriber.php # Auto-subscribe to events
│   ├── Producer/
│   │   ├── ProducerInterface.php
│   │   └── FileProducer.php
│   ├── Reliability/
│   │   └── PublisherConfirm.php
│   ├── Stream/
│   │   ├── Stream.php            # Persistent log logic
│   │   ├── StreamConfig.php      # Retention settings
│   │   ├── OffsetManager.php     # Consumer group offsets
│   │   └── ConsumerOffset.php    # Offset DTO
│   └── Worker/
│       ├── Worker.php            # Single consumer loop
│       └── WorkerPool.php        # Multi-worker manager
├── tests/
│   ├── Unit/                     # Pure logic tests
│   └── Integration/              # Real filesystem tests
├── storage/
│   ├── queues/
│   ├── dead-letter/
│   └── retry/
├── Makefile
├── composer.json
├── phpunit.xml.dist
└── phpstan.neon
```

---

## Exception Hierarchy

All exceptions extend `BrokerException`:

```
BrokerException
├── QueueNotFoundException      -- Queue name not in config
├── MessageExpiredException     -- TTL exceeded during consume
├── MessageTooLargeException    -- Body exceeds max_message_size
├── LockException               -- flock timeout
└── DeserializationException    -- Corrupt JSON on disk
```

---

## License

MIT. See [LICENSE](LICENSE).
