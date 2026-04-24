<?php

declare(strict_types=1);

namespace FileBroker\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\Logging\Logger;

final class PurgeCommand
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

        if (!$this->broker->hasQueue($queueName)) {
            $this->logger->error("Queue '{$queueName}' does not exist", ['queue' => $queueName]);
            return;
        }

        $this->broker->purge($queueName);
        $this->logger->info("Queue '{$queueName}' has been purged", ['queue' => $queueName]);
    }
}
