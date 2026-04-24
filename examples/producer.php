#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Producer demo: priority messages, batch produce, exchange publishing,
 * deduplication, and publisher confirms.
 *
 * Usage: php examples/producer.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use FileBroker\Broker\MessageBroker;
use FileBroker\Config\BrokerConfig;
use FileBroker\Config\QueueConfig;
use FileBroker\Exchange\Binding;
use FileBroker\Exchange\ExchangeRegistry;
use FileBroker\Exchange\ExchangeType;
use FileBroker\Logging\Logger;
use FileBroker\Observability\MetricsCollector;
use FileBroker\Reliability\PublisherConfirm;

$storagePath = '/tmp/file-broker-demo';

// Cleanup from previous runs.
echo "=== Cleanup\n";
foreach ([
    "$storagePath/queues",
    "$storagePath/retry",
    "$storagePath/dead-letter",
    "$storagePath/exchanges",
    "$storagePath/streams",
] as $dir) {
    if (is_dir($dir)) {
        // Remove all files and subdirectories.
        $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
echo "Storage cleaned.\n\n";

// ============================================================
// 1. Build config with multiple queues.
// ============================================================
echo "=== Build config\n";
$config = BrokerConfig::default()
    ->withQueue(new QueueConfig(
        name: 'orders',
        basePath: $storagePath,
        maxRetryAttempts: 3,
        retryDelaySeconds: 30,
        enableDeadLetter: true,
    ))
    ->withQueue(new QueueConfig(
        name: 'notifications',
        basePath: $storagePath,
        maxRetryAttempts: 5,
        retryDelaySeconds: 10,
    ))
    ->withQueue(new QueueConfig(
        name: 'audit-log',
        basePath: $storagePath,
        maxRetryAttempts: 1,
        enableDeadLetter: false,
    ));
echo "Queues: " . implode(', ', array_keys($config->queues)) . "\n\n";

// ============================================================
// 2. Create broker with metrics, logger, publisher confirm.
// ============================================================
$metrics = new MetricsCollector();
$logger = new Logger(STDERR);

$broker = new MessageBroker(
    config: $config,
    metrics: $metrics,
    logger: $logger,
    publisherConfirm: new PublisherConfirm(),
);
$broker->ensureInitialized();

// ============================================================
// 3. Create topic exchange + bindings.
// ============================================================
echo "=== Setup Topic Exchange\n";
$exchangeRegistry = $broker->getExchangeRegistry();
$exchange = $exchangeRegistry->create('orders-exchange', ExchangeType::Topic);

$exchangeRegistry->bind('orders-exchange', new Binding(
    queueName: 'orders',
    routingKey: 'order.#',
));
$exchangeRegistry->bind('orders-exchange', new Binding(
    queueName: 'notifications',
    routingKey: '*.notification',
));
$exchangeRegistry->bind('orders-exchange', new Binding(
    queueName: 'audit-log',
    routingKey: '#',
));
$exchange = $exchangeRegistry->get('orders-exchange');
echo "Exchange '{$exchange->name}' created with " . count($exchange->bindings) . " bindings.\n\n";

// ============================================================
// 4. Produce with different priorities (0-255).
// ============================================================
echo "=== Produce with priorities\n";
$broker->produce(
    queueName: 'orders',
    body: json_encode(['order_id' => 1, 'action' => 'created', 'amount' => 250], JSON_THROW_ON_ERROR),
    headers: ['content-type' => 'application/json', 'event' => 'order.created'],
    priority: 100,  // high priority
);
echo "  [orders] High-priority (100): order.created\n";

$broker->produce(
    queueName: 'orders',
    body: json_encode(['order_id' => 2, 'action' => 'updated'], JSON_THROW_ON_ERROR),
    headers: ['content-type' => 'application/json', 'event' => 'order.updated'],
    priority: 50,
);
echo "  [orders] Medium-priority (50): order.updated\n";

$broker->produce(
    queueName: 'orders',
    body: 'order-3 payload',
    priority: 0,   // default
    ttl: 3600,     // 1 hour
);
echo "  [orders] Default priority (0): order-3 payload, TTL=3600s\n\n";

// ============================================================
// 5. Batch produce.
// ============================================================
echo "=== Batch produce\n";
$batchIds = $broker->produceBatch('orders', [
    ['body' => json_encode(['batch' => 1], JSON_THROW_ON_ERROR), 'priority' => 10],
    ['body' => json_encode(['batch' => 2], JSON_THROW_ON_ERROR), 'priority' => 20],
    ['body' => json_encode(['batch' => 3], JSON_THROW_ON_ERROR), 'priority' => 30],
]);
echo "  Batch produced " . count($batchIds) . " messages: " . implode(', ', $batchIds) . "\n\n";

// ============================================================
// 6. Publish via exchange (fanout demo).
// ============================================================
echo "=== Publish via topic exchange\n";
$publishedIds = $broker->publish(
    exchangeName: 'orders-exchange',
    routingKey: 'order.shipped',
    body: json_encode(['order_id' => 5, 'status' => 'shipped'], JSON_THROW_ON_ERROR),
    options: [
        'headers' => ['event' => 'order.shipped'],
        'ttl' => 7200,
    ],
);
echo "  Routing key 'order.shipped' matched " . count($publishedIds) . " queue(s):\n";
echo "  Message IDs: " . implode(', ', $publishedIds) . "\n\n";

// ============================================================
// 7. Deduplication via key.
// ============================================================
echo "=== Deduplication\n";
$msg1 = $broker->produce(
    queueName: 'orders',
    body: json_encode(['dedup' => 'first'], JSON_THROW_ON_ERROR),
    key: 'idempotent-key-42',
);
$msg2 = $broker->produce(
    queueName: 'orders',
    body: json_encode(['dedup' => 'second-should-be-skipped'], JSON_THROW_ON_ERROR),
    key: 'idempotent-key-42',  // same key — deduplicated.
);
echo "  First call:  {$msg1->id}\n";
echo "  Second call: {$msg2->id} (same ID — deduplication works)\n\n";

// ============================================================
// 8. Publisher confirms (blocking).
// ============================================================
echo "=== Publisher confirm (blocking)\n";
$confirmedMsg = $broker->produceWithConfirm(
    queueName: 'orders',
    body: json_encode(['confirmed' => true], JSON_THROW_ON_ERROR),
);
echo "  Confirmed message: {$confirmedMsg->id}\n";
$pendingCount = $broker->getPublisherConfirm()?->pendingCount() ?? 0;
echo "  Pending confirms after wait: $pendingCount\n\n";

// ============================================================
// 9. Queue stats + metrics.
// ============================================================
echo "=== Queue stats\n";
$stats = $broker->getQueueStats('orders');
echo "  orders: {$stats['message_count']} messages, {$stats['retry_count']} retry, {$stats['dead_letter_count']} dlq\n";

$nsStats = $broker->getQueueStats('notifications');
echo "  notifications: {$nsStats['message_count']} messages\n";

$alStats = $broker->getQueueStats('audit-log');
echo "  audit-log: {$alStats['message_count']} messages\n\n";

echo "=== Metrics snapshot\n";
$snapshot = $metrics->getSnapshot();
echo json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n\n";

echo "=== Done. Messages awaiting consume.\n";
echo "Run: php examples/consumer.php\n";
