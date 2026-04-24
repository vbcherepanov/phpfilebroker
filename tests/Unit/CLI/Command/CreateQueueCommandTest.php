<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\CLI\Command\CreateQueueCommand;
use FileBroker\Config\BrokerConfig;
use FileBroker\Config\QueueConfig;
use FileBroker\Logging\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateQueueCommand::class)]
final class CreateQueueCommandTest extends TestCase
{
    private string $testDir;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/file-broker-create-queue-test-' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function test_execute_creates_queue(): void
    {
        $config = new BrokerConfig(
            storagePath: $this->testDir,
            defaultQueue: null,
            lockTimeout: 10,
            pollInterval: 1,
            maxWorkers: 1,
            queues: [],
        );

        $broker = $this->createMock(MessageBroker::class);
        $broker->method('getConfig')
            ->willReturn($config);
        $broker->method('hasQueue')
            ->with('new-queue')
            ->willReturn(false);
        $broker->expects($this->once())
            ->method('createQueue')
            ->with($this->callback(function (QueueConfig $qc) {
                return $qc->name === 'new-queue';
            }));

        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Failed to open memory stream');
        }

        $logger = new Logger($stream);
        $command = new CreateQueueCommand($broker, $logger);
        $command->execute(['name' => 'new-queue']);

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($content);
        self::assertStringContainsString('created', $content);
    }

    public function test_execute_logs_error_for_existing_queue(): void
    {
        $config = new BrokerConfig(
            storagePath: $this->testDir,
            defaultQueue: null,
            lockTimeout: 10,
            pollInterval: 1,
            maxWorkers: 1,
            queues: ['existing' => new QueueConfig('existing', $this->testDir . '/existing')],
        );

        $broker = $this->createMock(MessageBroker::class);
        $broker->method('getConfig')
            ->willReturn($config);
        $broker->method('hasQueue')
            ->with('existing')
            ->willReturn(true);

        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Failed to open memory stream');
        }

        $logger = new Logger($stream);
        $command = new CreateQueueCommand($broker, $logger);
        $command->execute(['name' => 'existing']);

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($content);
        self::assertStringContainsString('already exists', $content);
    }

    public function test_execute_raises_on_missing_name(): void
    {
        $broker = $this->createMock(MessageBroker::class);

        $command = new CreateQueueCommand($broker, $this->createSilentLogger());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Queue name is required');

        $command->execute([]);
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
