<?php

declare(strict_types=1);

namespace FileBroker\Tests\Integration\Broker;

use FileBroker\Broker\MessageBroker;
use FileBroker\Config\BrokerConfig;
use FileBroker\Config\QueueConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageBroker::class)]
final class BatchIntegrationTest extends TestCase
{
    private string $testDir;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/file-broker-batch-' . bin2hex(random_bytes(4));
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
            name: 'batch-queue',
            basePath: $this->testDir . '/batch-queue',
        ));

        return new MessageBroker($config);
    }

    public function test_produce_batch_returns_correct_ids(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        $ids = $broker->produceBatch('batch-queue', [
            ['body' => json_encode(['item' => 1])],
            ['body' => json_encode(['item' => 2])],
            ['body' => json_encode(['item' => 3])],
        ]);

        self::assertCount(3, $ids);
        self::assertIsString($ids[0]);
        self::assertNotEmpty($ids[0]);

        $stats = $broker->getQueueStats('batch-queue');
        self::assertSame(3, $stats['message_count']);
    }

    public function test_acknowledge_batch_removes_all_messages(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        $ids = $broker->produceBatch('batch-queue', [
            ['body' => json_encode(['a' => 1])],
            ['body' => json_encode(['b' => 2])],
            ['body' => json_encode(['c' => 3])],
        ]);

        self::assertCount(3, $ids);

        $broker->acknowledgeBatch('batch-queue', $ids);

        $stats = $broker->getQueueStats('batch-queue');
        self::assertSame(0, $stats['message_count']);
    }

    public function test_produce_batch_maintains_priority_order(): void
    {
        $broker = $this->createBroker();
        $broker->ensureInitialized();

        // Produce messages with different priorities. Lower priority = higher precedence.
        $broker->produceBatch('batch-queue', [
            ['body' => json_encode(['low']), 'priority' => 10],
            ['body' => json_encode(['medium']), 'priority' => 5],
            ['body' => json_encode(['high']), 'priority' => 0],
        ]);

        // Consume should return highest priority first (priority 0)
        $first = $broker->consume('batch-queue');
        self::assertNotNull($first);
        self::assertSame(json_encode(['high']), $first->body);
        self::assertSame(0, $first->priority);
        $broker->acknowledge('batch-queue', $first->id);

        $second = $broker->consume('batch-queue');
        self::assertNotNull($second);
        self::assertSame(json_encode(['medium']), $second->body);
        self::assertSame(5, $second->priority);
        $broker->acknowledge('batch-queue', $second->id);

        $third = $broker->consume('batch-queue');
        self::assertNotNull($third);
        self::assertSame(json_encode(['low']), $third->body);
        self::assertSame(10, $third->priority);
        $broker->acknowledge('batch-queue', $third->id);

        $none = $broker->consume('batch-queue');
        self::assertNull($none);
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
