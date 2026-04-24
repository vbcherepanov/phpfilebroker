#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Consumer demo: consume, ack, batch ack, reject/retry, dead letter,
 * stream mode + consumer groups + replay, and metrics.
 *
 * Usage: php examples/consumer.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use FileBroker\Broker\MessageBroker;
use FileBroker\Config\BrokerConfig;
use FileBroker\Config\QueueConfig;
use FileBroker\Observability\MetricsCollector;
use FileBroker\Stream\StreamConfig;

$storagePath = '/tmp/file-broker-demo';

// Reuse same config as producer.
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

$metrics = new MetricsCollector();
$broker = new MessageBroker(
    config: $config,
    metrics: $metrics,
);
$broker->ensureInitialized();

// ============================================================
// 1. Consume and process messages from 'orders'.
// ============================================================
echo "=== Consume from 'orders'\n";
$processed = 0;
$consumedIds = [];

while (true) {
    $message = $broker->consume('orders');
    if ($message === null) {
        echo "  Queue empty after $processed messages.\n";
        break;
    }

    $processed++;
    $consumedIds[] = $message->id;

    $body = json_decode($message->body, true);
    $preview = is_array($body) ? ($body['order_id'] ?? $body['batch'] ?? 'payload') : substr($message->body, 0, 40);

    echo "  [#$processed] {$message->id}\n";
    echo "    body:     $preview\n";
    echo "    priority: {$message->priority}\n";
    echo "    key:      " . ($message->key ?? 'none') . "\n";
    echo "    delivery: {$message->deliveryCount}\n";

    // Acknowledge (processed successfully).
    $broker->acknowledge('orders', $message->id);
    echo "    status:   acknowledged\n\n";

    if ($processed >= 5) {
        // Stop after 5 to keep some messages for stream demo.
        break;
    }
}

// ============================================================
// 2. Show batch ack on remaining messages.
// ============================================================
echo "=== Batch acknowledge remaining 'orders' messages\n";
$batchIds = [];
while (true) {
    $message = $broker->consume('orders');
    if ($message === null) {
        break;
    }
    $batchIds[] = $message->id;
}

if ($batchIds !== []) {
    $broker->acknowledgeBatch('orders', $batchIds);
    echo "  Acknowledged " . count($batchIds) . " messages in batch.\n";
} else {
    echo "  No messages left.\n";
}
echo "\n";

// ============================================================
// 3. Produce stream messages, then consume in stream mode.
// ============================================================
echo "=== Stream mode\n";

// Produce fresh messages for stream demo.
$broker->produce('orders', json_encode(['stream' => 'msg-1'], JSON_THROW_ON_ERROR));
$broker->produce('orders', json_encode(['stream' => 'msg-2'], JSON_THROW_ON_ERROR));
$broker->produce('orders', json_encode(['stream' => 'msg-3'], JSON_THROW_ON_ERROR));
echo "  Produced 3 messages for stream.\n";

// Enable stream (messages persist after ack).
$broker->enableStream('orders', new StreamConfig(
    queueName: 'orders',
    enabled: true,
    maxRetentionMessages: 1000,
));
echo "  Stream enabled for 'orders'.\n";

// Consume via stream.
$streamOffsets = [];
while (true) {
    $entry = $broker->streamConsume('orders', 'my-group');
    if ($entry === null) {
        echo "  No more stream messages.\n";
        break;
    }
    echo "  [#{$entry['offset']}] {$entry['id']}: {$entry['body']}\n";
    $broker->streamAcknowledge('orders', 'my-group', $entry['offset']);
    $streamOffsets[] = $entry['offset'];
}
echo "  Stream offsets acked: " . implode(', ', $streamOffsets) . "\n\n";

// ============================================================
// 4. Stream replay (all messages, from offset 0).
// ============================================================
echo "=== Stream replay (from offset 0)\n";
$replayed = $broker->streamReplay('orders', 'my-group', fromOffset: 0);
echo "  Replaying " . count($replayed) . " messages:\n";
foreach ($replayed as $entry) {
    echo "  [#{$entry['offset']}] {$entry['id']}: {$entry['body']}\n";
}
echo "\n";

// ============================================================
// 5. Reject and dead letter demo.
// ============================================================
echo "=== Reject / retry / dead letter\n";

// Produce a message to reject.
$rejectMsg = $broker->produce('orders', json_encode(['fail' => true], JSON_THROW_ON_ERROR));
echo "  Produced message to reject: {$rejectMsg->id}\n";

// Consume it, then reject (moves to retry).
$consumed = $broker->consume('orders');
if ($consumed !== null) {
    $broker->reject('orders', $consumed->id, 'Simulated processing failure');
    echo "  Rejected: {$consumed->id} (moved to retry with 30s delay)\n";
}

// Produce a message for direct dead-letter.
$dlqMsg = $broker->produce(
    queueName: 'notifications',
    body: json_encode(['dlq' => 'test'], JSON_THROW_ON_ERROR),
);
$consumedDlq = $broker->consume('notifications');
if ($consumedDlq !== null) {
    $broker->deadLetter('notifications', $consumedDlq->id, 'Invalid payload format');
    echo "  Dead-lettered: {$consumedDlq->id}\n";
}
echo "\n";

// ============================================================
// 6. Final stats + metrics.
// ============================================================
echo "=== Final stats\n";
foreach (['orders', 'notifications', 'audit-log'] as $q) {
    $s = $broker->getQueueStats($q);
    echo "  $q: {$s['message_count']} msgs, {$s['retry_count']} retry, {$s['dead_letter_count']} dlq\n";
}

if ($broker->getStream('orders') !== null) {
    $streamStats = $broker->streamStats('orders');
    echo "  orders (stream): {$streamStats['total_messages']} total, groups: " . implode(', ', $streamStats['consumer_groups']) . "\n";
}

echo "\n=== Metrics\n";
echo json_encode($metrics->getSnapshot(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n\n";

echo "=== Done.\n";
