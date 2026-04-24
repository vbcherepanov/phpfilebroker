<?php

declare(strict_types=1);

namespace FileBroker\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\Logging\Logger;

final class HealthCommand
{
    public function __construct(
        private readonly MessageBroker $broker,
        private readonly Logger $logger,
    ) {}

    public function execute(): void
    {
        $config = $this->broker->getConfig();
        $queues = $this->broker->listQueues();

        $totalMessages = 0;
        $totalDLQ = 0;
        $totalRetry = 0;

        foreach ($queues as $queueName) {
            $stats = $this->broker->getQueueStats($queueName);
            $totalMessages += $stats['message_count'];
            $totalDLQ += $stats['dead_letter_count'];
            $totalRetry += $stats['retry_count'];
        }

        $status = 'healthy';
        if ($totalDLQ > 100) {
            $status = 'warning';
        }
        if ($totalDLQ > 1000) {
            $status = 'critical';
        }

        $this->logger->info('File Broker Health', [
            'storage_path' => $config->storagePath,
            'queue_count' => \count($queues),
            'default_queue' => $config->defaultQueue ?? 'none',
            'lock_timeout' => $config->lockTimeout,
            'poll_interval' => $config->pollInterval,
            'max_workers' => $config->maxWorkers,
            'total_messages' => $totalMessages,
            'total_retries' => $totalRetry,
            'total_dlq' => $totalDLQ,
            'status' => $status,
        ]);
    }
}
