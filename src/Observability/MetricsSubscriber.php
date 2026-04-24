<?php

declare(strict_types=1);

namespace FileBroker\Observability;

use FileBroker\Event\EventDispatcher;
use FileBroker\Event\MessageAcknowledgedEvent;
use FileBroker\Event\MessageConsumedEvent;
use FileBroker\Event\MessageDeadLetteredEvent;
use FileBroker\Event\MessageProducedEvent;
use FileBroker\Event\MessageRejectedEvent;
use FileBroker\Event\MessageRetryEvent;

final class MetricsSubscriber
{
    public function __construct(
        private readonly MetricsCollector $metrics,
    ) {}

    /**
     * Subscribe to all broker events on the given dispatcher.
     */
    public function subscribe(EventDispatcher $dispatcher): void
    {
        $dispatcher->subscribe(MessageProducedEvent::class, $this->onProduced(...));
        $dispatcher->subscribe(MessageConsumedEvent::class, $this->onConsumed(...));
        $dispatcher->subscribe(MessageAcknowledgedEvent::class, $this->onAcknowledged(...));
        $dispatcher->subscribe(MessageRejectedEvent::class, $this->onRejected(...));
        $dispatcher->subscribe(MessageRetryEvent::class, $this->onRetried(...));
        $dispatcher->subscribe(MessageDeadLetteredEvent::class, $this->onDeadLettered(...));
    }

    private function onProduced(MessageProducedEvent $event): void
    {
        $this->metrics->incrementCounter('messages_produced_total');
        $this->metrics->recordHistogram('message_size_bytes', (float) \strlen($event->message->body));
    }

    private function onConsumed(MessageConsumedEvent $event): void
    {
        $this->metrics->incrementCounter('messages_consumed_total');
    }

    private function onAcknowledged(MessageAcknowledgedEvent $event): void
    {
        $this->metrics->incrementCounter('messages_acknowledged_total');
    }

    private function onRejected(MessageRejectedEvent $event): void
    {
        $this->metrics->incrementCounter('messages_rejected_total');
    }

    private function onRetried(MessageRetryEvent $event): void
    {
        $this->metrics->incrementCounter('messages_retried_total');
    }

    private function onDeadLettered(MessageDeadLetteredEvent $event): void
    {
        $this->metrics->incrementCounter('messages_dead_lettered_total');
    }
}
