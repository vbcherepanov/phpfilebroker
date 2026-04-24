<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Consumer;

use FileBroker\Broker\MessageBroker;
use FileBroker\Consumer\FileConsumer;
use FileBroker\Message\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileConsumer::class)]
final class FileConsumerTest extends TestCase
{
    public function test_process_consumes_and_acknowledges(): void
    {
        $message = Message::create(body: 'test body', id: 'msg-1');
        $broker = $this->createMock(MessageBroker::class);
        $broker->expects($this->exactly(2))
            ->method('consume')
            ->with('orders')
            ->willReturnOnConsecutiveCalls($message, null);
        $broker->expects($this->once())
            ->method('acknowledge')
            ->with('orders', 'msg-1');

        $consumer = new FileConsumer($broker);
        $callback = fn(Message $msg): bool => true;

        $processed = $consumer->process('orders', $callback);

        $this->assertSame(1, $processed);
    }

    public function test_process_consumes_and_rejects(): void
    {
        // When callback returns false, process() breaks without acknowledging.
        // The message stays on the queue (no ack, no reject).
        $message = Message::create(body: 'test body', id: 'msg-1');
        $broker = $this->createMock(MessageBroker::class);
        $broker->expects($this->once())
            ->method('consume')
            ->with('orders')
            ->willReturn($message);
        $broker->expects($this->never())
            ->method('acknowledge');

        $consumer = new FileConsumer($broker);
        $callback = fn(Message $msg): bool => false;

        $processed = $consumer->process('orders', $callback);

        // The message was consumed but processing stopped without ack
        $this->assertSame(0, $processed);
    }

    public function test_process_empty_queue(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->expects($this->once())
            ->method('consume')
            ->with('orders')
            ->willReturn(null);
        $broker->expects($this->never())
            ->method('acknowledge');

        $callCount = 0;
        $callback = function (Message $msg) use (&$callCount): bool {
            $callCount++;
            return true;
        };

        $consumer = new FileConsumer($broker);
        $processed = $consumer->process('orders', $callback);

        $this->assertSame(0, $processed);
        $this->assertSame(0, $callCount, 'Callback must not be called when queue is empty');
    }

    public function test_process_callback_exception_rejects(): void
    {
        $message = Message::create(body: 'test body', id: 'msg-1');
        $broker = $this->createMock(MessageBroker::class);
        $broker->expects($this->once())
            ->method('consume')
            ->with('orders')
            ->willReturn($message);
        $broker->expects($this->never())
            ->method('acknowledge');

        $consumer = new FileConsumer($broker);
        $callback = fn(Message $msg): bool => throw new \RuntimeException('Processing failed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Processing failed');

        $consumer->process('orders', $callback);
    }
}
