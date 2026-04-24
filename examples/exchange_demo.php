#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Exchange routing demo: topic, fanout, direct, and headers exchanges.
 *
 * Usage: php examples/exchange_demo.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use FileBroker\Broker\MessageBroker;
use FileBroker\Config\BrokerConfig;
use FileBroker\Config\QueueConfig;
use FileBroker\Exchange\Binding;
use FileBroker\Exchange\ExchangeRegistry;
use FileBroker\Exchange\ExchangeType;

$storagePath = '/tmp/file-broker-demo';

// ── Config with 4 queues ──
$config = BrokerConfig::default()
    ->withQueue(new QueueConfig(name: 'queue-a', basePath: $storagePath))
    ->withQueue(new QueueConfig(name: 'queue-b', basePath: $storagePath))
    ->withQueue(new QueueConfig(name: 'queue-c', basePath: $storagePath))
    ->withQueue(new QueueConfig(name: 'queue-d', basePath: $storagePath));

$broker = new MessageBroker(config: $config);
$broker->ensureInitialized();

$registry = $broker->getExchangeRegistry();

// ============================================================
// 1. Topic Exchange: route by pattern matching.
// ============================================================
echo "=== 1. Topic Exchange\n";
$registry->create('topic-demo', ExchangeType::Topic);
$registry->bind('topic-demo', new Binding('queue-a', 'order.created'));
$registry->bind('topic-demo', new Binding('queue-b', 'order.*'));       // any action
$registry->bind('topic-demo', new Binding('queue-c', '*.created'));     // any domain
$registry->bind('topic-demo', new Binding('queue-d', '#'));             // catch-all

$testCases = [
    ['order.created', ['queue-a', 'queue-b', 'queue-c', 'queue-d']],
    ['order.shipped', ['queue-b', 'queue-d']],
    ['invoice.created', ['queue-c', 'queue-d']],
    ['payment.failed', ['queue-d']],
];

foreach ($testCases as [$routingKey, $expected]) {
    $ids = $broker->publish('topic-demo', $routingKey, json_encode(['rk' => $routingKey], JSON_THROW_ON_ERROR));
    $actual = array_keys(array_intersect_key(
        array_flip($broker->listQueues()),
        array_flip(array_unique(array_map(
            fn(string $id): string => $broker->getQueueStats('queue-a') !== null ? 'queue-a' : '',
            $ids,
        ))),
    ));

    // Verify routing by checking which queues got a message.
    $hit = [];
    foreach (['queue-a', 'queue-b', 'queue-c', 'queue-d'] as $q) {
        if ($broker->getQueueStats($q)['message_count'] > 0) {
            $hit[] = $q;
            $broker->purge($q); // clean for next test
        }
    }
    sort($hit);
    sort($expected);
    $status = $hit === $expected ? 'PASS' : 'FAIL';
    echo "  rk='$routingKey' -> [" . implode(', ', $hit) . "], expected [" . implode(', ', $expected) . "] $status\n";
}

// Clean queues after tests.
foreach (['queue-a', 'queue-b', 'queue-c', 'queue-d'] as $q) {
    $broker->purge($q);
}
echo "\n";

// ============================================================
// 2. Fanout Exchange: broadcast to all bound queues.
// ============================================================
echo "=== 2. Fanout Exchange\n";
$registry->create('fanout-demo', ExchangeType::Fanout);
$registry->bind('fanout-demo', new Binding('queue-a'));
$registry->bind('fanout-demo', new Binding('queue-b'));
$registry->bind('fanout-demo', new Binding('queue-c'));

$ids = $broker->publish('fanout-demo', 'ignored', json_encode(['broadcast' => true], JSON_THROW_ON_ERROR));
$hit = [];
foreach (['queue-a', 'queue-b', 'queue-c', 'queue-d'] as $q) {
    $s = $broker->getQueueStats($q);
    if ($s['message_count'] > 0) {
        $hit[] = $q;
    }
    $broker->purge($q);
}

sort($hit);
$expected = ['queue-a', 'queue-b', 'queue-c'];
$status = $hit === $expected ? 'PASS' : 'FAIL';
echo "  Fanout hit: [" . implode(', ', $hit) . "], expected [" . implode(', ', $expected) . "] $status\n\n";

// ============================================================
// 3. Direct Exchange: exact routing key match.
// ============================================================
echo "=== 3. Direct Exchange\n";
$registry->create('direct-demo', ExchangeType::Direct);
$registry->bind('direct-demo', new Binding('queue-a', 'critical'));
$registry->bind('direct-demo', new Binding('queue-b', 'info'));
$registry->bind('direct-demo', new Binding('queue-c', 'info'));

foreach (['critical', 'info', 'debug'] as $rk) {
    $ids = $broker->publish('direct-demo', $rk, json_encode(['level' => $rk], JSON_THROW_ON_ERROR));
    $hit = [];
    foreach (['queue-a', 'queue-b', 'queue-c'] as $q) {
        if ($broker->getQueueStats($q)['message_count'] > 0) {
            $hit[] = $q;
        }
        $broker->purge($q);
    }
    sort($hit);
    echo "  rk='$rk' -> [" . implode(', ', $hit) . "]\n";
}
echo "\n";

// ============================================================
// 4. Headers Exchange: route by header key-value match.
// ============================================================
echo "=== 4. Headers Exchange\n";
$registry->create('headers-demo', ExchangeType::Headers);
$registry->bind('headers-demo', new Binding(
    queueName: 'queue-a',
    headerMatch: ['format' => 'json', 'priority' => 'high'],
    xmatch: 'all',
));
$registry->bind('headers-demo', new Binding(
    queueName: 'queue-b',
    headerMatch: ['format' => 'xml'],
    xmatch: 'any',
));
$registry->bind('headers-demo', new Binding(
    queueName: 'queue-c',
    headerMatch: ['priority' => 'high'],
    xmatch: 'any',
));

$testCases = [
    [['format' => 'json', 'priority' => 'high'], ['queue-a', 'queue-c']],       // matches both all+any
    [['format' => 'json'], []],            // no match: queue-c needs priority=high, queue-a needs both
    [['format' => 'xml'], ['queue-b']], // any match: format=xml
    [['type' => 'unknown'], []],        // no match
];

foreach ($testCases as [$headers, $expected]) {
    $ids = $broker->publish('headers-demo', '', json_encode(['hdr' => 'test'], JSON_THROW_ON_ERROR), ['headers' => $headers]);
    $hit = [];
    foreach (['queue-a', 'queue-b', 'queue-c'] as $q) {
        if ($broker->getQueueStats($q)['message_count'] > 0) {
            $hit[] = $q;
        }
        $broker->purge($q);
    }
    sort($hit);
    sort($expected);
    $status = $hit === $expected ? 'PASS' : 'FAIL';
    echo "  headers=" . json_encode($headers, JSON_THROW_ON_ERROR) . " -> [" . implode(', ', $hit) . "], expected [" . implode(', ', $expected) . "] $status\n";
}

// Cleanup exchange files + storage.
echo "\n=== Cleanup\n";
foreach ($registry->list() as $name) {
    $registry->delete($name);
}
echo "Done.\n";
