<?php

declare(strict_types=1);

namespace FileBroker\Tests\Integration\Stream;

use FileBroker\Broker\MessageBroker;
use FileBroker\Config\BrokerConfig;
use FileBroker\Config\QueueConfig;
use FileBroker\Stream\StreamConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageBroker::class)]
final class StreamIntegrationTest extends TestCase
{
    private string $testDir;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/file-broker-stream-test-' . bin2hex(random_bytes(4));
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
            name: 'stream-queue',
            basePath: $this->testDir,
            defaultTtlSeconds: null,
            maxRetryAttempts: 3,
            retryDelaySeconds: 60,
            enableDeadLetter: true,
        ));

        return new MessageBroker($config);
    }

    public function test_stream_consume_and_acknowledge(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        // Enable stream mode
        $broker->enableStream('stream-queue', new StreamConfig(
            queueName: 'stream-queue',
            enabled: true,
        ));

        // Produce a message
        $broker->produce('stream-queue', json_encode(['data' => 'hello']));

        // Consume via stream
        $result = $broker->streamConsume('stream-queue', 'group-1');
        self::assertNotNull($result, 'Should consume a message');
        self::assertSame(json_encode(['data' => 'hello']), $result['body']);
        self::assertSame(0, $result['offset']);

        // Acknowledge the offset
        $broker->streamAcknowledge('stream-queue', 'group-1', $result['offset']);

        // Consume again — should return null (nothing new)
        $second = $broker->streamConsume('stream-queue', 'group-1');
        self::assertNull($second, 'Should not return the same message after ack');
    }

    public function test_stream_replay_from_offset(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        $broker->enableStream('stream-queue', new StreamConfig(
            queueName: 'stream-queue',
            enabled: true,
        ));

        // Produce 5 messages
        for ($i = 0; $i < 5; $i++) {
            // Small delay to ensure different filemtime
            usleep(1000);
            $broker->produce('stream-queue', json_encode(['index' => $i]));
        }

        // Replay from offset 0 — should return all 5
        $all = $broker->streamReplay('stream-queue', 'group-2', 0);
        self::assertCount(5, $all);

        // Replay from offset 2 — should return 3 messages (indices 2, 3, 4)
        $subset = $broker->streamReplay('stream-queue', 'group-2', 2);
        self::assertCount(3, $subset);
        self::assertSame(2, $subset[0]['offset']);
        self::assertSame(3, $subset[1]['offset']);
        self::assertSame(4, $subset[2]['offset']);

        // Replay from offset 2 with limit 2
        $limited = $broker->streamReplay('stream-queue', 'group-2', 2, 2);
        self::assertCount(2, $limited);
        self::assertSame(2, $limited[0]['offset']);
        self::assertSame(3, $limited[1]['offset']);
    }

    public function test_stream_retention_cleanup(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        $broker->enableStream('stream-queue', new StreamConfig(
            queueName: 'stream-queue',
            enabled: true,
            maxRetentionSeconds: 1, // Very short retention for testing
        ));

        // Produce 3 messages
        for ($i = 0; $i < 3; $i++) {
            usleep(1000);
            $broker->produce('stream-queue', json_encode(['index' => $i]));
        }

        // Wait for retention to expire
        sleep(2);

        $stats = $broker->streamStats('stream-queue');
        self::assertSame(3, $stats['total_messages'], 'Should have 3 messages before cleanup');

        // Enforce retention through the stream directly
        $stream = $broker->getStream('stream-queue');
        self::assertNotNull($stream);
        $deleted = $stream->enforceRetention();

        self::assertGreaterThan(0, $deleted, 'Should have deleted expired messages');

        // Stats should reflect cleanup
        $statsAfter = $broker->streamStats('stream-queue');
        self::assertLessThan(3, $statsAfter['total_messages'], 'Should have fewer messages after retention cleanup');
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
