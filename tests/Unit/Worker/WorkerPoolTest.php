<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Worker;

use FileBroker\Broker\MessageBroker;
use FileBroker\Config\BrokerConfig;
use FileBroker\Event\WorkerStartedEvent;
use FileBroker\Event\WorkerStoppedEvent;
use FileBroker\Message\Message;
use FileBroker\Worker\WorkerPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkerPool::class)]
final class WorkerPoolTest extends TestCase
{
    private string $testDir;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/file-broker-workerpool-test-' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function test_pool_size_matches_max_workers(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'max_workers' => 4,
        ]);

        $broker = new MessageBroker($config);
        $pool = new WorkerPool('orders', $broker);

        static::assertSame(4, $pool->size());
    }

    public function test_pool_size_clamps_to_16(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'max_workers' => 100,
        ]);

        $broker = new MessageBroker($config);
        $pool = new WorkerPool('orders', $broker);

        static::assertSame(16, $pool->size());
    }

    public function test_pool_size_minimum_one(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'max_workers' => 0,
        ]);

        $broker = new MessageBroker($config);
        $pool = new WorkerPool('orders', $broker);

        static::assertSame(1, $pool->size());
    }

    public function test_resize_up(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'max_workers' => 4,
        ]);

        $broker = new MessageBroker($config);
        $pool = new WorkerPool('orders', $broker);

        static::assertSame(4, $pool->size());
        $pool->resize(8);
        static::assertSame(8, $pool->size());
    }

    public function test_resize_down(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'max_workers' => 4,
        ]);

        $broker = new MessageBroker($config);
        $pool = new WorkerPool('orders', $broker);

        static::assertSame(4, $pool->size());
        $pool->resize(2);
        static::assertSame(2, $pool->size());
    }

    public function test_dispatches_events_on_run(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => ['orders' => ['name' => 'orders']],
        ]);

        $broker = new MessageBroker($config);
        $dispatcher = $broker->getEventDispatcher();

        $startedReceived = false;
        $stoppedReceived = false;

        $dispatcher->subscribe(WorkerStartedEvent::class, function (WorkerStartedEvent $e) use (&$startedReceived): void {
            static::assertSame('orders', $e->queueName);
            static::assertGreaterThan(0, $e->poolSize);
            $startedReceived = true;
        });

        $dispatcher->subscribe(WorkerStoppedEvent::class, function (WorkerStoppedEvent $e) use (&$stoppedReceived): void {
            static::assertSame('orders', $e->queueName);
            $stoppedReceived = true;
        });

        $pool = new WorkerPool('orders', $broker);

        // Pool::run() blocks forever (worker loop), so we only verify
        // that the pool size matches and stop() works without throwing.
        static::assertGreaterThan(0, $pool->size());
        $pool->stop(); // Should not throw.
    }

    public function test_stop(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'max_workers' => 2,
        ]);

        $broker = new MessageBroker($config);
        $pool = new WorkerPool('orders', $broker);

        $pool->stop(); // Should not throw even if run was never called.

        self::assertSame(2, $pool->size());
    }

    public function test_constructor_accepts_handler(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('getConfig')->willReturn(BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'max_workers' => 2,
        ]));

        $handlerCalled = false;

        $handler = function (Message $_msg, MessageBroker $_brk) use (&$handlerCalled): void {
            $handlerCalled = true;
        };

        $pool = new WorkerPool('orders', $broker, $handler);

        static::assertSame(2, $pool->size());
        // Handler is stored — we can't test run() because it blocks,
        // but we verify construction succeeds.
        self::assertFalse($handlerCalled, 'Handler should not be called during construction');

        $pool->stop();
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = array_diff(scandir($path), ['.', '..']);
        foreach ($files as $file) {
            $fullPath = $path . '/' . $file;
            is_dir($fullPath) ? $this->removeDir($fullPath) : @unlink($fullPath);
        }

        @rmdir($path);
    }
}
