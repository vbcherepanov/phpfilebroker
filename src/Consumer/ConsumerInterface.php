<?php

declare(strict_types=1);

namespace FileBroker\Consumer;

use FileBroker\Message\Message;

/**
 * Interface for message consumers.
 */
interface ConsumerInterface
{
    /**
     * Consume the next message from a queue.
     *
     * @param string $queueName Queue to consume from
     * @return Message|null The message, or null if queue is empty
     */
    public function consume(string $queueName): ?Message;

    /**
     * Process messages from a queue until empty or callback returns false.
     *
     * @param string $queueName Queue to consume from
     * @param callable(Message): bool $callback Called for each message; return false to stop
     * @param int $maxMessages Maximum messages to process (0 = unlimited)
     */
    public function process(
        string $queueName,
        callable $callback,
        int $maxMessages = 0,
    ): int;

    /**
     * Check if a queue has pending messages.
     */
    public function hasMessages(string $queueName): bool;
}
