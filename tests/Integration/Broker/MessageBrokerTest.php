<?php

declare(strict_types=1);

namespace FileBroker\Tests\Integration\Broker;

use FileBroker\Broker\MessageBroker;
use FileBroker\Config\BrokerConfig;
use FileBroker\Config\QueueConfig;
use FileBroker\Event\MessageConsumedEvent;
use FileBroker\Event\MessageProducedEvent;
use FileBroker\Message\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageBroker::class)]
final class MessageBrokerTest extends TestCase
{
    private string $testDir;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/file-broker-integration-' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    private function createBroker(): MessageBroker
    {
        $config = BrokerConfig::default();
        $config = $config->withQueue(new QueueConfig(
            name: 'test-queue',
            basePath: $this->testDir . '/test-queue',
            defaultTtlSeconds: null,
            maxRetryAttempts: 3,
            retryDelaySeconds: 60,
            enableDeadLetter: true,
        ));
        $config = $config->withQueue(new QueueConfig(
            name: 'dlq-queue',
            basePath: $this->testDir . '/dlq-queue',
            enableDeadLetter: false,
        ));

        return new MessageBroker($config);
    }

    public function test_produce_and_consume(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        $body = json_encode(['order_id' => 123, 'item' => 'laptop']);
        $message = $broker->produce(
            queueName: 'test-queue',
            body: $body,
            messageId: 'test-msg-001',
            headers: ['content_type' => 'application/json'],
        );

        self::assertSame('test-msg-001', $message->id);
        self::assertSame(0, $message->deliveryCount);

        $consumed = $broker->consume('test-queue');
        self::assertNotNull($consumed);
        self::assertSame('test-msg-001', $consumed->id);
        self::assertSame($body, $consumed->body);
        self::assertSame(1, $consumed->deliveryCount);
    }

    public function test_consume_empty_queue_returns_null(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        $message = $broker->consume('test-queue');
        self::assertNull($message);
    }

    public function test_acknowledge_removes_message(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        $broker->produce('test-queue', json_encode(['test' => 'data']));

        $consumed = $broker->consume('test-queue');
        self::assertNotNull($consumed);

        $broker->acknowledge('test-queue', $consumed->id);

        $remaining = $broker->consume('test-queue');
        self::assertNull($remaining);
    }

    public function test_list_queues(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        $queues = $broker->listQueues();
        self::assertCount(2, $queues);
        self::assertContains('test-queue', $queues);
        self::assertContains('dlq-queue', $queues);
    }

    public function test_has_queue(): void
    {
        $broker = $this->createBroker();

        self::assertTrue($broker->hasQueue('test-queue'));
        self::assertFalse($broker->hasQueue('nonexistent'));
    }

    public function test_get_queue_stats(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        // Produce some messages
        for ($i = 0; $i < 3; $i++) {
            $broker->produce('test-queue', json_encode(['index' => $i]));
        }

        $stats = $broker->getQueueStats('test-queue');

        self::assertSame('test-queue', $stats['queue']);
        self::assertSame(3, $stats['message_count']);
        self::assertGreaterThan(0, $stats['total_size_bytes']);
        self::assertNotNull($stats['oldest_message']);
        self::assertNotNull($stats['newest_message']);
        self::assertSame(0, $stats['retry_count']);
        self::assertSame(0, $stats['dead_letter_count']);
    }

    public function test_purge_queue(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        $broker->produce('test-queue', json_encode(['test' => 'data']));
        $broker->produce('test-queue', json_encode(['test' => 'data2']));

        $stats = $broker->getQueueStats('test-queue');
        self::assertSame(2, $stats['message_count']);

        $broker->purge('test-queue');

        $stats = $broker->getQueueStats('test-queue');
        self::assertSame(0, $stats['message_count']);
    }

    public function test_create_and_delete_queue(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        $newQueue = new QueueConfig(
            name: 'dynamic-queue',
            basePath: $this->testDir . '/dynamic-queue',
        );

        $broker->createQueue($newQueue);
        self::assertTrue($broker->hasQueue('dynamic-queue'));

        $broker->deleteQueue('dynamic-queue');
        self::assertFalse($broker->hasQueue('dynamic-queue'));
    }

    public function test_produce_to_nonexistent_queue_throws(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        self::expectException(\FileBroker\Exception\QueueNotFoundException::class);
        $broker->produce('nonexistent', json_encode(['test' => 'data']));
    }

    public function test_delete_nonexistent_queue_throws(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        self::expectException(\FileBroker\Exception\QueueNotFoundException::class);
        $broker->deleteQueue('nonexistent');
    }

    public function test_message_with_ttl_expires(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        // Create message that's already expired
        $message = Message::create(
            body: json_encode(['test' => 'expired']),
            ttlSeconds: -1,
        );

        $broker->produce('test-queue', $message->body, $message->id, ttl: -1);

        $consumed = $broker->consume('test-queue');
        // Expired message should be moved to DLQ, not returned
        self::assertNull($consumed);
    }

    public function test_events_are_dispatched(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        $producedEvents = [];
        $consumedEvents = [];

        $broker->getEventDispatcher()->subscribe(
            MessageProducedEvent::class,
            static function (MessageProducedEvent $e) use (&$producedEvents): void {
                $producedEvents[] = $e;
            },
        );

        $broker->getEventDispatcher()->subscribe(
            MessageConsumedEvent::class,
            static function (MessageConsumedEvent $e) use (&$consumedEvents): void {
                $consumedEvents[] = $e;
            },
        );

        $broker->produce('test-queue', json_encode(['test' => 'data']), 'event-msg-001');
        self::assertCount(1, $producedEvents);
        self::assertSame('event-msg-001', $producedEvents[0]->message->id);

        $broker->consume('test-queue');
        self::assertCount(1, $consumedEvents);
    }

