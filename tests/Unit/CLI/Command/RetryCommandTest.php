<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\CLI\Command\RetryCommand;
use FileBroker\Config\BrokerConfig;
use FileBroker\Config\QueueConfig;
use FileBroker\Logging\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetryCommand::class)]
final class RetryCommandTest extends TestCase
{
    private string $testDir;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/file-broker-retry-cmd-test-' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function test_execute_raises_on_missing_args(): void
    {
        $broker = $this->createMock(MessageBroker::class);

        $command = new RetryCommand($broker, $this->createSilentLogger());
        $this->expectException(\InvalidArgumentException::class);

        $command->execute([]);
    }

    public function test_execute_raises_on_missing_queue(): void
    {
        $broker = $this->createMock(MessageBroker::class);

        $command = new RetryCommand($broker, $this->createSilentLogger());
        $this->expectException(\InvalidArgumentException::class);

        $command->execute(['id' => 'msg-001']);
    }

    public function test_execute_raises_on_missing_id(): void
    {
        $config = new BrokerConfig(
            storagePath: $this->testDir,
            defaultQueue: null,
            lockTimeout: 10,
            pollInterval: 1,
            maxWorkers: 1,
            queues: [
                'test-queue' => new QueueConfig('test-queue', $this->testDir . '/test-queue'),
            ],
        );

        $broker = $this->createMock(MessageBroker::class);
        $broker->method('getConfig')->willReturn($config);

        $command = new RetryCommand($broker, $this->createSilentLogger());
        $this->expectException(\InvalidArgumentException::class);

        $command->execute(['queue' => 'test-queue']);
    }

    private function createSilentLogger(): Logger
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Failed to open memory stream');
        }
        return new Logger($stream);
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
