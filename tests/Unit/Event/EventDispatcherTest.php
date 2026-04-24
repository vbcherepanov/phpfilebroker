<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Event;

use FileBroker\Event\EventDispatcher;
use FileBroker\Event\MessageProducedEvent;
use FileBroker\Message\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventDispatcher::class)]
final class EventDispatcherTest extends TestCase
{
    public function test_dispatch_calls_listener(): void
    {
        $dispatcher = new EventDispatcher();
        $message = Message::create(body: 'test');
        $event = new MessageProducedEvent($message, 'orders', '/tmp/test.msg');

        $calledWith = null;
        $dispatcher->subscribe(
            MessageProducedEvent::class,
            function (object $e) use (&$calledWith): void {
                $calledWith = $e;
            },
        );

        $dispatcher->dispatch($event);

        $this->assertSame($event, $calledWith);
    }

    public function test_multiple_listeners(): void
    {
        $dispatcher = new EventDispatcher();
        $message = Message::create(body: 'test');
        $event = new MessageProducedEvent($message, 'orders', '/tmp/test.msg');

        $callOrder = [];
        $dispatcher->subscribe(
            MessageProducedEvent::class,
            function (object $e) use (&$callOrder): void {
                $callOrder[] = 'first';
            },
        );
        $dispatcher->subscribe(
            MessageProducedEvent::class,
            function (object $e) use (&$callOrder): void {
                $callOrder[] = 'second';
            },
        );

        $dispatcher->dispatch($event);

        $this->assertSame(['first', 'second'], $callOrder);
    }

    public function test_listeners_receive_correct_event_object(): void
    {
        $dispatcher = new EventDispatcher();
        $message = Message::create(body: 'test');
        $event = new MessageProducedEvent($message, 'orders', '/tmp/test.msg');

        $dispatcher->subscribe(
            MessageProducedEvent::class,
            function (MessageProducedEvent $e): void {
                $this->assertSame('orders', $e->queueName);
                $this->assertSame('/tmp/test.msg', $e->filePath);
            },
        );

        $dispatcher->dispatch($event);
    }

    public function test_dispatch_without_listeners(): void
    {
        $dispatcher = new EventDispatcher();
        $message = Message::create(body: 'test');
        $event = new MessageProducedEvent($message, 'orders', '/tmp/test.msg');

        $result = $dispatcher->dispatch($event);

        $this->assertSame($event, $result);
    }

    public function test_get_listeners_returns_empty_array_for_unregistered_event(): void
    {
        $dispatcher = new EventDispatcher();

        $listeners = $dispatcher->getListeners(MessageProducedEvent::class);

        $this->assertSame([], $listeners);
    }

    public function test_get_listeners_returns_registered_listeners(): void
    {
        $dispatcher = new EventDispatcher();
        $listener = function (object $e): void {};

        $dispatcher->subscribe(MessageProducedEvent::class, $listener);

        $listeners = $dispatcher->getListeners(MessageProducedEvent::class);
        $this->assertCount(1, $listeners);
        $this->assertSame($listener, $listeners[0]);
    }

    public function test_has_listeners_returns_false_for_unregistered_event(): void
    {
        $dispatcher = new EventDispatcher();

        $this->assertFalse($dispatcher->hasListeners(MessageProducedEvent::class));
    }
}
