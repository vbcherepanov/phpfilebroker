<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\CLI\Command\DeleteQueueCommand;
use FileBroker\Logging\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeleteQueueCommand::class)]
final class DeleteQueueCommandTest extends TestCase
{
    public function test_execute_deletes_queue(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('hasQueue')
            ->with('test-queue')
            ->willReturn(true);
        $broker->expects($this->once())
            ->method('deleteQueue')
            ->with('test-queue');

        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Failed to open memory stream');
        }

        $logger = new Logger($stream);
        $command = new DeleteQueueCommand($broker, $logger);
        $command->execute(['name' => 'test-queue']);

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($content);
        self::assertStringContainsString('deleted', $content);
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
        $command = new DeleteQueueCommand($broker, $logger);
        $command->execute(['name' => 'nonexistent']);

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($content);
        self::assertStringContainsString('does not exist', $content);
    }

    public function test_execute_raises_on_missing_name(): void
    {
        $broker = $this->createMock(MessageBroker::class);

        $command = new DeleteQueueCommand($broker, $this->createSilentLogger());
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
