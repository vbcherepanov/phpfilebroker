<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Worker;

use FileBroker\Broker\MessageBroker;
use FileBroker\Message\Message;
use FileBroker\Worker\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Worker::class)]
final class WorkerCallbackTest extends TestCase
{
    public function test_worker_with_callback_handler(): void
    {
        $message = Message::create(body: 'test-body');

        $handlerCalled = false;
        $receivedMessage = null;

        $broker = $this->createMock(MessageBroker::class);
        $broker->method('consume')
            ->willReturn($message);

        $worker = new Worker('test-queue', $broker, function (Message $msg, MessageBroker $_brk) use (&$handlerCalled, &$receivedMessage, &$worker): void {
            $handlerCalled = true;
            $receivedMessage = $msg;
            $worker->stop();
        });

        $worker->run();

        self::assertTrue($handlerCalled, 'Callback should have been called');
        self::assertSame($message, $receivedMessage);
        self::assertFalse($worker->isRunning(), 'Worker should be stopped after callback stops it');
    }

    public function test_worker_without_handler_auto_acknowledges(): void
    {
        $message = Message::create(body: 'test-body');

        $broker = $this->createMock(MessageBroker::class);
        $broker->method('consume')
            ->willReturn($message);

        $broker->expects(self::once())
            ->method('acknowledge')
            ->with('test-queue', $message->id);

        $worker = new Worker('test-queue', $broker);

        // Invoke process() directly to test the default handler logic.
        // Full run() loop is tested separately via integration tests.
        $refMethod = new \ReflectionMethod(Worker::class, 'process');
        $refMethod->invoke($worker, $message);

        self::assertTrue($worker->isRunning(), 'Worker should still be running after process()');
    }

    public function test_worker_handler_exception_triggers_reject(): void
    {
        $message = Message::create(body: 'test-body');
        $exceptionMessage = 'Handler failure in unit test';

        $callCount = 0;

        $broker = $this->createMock(MessageBroker::class);
        $broker->method('consume')
            ->willReturnCallback(function () use (&$callCount, $message, &$worker): ?Message {
                ++$callCount;
                if ($callCount === 1) {
                    return $message;
                }
                // Stop the loop after first iteration.
                $worker->stop();
                return null;
            });

        $broker->expects(self::once())
            ->method('reject')
            ->with('test-queue', $message->id, self::stringContains($exceptionMessage));

        $handler = function (Message $_msg, MessageBroker $_brk) use ($exceptionMessage): void {
            throw new \RuntimeException($exceptionMessage);
        };

        $worker = new Worker('test-queue', $broker, $handler);

        // The loop: consume returns message, handler throws, reject called,
        // consume returns null + stop(), loop exits.
        $worker->run();

        self::assertFalse($worker->isRunning(), 'Worker should be stopped');
        self::assertSame(2, $callCount, 'consume should have been called twice');
    }

    public function test_worker_stop_breaks_loop(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->method('consume')
            ->willReturn(null);

        $worker = new Worker('test-queue', $broker);

        self::assertTrue($worker->isRunning(), 'Worker should start as running');

        $worker->stop();

        self::assertFalse($worker->isRunning(), 'Worker should be stopped');
    }

    public function test_worker_run_exits_when_stopped_before_run(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $broker->expects(self::never())
            ->method('consume');

        $worker = new Worker('test-queue', $broker);
        $worker->stop();
        $worker->run();

        self::assertFalse($worker->isRunning());
    }

    public function test_worker_run_passed_handler_takes_precedence(): void
    {
        $message = Message::create(body: 'test-body');

        $broker = $this->createMock(MessageBroker::class);
        $broker->method('consume')
            ->willReturn($message);

        $ctorCalled = false;

        $ctorHandler = function (Message $_msg, MessageBroker $_brk) use (&$ctorCalled): void {
            $ctorCalled = true;
        };

        $worker = new Worker('test-queue', $broker, $ctorHandler);

        $runHandlerCalled = false;
        $worker->run(function (Message $_msg, MessageBroker $_brk) use (&$runHandlerCalled, &$worker): void {
            $runHandlerCalled = true;
            $worker->stop();
        });

        self::assertTrue($runHandlerCalled, 'Handler passed to run() should be called');
        self::assertFalse($ctorCalled, 'Constructor handler should NOT be called when run() handler is provided');
    }
}
