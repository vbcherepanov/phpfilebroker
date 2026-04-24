<?php

declare(strict_types=1);

namespace FileBroker\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\Logging\Logger;

final class StatsCommand
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
        $queueName = $args['queue'] ?? null;

        if ($queueName !== null) {
            $this->showSingleQueueStats($queueName);
        } else {
            $this->showAllQueuesStats();
        }
    }

    private function showSingleQueueStats(string $queueName): void
    {
        $stats = $this->broker->getQueueStats($queueName);

        $this->logger->info('Queue stats', $stats);
    }

    private function showAllQueuesStats(): void
    {
        $queues = $this->broker->listQueues();

        if ($queues === []) {
            $this->logger->warning('No queues configured');
            return;
        }

        $allStats = [];
        foreach ($queues as $queueName) {
            $allStats[$queueName] = $this->broker->getQueueStats($queueName);
        }

        $this->logger->info('All queues stats', ['queues' => $allStats]);
    }
}
