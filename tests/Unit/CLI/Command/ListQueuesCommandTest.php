<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\CLI\Command\ListQueuesCommand;
use FileBroker\Logging\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListQueuesCommand::class)]
final class ListQueuesCommandTest extends TestCase
{
    public function test_execute_logs_empty_list(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('listQueues')
            ->willReturn([]);

        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Failed to open memory stream');
        }

        $logger = new Logger($stream);
        $command = new ListQueuesCommand($broker, $logger);
        $command->execute();

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($content);
        self::assertStringContainsString('No queues configured', $content);
    }

    public function test_execute_logs_queues(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('listQueues')
            ->willReturn(['orders', 'emails', 'notifications']);

        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Failed to open memory stream');
        }

        $logger = new Logger($stream);
        $command = new ListQueuesCommand($broker, $logger);
        $command->execute();

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($content);
        self::assertStringContainsString('orders', $content);
        self::assertStringContainsString('emails', $content);
        self::assertStringContainsString('notifications', $content);
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
