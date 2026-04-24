<?php

declare(strict_types=1);

namespace FileBroker\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\Logging\Logger;

final class DeleteQueueCommand
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
        $queueName = $args['name'] ?? throw new \InvalidArgumentException('Queue name is required');

        if (!$this->broker->hasQueue($queueName)) {
            $this->logger->error("Queue '{$queueName}' does not exist", ['queue' => $queueName]);
            return;
        }

        $this->broker->deleteQueue($queueName);
        $this->logger->info("Queue '{$queueName}' has been deleted", ['queue' => $queueName]);
    }
}
