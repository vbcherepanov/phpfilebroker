<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\CLI\Command\ConsumeCommand;
use FileBroker\Logging\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsumeCommand::class)]
final class ConsumeCommandTest extends TestCase
{
    public function test_execute_returns_message(): void
    {
        $expectedMessage = \FileBroker\Message\Message::create(
            body: '{"test":"data"}',
            id: 'msg-001',
            headers: ['content_type' => 'application/json'],
        );

        $broker = $this->createMock(MessageBroker::class);
        $broker->method('consume')
            ->willReturn($expectedMessage);

        $command = new ConsumeCommand($broker, $this->createSilentLogger());
        $result = $command->execute(['queue' => 'test-queue']);

        self::assertSame($expectedMessage, $result);
        self::assertSame('msg-001', $result->id);
    }

    public function test_execute_returns_null_on_empty_queue(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('consume')
            ->willReturn(null);

        $command = new ConsumeCommand($broker, $this->createSilentLogger());
        $result = $command->execute(['queue' => 'empty-queue']);

        self::assertNull($result);
    }

    public function test_execute_raises_on_missing_queue(): void
    {
        $broker = $this->createMock(MessageBroker::class);

        $command = new ConsumeCommand($broker, $this->createSilentLogger());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Queue name is required');

        $command->execute([]);
    }

    public function test_execute_logs_empty_queue_message(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('consume')
            ->willReturn(null);

        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Failed to open memory stream');
        }

        $logger = new Logger($stream);
        $command = new ConsumeCommand($broker, $logger);
        $result = $command->execute(['queue' => 'empty-queue']);

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($content);
        self::assertStringContainsString('empty-queue', $content);
        self::assertStringContainsString('is empty', $content);
        self::assertNull($result);
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
