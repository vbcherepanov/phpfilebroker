<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Producer;

use FileBroker\Broker\MessageBroker;
use FileBroker\Message\Message;
use FileBroker\Producer\FileProducer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileProducer::class)]
final class FileProducerTest extends TestCase
{
    public function test_send_calls_broker_produce(): void
    {
        $message = Message::create(body: 'test body');
        $broker = $this->createMock(MessageBroker::class);
        $broker->expects($this->once())
            ->method('produce')
            ->with(
                'orders',
                'test body',
                null,
                [],
                null,
            )
            ->willReturn($message);

        $producer = new FileProducer($broker);
        $result = $producer->send('orders', 'test body');

        $this->assertSame($message, $result);
    }

    public function test_send_with_ttl(): void
    {
        $message = Message::create(body: 'test body');
        $broker = $this->createMock(MessageBroker::class);
        $broker->expects($this->once())
            ->method('produce')
            ->with(
                'orders',
                'test body',
                null,
                [],
                3600,
            )
            ->willReturn($message);

        $producer = new FileProducer($broker);
        $result = $producer->send('orders', 'test body', ttlSeconds: 3600);

        $this->assertSame($message, $result);
    }

    public function test_send_returns_message(): void
    {
        $message = Message::create(body: 'test body');
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('produce')->willReturn($message);

        $producer = new FileProducer($broker);
        $result = $producer->send('orders', 'test body');

        $this->assertInstanceOf(Message::class, $result);
        $this->assertSame('test body', $result->body);
    }
}
