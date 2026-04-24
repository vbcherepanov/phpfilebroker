<?php

declare(strict_types=1);

namespace FileBroker\Event;

/**
 * Dispatched when a WorkerPool stops.
 */
final class WorkerStoppedEvent
{
    public function __construct(
        public readonly string $queueName,
    ) {}
}
