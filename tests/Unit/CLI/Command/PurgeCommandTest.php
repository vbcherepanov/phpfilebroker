<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\CLI\Command\PurgeCommand;
use FileBroker\Logging\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PurgeCommand::class)]
final class PurgeCommandTest extends TestCase
{
    public function test_execute_purges_queue(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('hasQueue')
            ->with('test-queue')
            ->willReturn(true);
        $broker->expects($this->once())
            ->method('purge')
            ->with('test-queue');

        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Failed to open memory stream');
        }

        $logger = new Logger($stream);
        $command = new PurgeCommand($broker, $logger);
        $command->execute(['queue' => 'test-queue']);

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($content);
        self::assertStringContainsString('purged', $content);
    }

    public function test_execute_logs_error_for_nonexistent_queue(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('hasQueue')
            ->with('nonexistent')
            ->willReturn(false);

        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Failed to open memory stream');
        }

        $logger = new Logger($stream);
        $command = new PurgeCommand($broker, $logger);
        $command->execute(['queue' => 'nonexistent']);

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($content);
        self::assertStringContainsString('does not exist', $content);
    }

    public function test_execute_raises_on_missing_queue(): void
    {
        $broker = $this->createMock(MessageBroker::class);

        $command = new PurgeCommand($broker, $this->createSilentLogger());
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
}
