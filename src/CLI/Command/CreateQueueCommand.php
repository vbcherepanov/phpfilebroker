<?php

declare(strict_types=1);

namespace FileBroker\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\Config\QueueConfig;
use FileBroker\Logging\Logger;

final class CreateQueueCommand
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
        $name = $args['name'] ?? throw new \InvalidArgumentException('Queue name is required');
        $path = $args['path'] ?? \sprintf('%s/queues/%s', $this->broker->getConfig()->storagePath, $name);

        if ($this->broker->hasQueue($name)) {
            $this->logger->warning("Queue '{$name}' already exists", ['queue' => $name]);
            return;
        }

        $config = new QueueConfig(
            name: $name,
            basePath: (string) $path,
        );

        $this->broker->createQueue($config);
        $this->logger->info("Queue '{$name}' created at '{$path}'", ['queue' => $name, 'path' => $path]);
    }
}
