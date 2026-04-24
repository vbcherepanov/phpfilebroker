<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\CLI\Command\ProduceCommand;
use FileBroker\Logging\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProduceCommand::class)]
final class ProduceCommandTest extends TestCase
{
    private string $testDir;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/file-broker-produce-cmd-test-' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function test_execute_produces_message(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('produce')
            ->willReturnCallback(function (string $queueName, string $body, ?string $messageId = null, ?array $headers = null, ?int $ttl = null) {
                $message = \FileBroker\Message\Message::create(
                    body: $body,
                    id: $messageId ?? 'msg-001',
                    headers: $headers ?? [],
                );

                return $message;
            });

        $command = new ProduceCommand($broker, $this->createSilentLogger());
        $message = $command->execute([
            'queue' => 'test-queue',
            'body' => '{"test":"data"}',
        ]);

        self::assertNotNull($message);
        self::assertSame('msg-001', $message->id);
        self::assertSame('{"test":"data"}', $message->body);
    }

    public function test_execute_with_custom_id(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('produce')
            ->willReturnCallback(function (string $queueName, string $body, ?string $messageId = null, ?array $headers = null, ?int $ttl = null) {
                if ($messageId !== 'custom-id-123') {
                    throw new \InvalidArgumentException('Expected custom-id-123');
                }

                return \FileBroker\Message\Message::create(
                    body: $body,
                    id: 'custom-id-123',
                    headers: $headers ?? [],
                );
            });

        $command = new ProduceCommand($broker, $this->createSilentLogger());
        $message = $command->execute([
            'queue' => 'test-queue',
            'body' => 'test body',
            'id' => 'custom-id-123',
        ]);

        self::assertNotNull($message);
        self::assertSame('custom-id-123', $message->id);
    }

    public function test_execute_with_ttl(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('produce')
            ->willReturnCallback(function (string $queueName, string $body, ?string $messageId = null, ?array $headers = null, ?int $ttl = null) {
                if ($ttl !== 3600) {
                    throw new \InvalidArgumentException('Expected ttl=3600');
                }

                return \FileBroker\Message\Message::create(
                    body: $body,
                    id: 'msg-ttl',
                    headers: $headers ?? [],
                );
            });

        $command = new ProduceCommand($broker, $this->createSilentLogger());
        $message = $command->execute([
            'queue' => 'test-queue',
            'body' => 'test body',
            'ttl' => '3600',
        ]);

        self::assertNotNull($message);
        self::assertSame('msg-ttl', $message->id);
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
