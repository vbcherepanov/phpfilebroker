<?php

declare(strict_types=1);

namespace FileBroker\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\Logging\Logger;
use FileBroker\Message\Message;

final class ConsumeCommand
{
    public function __construct(
        private readonly MessageBroker $broker,
        private readonly Logger $logger,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args): ?Message
    {
        $queueName = $args['queue'] ?? throw new \InvalidArgumentException('Queue name is required');

        $message = $this->broker->consume($queueName);

        if ($message === null) {
            $this->logger->warning("Queue '{$queueName}' is empty", ['queue' => $queueName]);
            return null;
        }

        $this->logger->info('Message consumed', [
            'id' => $message->id,
            'queue' => $queueName,
            'body' => mb_strcut($message->body, 0, 200),
            'created_at' => $message->createdAt->format('Y-m-d H:i:s'),
            'expires_at' => $message->expiresAt?->format('Y-m-d H:i:s') ?? 'never',
            'attempts' => $message->deliveryCount,
            'headers' => $message->headers,
        ]);

        return $message;
    }
}
