<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\CLI\Command\DeadLetterCommand;
use FileBroker\Logging\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeadLetterCommand::class)]
final class DeadLetterCommandTest extends TestCase
{
    public function test_execute_delegates_to_broker(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->expects($this->once())
            ->method('deadLetter')
            ->with('test-queue', 'msg-001', 'CLI dead-letter');

        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Failed to open memory stream');
        }

        $logger = new Logger($stream);
        $command = new DeadLetterCommand($broker, $logger);
        $command->execute([
            'queue' => 'test-queue',
            'id' => 'msg-001',
            'reason' => 'CLI dead-letter',
        ]);

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($content);
        self::assertStringContainsString('moved to DLQ', $content);
    }

    public function test_execute_uses_default_reason(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->expects($this->once())
            ->method('deadLetter')
            ->with('test-queue', 'msg-001', 'CLI dead-letter');

        $command = new DeadLetterCommand($broker, $this->createSilentLogger());
        $command->execute([
            'queue' => 'test-queue',
            'id' => 'msg-001',
        ]);
    }

    public function test_execute_raises_on_missing_args(): void
    {
        $broker = $this->createMock(MessageBroker::class);

        $command = new DeadLetterCommand($broker, $this->createSilentLogger());
        $this->expectException(\InvalidArgumentException::class);

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
