<?php

declare(strict_types=1);

namespace FileBroker\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\Logging\Logger;

final class DeadLetterCommand
{
    public function __construct(
        private readonly MessageBroker $broker,
        private readonly Logger $logger,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args): void
    {
        $queueName = $args['queue'] ?? throw new \InvalidArgumentException('Queue name is required');
        $messageId = $args['id'] ?? throw new \InvalidArgumentException('Message ID is required');
        $reason = $args['reason'] ?? 'CLI dead-letter';

        $this->broker->deadLetter($queueName, $messageId, $reason);
        $this->logger->info("Message '{$messageId}' moved to DLQ", [
            'queue' => $queueName,
            'message_id' => $messageId,
            'reason' => $reason,
        ]);
    }
}
