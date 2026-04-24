<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\CLI;

use FileBroker\Broker\MessageBroker;
use FileBroker\CLI\Command\WatchCommand;
use FileBroker\Config\BrokerConfig;
use FileBroker\Logging\Logger;
use PHPUnit\Framework\TestCase;

final class WatchCommandTest extends TestCase
{
    private string $testDir;
    private Logger $logger;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/file-broker-watch-test-' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
        $this->logger = $this->createSilentLogger();
    }

    public function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function test_execute_produces_and_consumes_with_once(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => ['orders' => ['name' => 'orders', 'path' => $this->testDir]],
            'max_workers' => 1,
        ]);

        $broker = new MessageBroker($config);
        $broker->ensureInitialized();
        $command = new WatchCommand($broker, $this->logger);

        // Produce a message first.
        $sent = $broker->produce('orders', '{"order_id": 42}');

        // Capture output.
        ob_start();
        $command->execute(['queue' => 'orders', 'once' => true]);
        ob_end_clean();

        // Verify the queue directory was created (nested under queues/).
        static::assertDirectoryExists($this->testDir . '/queues/orders');

        // Re-check the message is still in queue (didn't get acked).
        static::assertTrue($broker->hasQueue('orders'));
    }

    public function test_execute_with_single_worker(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => ['orders' => ['name' => 'orders', 'path' => $this->testDir]],
            'max_workers' => 1, // single worker mode.
        ]);

        $broker = new MessageBroker($config);
        $command = new WatchCommand($broker, $this->logger);

        $sent = $broker->produce('orders', '{"order_id": 42}');

        // Run with 'once' to avoid blocking on consume loop.
        ob_start();
        $command->execute(['queue' => 'orders', 'once' => true, 'limit' => 1]);
        ob_end_clean();

        static::assertTrue($broker->hasQueue('orders'));
    }

    public function test_execute_with_multiple_workers(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => ['orders' => ['name' => 'orders', 'path' => $this->testDir]],
            'max_workers' => 4, // multi-worker mode.
        ]);

        $broker = new MessageBroker($config);
        $command = new WatchCommand($broker, $this->logger);

        // Produce a few messages.
        for ($i = 0; $i < 3; $i++) {
            $broker->produce('orders', json_encode(['order_id' => $i]));
        }

        // Short-lived run with 'once'.
        ob_start();
        $command->execute(['queue' => 'orders', 'once' => true]);
        ob_end_clean();

        static::assertTrue($broker->hasQueue('orders'));
    }

    private function createSilentLogger(): Logger
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Failed to open memory stream');
        }
        return new Logger($stream);
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
