<?php

declare(strict_types=1);

namespace FileBroker\Flow;

/**
 * Limits unacknowledged messages per consumer to prevent overload.
 *
 * RabbitMQ-style prefetch: caps the number of messages a consumer
 * can have in-flight (unacknowledged) at any time.
 */
final class PrefetchController
{
    public function __construct(
        public readonly int $prefetchCount = 10,
        public readonly int $prefetchSize = 0,
    ) {}

    /**
     * Can the consumer receive another message?
     */
    public function canReceive(int $unackedCount): bool
    {
        if ($this->prefetchSize > 0) {
            // Size-based limiting not implemented in filesystem broker;
            // 0 means unlimited (like RabbitMQ global size=0).
            // When non-zero this is a placeholder for future extension.
        }

        return $unackedCount < $this->prefetchCount;
    }

    /**
     * Create a new instance with a different prefetch count.
     */
    public function withPrefetchCount(int $count): self
    {
        return new self(
            prefetchCount: $count,
            prefetchSize: $this->prefetchSize,
        );
    }
}
