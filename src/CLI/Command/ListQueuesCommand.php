<?php

declare(strict_types=1);

namespace FileBroker\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\Logging\Logger;

final class ListQueuesCommand
{
    public function __construct(
        private readonly MessageBroker $broker,
        private readonly Logger $logger,
    ) {}

    public function execute(): void
    {
        $queues = $this->broker->listQueues();

        if ($queues === []) {
            $this->logger->warning('No queues configured');
            return;
        }

        $this->logger->info('Queues listed', [
            'count' => \count($queues),
            'queues' => $queues,
        ]);
    }
}
