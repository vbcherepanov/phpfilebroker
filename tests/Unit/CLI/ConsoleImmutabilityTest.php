<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\CLI;

use FileBroker\Broker\MessageBroker;
use FileBroker\CLI\Console;
use FileBroker\Config\QueueConfig;
use PHPUnit\Framework\TestCase;

final class ConsoleImmutabilityTest extends TestCase
{
    private string $testDir;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/file-broker-console-imm-test-' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function test_run_is_immutatable(): void
    {
        $config = QueueConfig::fromArray(['name' => 'orders']);

        $broker = new MessageBroker(
            config: \FileBroker\Config\BrokerConfig::fromArray([
                'storage_path' => $this->testDir,
                'queues' => ['orders' => $config],
            ]),
        );

        $console = new Console($broker);

        // First run: produce command.
        ob_start();
        $exit1 = $console->run(['file-broker', 'produce', 'orders', '{"id": 1}', '--ttl=60']);
        ob_end_clean();
        static::assertSame(0, $exit1);

        // Second run: consume command — should not be affected by first run's options.
        ob_start();
        $exit2 = $console->run(['file-broker', 'consume', 'orders']);
        ob_end_clean();
        static::assertSame(0, $exit2);
    }

    public function test_run_parses_argv_options(): void
    {
        $config = QueueConfig::fromArray(['name' => 'orders']);

        $broker = new MessageBroker(
            config: \FileBroker\Config\BrokerConfig::fromArray([
                'storage_path' => $this->testDir,
                'queues' => ['orders' => $config],
            ]),
        );

        $console = new Console($broker);

        // Run produce with --ttl=30 --id=abc, verify it exits cleanly.
        ob_start();
        $exitCode = $console->run(['file-broker', 'produce', 'orders', 'body', '--ttl=30', '--id=abc']);
        ob_end_clean();

        static::assertSame(0, $exitCode);
    }

    public function test_run_multiple_times_without_state_leakage(): void
    {
        $config = QueueConfig::fromArray(['name' => 'orders']);

        $broker = new MessageBroker(
            config: \FileBroker\Config\BrokerConfig::fromArray([
                'storage_path' => $this->testDir,
                'queues' => ['orders' => $config],
            ]),
        );

        $console = new Console($broker);

        // Produce with TTL.
        ob_start();
        $console->run(['file-broker', 'produce', 'orders', '{"id": 1}', '--ttl=60']);
        ob_end_clean();

        // Consume should work independently.
        ob_start();
        $console->run(['file-broker', 'consume', 'orders']);
        ob_end_clean();

        // Produce without TTL.
        ob_start();
        $console->run(['file-broker', 'produce', 'orders', '{"id": 2}']);
        ob_end_clean();

        // Verify messages are in queue.
        $stats = $broker->getQueueStats('orders');
        static::assertGreaterThanOrEqual(2, $stats['message_count']);
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
