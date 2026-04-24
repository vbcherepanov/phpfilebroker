<?php

declare(strict_types=1);

namespace FileBroker\Consumer;

use FileBroker\Broker\MessageBroker;
use FileBroker\Message\Message;

/**
 * Default consumer implementation backed by the MessageBroker.
 */
final class FileConsumer implements ConsumerInterface
{
    public function __construct(
        private readonly MessageBroker $broker,
    ) {}

    public function consume(string $queueName): ?Message
    {
        return $this->broker->consume($queueName);
    }

    public function process(
        string $queueName,
        callable $callback,
        int $maxMessages = 0,
    ): int {
        $processed = 0;

        while (true) {
            if ($maxMessages > 0 && $processed >= $maxMessages) {
                break;
            }

            $message = $this->consume($queueName);
            if ($message === null) {
                break;
            }

            $shouldContinue = $callback($message);
            if ($shouldContinue === false) {
                // Message not acknowledged — it stays in queue for retry
                break;
            }

            // Acknowledge successful processing
            $this->broker->acknowledge($queueName, $message->id);
            $processed++;
        }

        return $processed;
    }

    public function hasMessages(string $queueName): bool
    {
        $stats = $this->broker->getQueueStats($queueName);
        return $stats['message_count'] > 0;
    }
}
