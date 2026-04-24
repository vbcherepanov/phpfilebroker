<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Observability;

use FileBroker\Event\EventDispatcher;
use FileBroker\Event\MessageConsumedEvent;
use FileBroker\Event\MessageDeadLetteredEvent;
use FileBroker\Event\MessageProducedEvent;
use FileBroker\Message\Message;
use FileBroker\Observability\MetricsCollector;
use FileBroker\Observability\MetricsSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricsSubscriber::class)]
final class MetricsSubscriberTest extends TestCase
{
    public function test_subscriber_collects_produce_metrics(): void
    {
        $collector = new MetricsCollector();
        $subscriber = new MetricsSubscriber($collector);
        $dispatcher = new EventDispatcher();

        $subscriber->subscribe($dispatcher);

        $message = Message::create(body: 'hello world');
        $dispatcher->dispatch(new MessageProducedEvent(
            message: $message,
            queueName: 'orders',
            filePath: '/tmp/orders/test.msg',
        ));

        $snapshot = $collector->getSnapshot();

        $this->assertSame(1, $snapshot['counters']['messages_produced_total']);
        $this->assertSame(11.0, $snapshot['histograms']['message_size_bytes']['sum']);
    }

    public function test_subscriber_collects_consume_and_dlq_metrics(): void
    {
        $collector = new MetricsCollector();
        $subscriber = new MetricsSubscriber($collector);
        $dispatcher = new EventDispatcher();

        $subscriber->subscribe($dispatcher);

        $message = Message::create(body: 'payload');

        $dispatcher->dispatch(new MessageConsumedEvent(
            message: $message,
            queueName: 'orders',
            filePath: '/tmp/orders/test.msg',
        ));

        $dispatcher->dispatch(new MessageDeadLetteredEvent(
            message: $message,
            queueName: 'orders',
            reason: 'Test reason',
        ));

        $snapshot = $collector->getSnapshot();

        $this->assertSame(1, $snapshot['counters']['messages_consumed_total']);
        $this->assertSame(1, $snapshot['counters']['messages_dead_lettered_total']);
    }
}
