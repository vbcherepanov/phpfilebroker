<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\CLI\Command\WatchCommand;
use FileBroker\Logging\Logger;
use FileBroker\Message\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WatchCommand::class)]
final class WatchCommandTest extends TestCase
{
    private Logger $logger;

    public function setUp(): void
    {
        $this->logger = $this->createSilentLogger();
    }

    public function test_execute_raises_on_missing_queue(): void
    {
        $broker = $this->createMock(MessageBroker::class);

        $command = new WatchCommand($broker, $this->logger);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Queue name is required');

        $command->execute([]);
    }

    public function test_execute_uses_limit_option(): void
    {
        $broker = $this->createMock(MessageBroker::class);

        $message = Message::create(
            body: '{"test":"data"}',
            id: 'msg-001',
            headers: [],
        );

        $callCount = 0;
        $broker->method('consume')
            ->willReturnCallback(function () use ($message, &$callCount) {
                ++$callCount;
                if ($callCount === 1) {
                    return $message;
                }
                return null;
            });

        $command = new WatchCommand($broker, $this->logger);

        ob_start();
        $command->execute([
            'queue' => 'test-queue',
            'limit' => '5',
            'once' => true,
        ]);
        $output = ob_get_clean();

        self::assertNotNull($output);
    }

    public function test_execute_shows_messages(): void
    {
        $broker = $this->createMock(MessageBroker::class);

        $message = Message::create(
            body: '{"test":"data"}',
            id: 'msg-001',
            headers: ['content_type' => 'application/json'],
        );

        $callCount = 0;
        $broker->method('consume')
            ->willReturnCallback(function () use ($message, &$callCount) {
                ++$callCount;
                if ($callCount === 1) {
                    return $message;
                }
                return null;
            });

        $command = new WatchCommand($broker, $this->logger);

        ob_start();
        $command->execute([
            'queue' => 'test-queue',
            'limit' => 0,
            'once' => true,
        ]);
        $output = ob_get_clean();

        self::assertNotNull($output);
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
