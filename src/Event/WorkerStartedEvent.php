<?php

declare(strict_types=1);

namespace FileBroker\Event;

/**
 * Dispatched when a WorkerPool starts.
 */
final class WorkerStartedEvent
{
    public function __construct(
        public readonly string $queueName,
        public readonly int $poolSize,
    ) {}
}
