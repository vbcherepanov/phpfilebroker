<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Config;

use FileBroker\Config\QueueConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueueConfig::class)]
final class QueueConfigTest extends TestCase
{
    public function test_from_array_with_minimal_data(): void
    {
        $config = QueueConfig::fromArray(['name' => 'test-queue']);

        self::assertSame('test-queue', $config->name);
        self::assertStringContainsString('file-broker', $config->basePath);
        self::assertNull($config->defaultTtlSeconds);
        self::assertSame(3, $config->maxRetryAttempts);
        self::assertSame(60, $config->retryDelaySeconds);
        self::assertTrue($config->enableDeadLetter);
        self::assertSame(10_485_760, $config->maxMessageSizeBytes);
    }

    public function test_from_array_with_full_data(): void
    {
        $config = QueueConfig::fromArray([
            'name' => 'orders',
            'base_path' => '/tmp/orders',
            'default_ttl' => 3600,
            'max_retry' => 5,
            'retry_delay' => 120,
            'dead_letter' => true,
            'dead_letter_queue' => 'orders.dlq',
            'max_message_size' => 5_242_880,
        ]);

        self::assertSame('orders', $config->name);
        self::assertSame('/tmp/orders', $config->basePath);
        self::assertSame(3600, $config->defaultTtlSeconds);
        self::assertSame(5, $config->maxRetryAttempts);
        self::assertSame(120, $config->retryDelaySeconds);
        self::assertTrue($config->enableDeadLetter);
        self::assertSame('orders.dlq', $config->deadLetterQueueName);
        self::assertSame(5_242_880, $config->maxMessageSizeBytes);
    }

    public function test_from_array_throws_without_name(): void
    {
        self::expectException(\InvalidArgumentException::class);
        QueueConfig::fromArray([]);
    }

    public function test_messages_path(): void
    {
        $config = new QueueConfig(name: 'orders', basePath: '/tmp/orders');
        self::assertSame('/tmp/orders/queues/orders', $config->messagesPath());
    }

    public function test_retry_path(): void
    {
        $config = new QueueConfig(name: 'orders', basePath: '/tmp/orders');
        self::assertSame('/tmp/orders/retry/orders', $config->retryPath());
    }

    public function test_dead_letter_path(): void
    {
        $config = new QueueConfig(name: 'orders', basePath: '/tmp/orders');
        self::assertSame('/tmp/orders/dead-letter/orders.dlq', $config->deadLetterPath());
    }

    public function test_to_array(): void
    {
        $config = new QueueConfig(
            name: 'test',
            basePath: '/tmp/test',
            defaultTtlSeconds: 1800,
            maxRetryAttempts: 5,
            retryDelaySeconds: 30,
            enableDeadLetter: true,
            deadLetterQueueName: 'test.dlq',
            maxMessageSizeBytes: 1_048_576,
        );

        $array = $config->toArray();

        self::assertSame('test', $array['name']);
        self::assertSame('/tmp/test', $array['base_path']);
        self::assertSame(1800, $array['default_ttl']);
        self::assertSame(5, $array['max_retry']);
        self::assertSame(30, $array['retry_delay']);
        self::assertTrue($array['dead_letter']);
        self::assertSame('test.dlq', $array['dead_letter_queue']);
        self::assertSame(1_048_576, $array['max_message_size']);
    }
}