    public function test_consumer_interface(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        $consumer = $broker->getConsumer();

        self::assertFalse($consumer->hasMessages('test-queue'));

        $broker->produce('test-queue', json_encode(['test' => 'data']));
        self::assertTrue($consumer->hasMessages('test-queue'));

        $processed = $consumer->process('test-queue', function (Message $msg): bool {
            self::assertSame(json_encode(['test' => 'data']), $msg->body);
            return true;
        });

        self::assertSame(1, $processed);
        self::assertFalse($consumer->hasMessages('test-queue'));
    }

    public function test_producer_interface(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        $producer = $broker->getProducer();

        $message = $producer->send(
            'test-queue',
            json_encode(['batch' => true]),
            headers: ['content_type' => 'application/json'],
            ttlSeconds: 3600,
        );

        self::assertNotNull($message->id);

        $batch = $producer->sendBatch('test-queue', [
            json_encode(['item' => 1]),
            json_encode(['item' => 2]),
            json_encode(['item' => 3]),
        ]);

        self::assertCount(3, $batch);
        foreach ($batch as $msg) {
            self::assertNotNull($msg->id);
        }
    }

    public function test_consume_with_process_callback_stops_on_false(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        // Produce 2 messages with delay to ensure FIFO order by filemtime
        $broker->produce('test-queue', json_encode(['index' => 0]));
        sleep(1);
        $broker->produce('test-queue', json_encode(['index' => 1]));
        sleep(1);
        $broker->produce('test-queue', json_encode(['index' => 2]));

        $processed = $broker->getConsumer()->process('test-queue', function (Message $msg): bool {
            if ($msg->body === json_encode(['index' => 2])) {
                return false; // Stop at index 2
            }
            return true;
        });

        // Should process messages 0, 1 (acknowledged) and stop at 2 (not acknowledged)
        self::assertSame(2, $processed);
    }

    public function testConsumeMovesExpiredRetryBackToQueue(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        // Create a retry message with _broker_retry_at in the past
        $retryPath = $broker->getConfig()->queues['test-queue']->retryPath();
        $message = Message::create(
            body: json_encode(['retry' => 'test']),
            id: 'retry-msg-001',
            headers: [
                '_broker_retry_reason' => 'test',
                '_broker_retry_at' => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeImmutable::ATOM),
            ],
        );

        $destPath = $retryPath . '/' . $message->id . '_' . time() . '_' . bin2hex(random_bytes(4));
        $payloadFactory = new \FileBroker\Message\MessagePayloadFactory();
        file_put_contents($destPath, $payloadFactory->toJson($message));

        $consumed = $broker->consume('test-queue');
        self::assertNotNull($consumed, 'Should consume a message from expired retry');
        self::assertSame($message->body, $consumed->body);
        self::assertArrayNotHasKey('_broker_retry_reason', $consumed->headers);
        self::assertArrayNotHasKey('_broker_retry_at', $consumed->headers);
    }

    public function testConsumeSkipsFutureRetry(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        // Create a retry message with _broker_retry_at in the future
        $retryPath = $broker->getConfig()->queues['test-queue']->retryPath();
        $message = Message::create(
            body: json_encode(['retry' => 'future']),
            id: 'retry-msg-002',
            headers: [
                '_broker_retry_reason' => 'test',
                '_broker_retry_at' => (new \DateTimeImmutable('+1 hour'))->format(\DateTimeImmutable::ATOM),
            ],
        );

        $destPath = $retryPath . '/' . $message->id . '_' . time() . '_' . bin2hex(random_bytes(4));
        $payloadFactory = new \FileBroker\Message\MessagePayloadFactory();
        file_put_contents($destPath, $payloadFactory->toJson($message));

        $consumed = $broker->consume('test-queue');
        self::assertNull($consumed, 'Should skip retry messages with future retry_at');
    }

    public function testDeleteQueueDoesNotMutateConfig(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        $configBefore = $broker->getConfig();
        $queueNamesBefore = array_keys($configBefore->queues);
        sort($queueNamesBefore);

        $broker->deleteQueue('test-queue');

        // Verify the broker's config no longer has the deleted queue
        self::assertFalse($broker->hasQueue('test-queue'));

        // Verify the original config object was not mutated
        $configAfterOriginal = $broker->getConfig();
        self::assertNotSame($configBefore, $configAfterOriginal, 'Config should be a new instance');
        self::assertFalse($broker->hasQueue('test-queue'));
    }

    public function test_consume_respects_priority_order(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        // Produce in reverse priority order. Low number = high priority.
        $broker->produce('test-queue', json_encode(['p' => 50]), priority: 50);
        usleep(10000); // Ensure different filemtime for ordering
        $broker->produce('test-queue', json_encode(['p' => 10]), priority: 10);
        usleep(10000);
        $broker->produce('test-queue', json_encode(['p' => 0]), priority: 0);

        // Consume: highest priority (0) must come first
        $first = $broker->consume('test-queue');
        self::assertNotNull($first, 'Should consume first message');
        self::assertSame(0, $first->priority);
        self::assertSame(json_encode(['p' => 0]), $first->body);
        $broker->acknowledge('test-queue', $first->id);

        $second = $broker->consume('test-queue');
        self::assertNotNull($second, 'Should consume second message');
        self::assertSame(10, $second->priority);
        $broker->acknowledge('test-queue', $second->id);

        $third = $broker->consume('test-queue');
        self::assertNotNull($third, 'Should consume third message');
        self::assertSame(50, $third->priority);
        $broker->acknowledge('test-queue', $third->id);

        $none = $broker->consume('test-queue');
        self::assertNull($none, 'Queue should be empty');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
