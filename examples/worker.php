#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Worker demo: long-running consumer with graceful shutdown.
 *
 * Usage: php examples/worker.php
 *        Ctrl+C to stop gracefully.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use FileBroker\Broker\MessageBroker;
use FileBroker\Config\BrokerConfig;
use FileBroker\Config\QueueConfig;
use FileBroker\Logging\Logger;
use FileBroker\Message\Message;
use FileBroker\Observability\MetricsCollector;
use FileBroker\Worker\Worker;

$storagePath = '/tmp/file-broker-demo';

$config = BrokerConfig::default()
    ->withQueue(new QueueConfig(
        name: 'orders',
        basePath: $storagePath,
        maxRetryAttempts: 3,
        retryDelaySeconds: 30,
        enableDeadLetter: true,
    ));

$metrics = new MetricsCollector();
$logger = new Logger(STDERR);

$broker = new MessageBroker(
    config: $config,
    metrics: $metrics,
    logger: $logger,
);
$broker->ensureInitialized();

// Produce some messages so the worker has work to do.
echo "=== Pre-loading messages\n";
for ($i = 1; $i <= 3; $i++) {
    $broker->produce(
        queueName: 'orders',
        body: json_encode(['task' => $i, 'ts' => date('c')], JSON_THROW_ON_ERROR),
        headers: ['task' => (string) $i],
    );
    echo "  Produced task #$i\n";
}
echo "\n";

// Custom message handler.
$handler = static function (Message $message, MessageBroker $b) use ($metrics): void {
    $body = json_decode($message->body, true);
    $task = $body['task'] ?? 'unknown';

    // Simulate work.
    echo "  Processing task #$task (msg {$message->id}), delivery {$message->deliveryCount}\n";
    usleep(200_000); // 200ms

    // Record metric.
    $metrics->incrementCounter('messages.processed');
    $metrics->recordHistogram('message.body_size', strlen($message->body));

    // Acknowledge.
    $b->acknowledge('orders', $message->id);
};

echo "=== Starting worker (press Ctrl+C to stop)\n\n";

$worker = new Worker(
    queueName: 'orders',
    broker: $broker,
    handler: $handler(...),
);

// Install signal handlers for graceful shutdown.
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use ($worker): void {
        echo "\nSIGTERM received, stopping...\n";
        $worker->stop();
    });
    pcntl_signal(SIGINT, function () use ($worker): void {
        echo "\nSIGINT received, stopping...\n";
        $worker->stop();
    });
}

$worker->run();

// Graceful shutdown complete.
echo "\n=== Worker stopped\n";
echo "Processed: {$metrics->getCounter('messages.processed')} messages\n";
echo "Metrics: " . json_encode($metrics->getSnapshot(), JSON_THROW_ON_ERROR) . "\n";
