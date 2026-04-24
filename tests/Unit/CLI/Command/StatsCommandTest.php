<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\CLI\Command\StatsCommand;
use FileBroker\Logging\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatsCommand::class)]
final class StatsCommandTest extends TestCase
{
    public function test_execute_logs_single_queue_stats(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('getQueueStats')
            ->with('test-queue')
            ->willReturn([
                'queue' => 'test-queue',
                'message_count' => 10,
                'retry_count' => 2,
                'dead_letter_count' => 1,
                'total_size_bytes' => 1024,
                'oldest_message' => 'msg-001',
                'newest_message' => 'msg-010',
            ]);

        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Failed to open memory stream');
        }

        $logger = new Logger($stream);
        $command = new StatsCommand($broker, $logger);
        $command->execute(['queue' => 'test-queue']);

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($content);
        self::assertStringContainsString('test-queue', $content);
        self::assertStringContainsString('10', $content);
        self::assertStringContainsString('2', $content);
        self::assertStringContainsString('1', $content);
    }

    public function test_execute_logs_all_queues_stats(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('listQueues')
            ->willReturn(['queue-1', 'queue-2']);
        $broker->method('getQueueStats')
            ->willReturn([
                'queue' => 'queue-1',
                'message_count' => 5,
                'retry_count' => 0,
                'dead_letter_count' => 0,
                'total_size_bytes' => 512,
                'oldest_message' => 'msg-001',
                'newest_message' => 'msg-005',
            ]);

        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            self::fail('Failed to open memory stream');
        }

        $logger = new Logger($stream);
        $command = new StatsCommand($broker, $logger);
        $command->execute([]);

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        self::assertIsString($content);
        self::assertStringContainsString('queue-1', $content);
        self::assertStringContainsString('queue-2', $content);
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
