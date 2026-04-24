<?php

declare(strict_types=1);

namespace FileBroker\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\Logging\Logger;
use FileBroker\Message\Message;
use FileBroker\Worker\WorkerPool;

/**
 * Watch a queue and display messages as they arrive.
 */
final class WatchCommand
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
        $limit = isset($args['limit']) ? (int) $args['limit'] : 0;
        $once = isset($args['once']) && $args['once'] === true;

        if ($once) {
            $this->watchOnce($queueName);
            return;
        }

        $maxWorkers = $this->broker->getConfig()->maxWorkers;
        if ($maxWorkers > 1) {
            $this->watchWithPool($queueName, $limit);
            return;
        }

        // Single worker with exponential backoff.
        $this->watchSingle($queueName, $limit);
    }

    private function watchOnce(string $queueName): void
    {
        $this->logger->info("Watching queue '{$queueName}'", ['queue' => $queueName, 'mode' => 'once']);
        echo "Watching queue '{$queueName}' (Ctrl+C to stop)...\n";
        echo str_repeat('-', 60) . "\n";

        $message = $this->broker->consume($queueName);

        if ($message !== null) {
            $this->displayMessage($message);
        }

        $this->logger->info('Watch done', ['queue' => $queueName, 'processed' => 1]);
        echo "Done. Processed 1 message(s).\n";
    }

    private function watchSingle(string $queueName, int $limit): void
    {
        $this->logger->info("Watching queue '{$queueName}'", ['queue' => $queueName, 'mode' => 'single', 'limit' => $limit]);
        echo "Watching queue '{$queueName}' (Ctrl+C to stop)...\n";
        echo str_repeat('-', 60) . "\n";

        $count = 0;
        $maxBackoff = 30; // seconds
        $backoff = 1;

        while (true) {
            $message = $this->broker->consume($queueName);

            if ($message === null) {
                usleep((int) min($backoff, $maxBackoff) * 1_000_000);
                $backoff = min($backoff * 2, $maxBackoff);
                continue;
            }

            // Reset backoff on successful consume.
            $backoff = 1;
            $count = $this->displayMessage($message, $count);

            if ($limit > 0 && $count >= $limit) {
                break;
            }
        }

        $this->logger->info('Watch done', ['queue' => $queueName, 'processed' => $count]);
        echo "Done. Processed {$count} message(s).\n";
    }

    private function watchWithPool(string $queueName, int $limit): void
    {
        $this->logger->info("Watching queue '{$queueName}'", ['queue' => $queueName, 'mode' => 'pool', 'limit' => $limit]);
        echo "Watching queue '{$queueName}' (Ctrl+C to stop)...\n";
        echo str_repeat('-', 60) . "\n";

        $count = 0;

        // Callback displays each message without acknowledging (read-only watch).
        $handler = function (Message $message, MessageBroker $_broker) use (&$count, $limit): void {
            $count = $this->displayMessage($message, $count);

            if ($limit > 0 && $count >= $limit) {
                // Signal stop through the broker by reject-then-stop pattern.
                // The pool will drain remaining workers.
                throw new \RuntimeException('Limit reached');
            }
        };

        $pool = new WorkerPool($queueName, $this->broker, $handler);

        try {
            $pool->run();

            $this->logger->info('Watch done', ['queue' => $queueName, 'processed' => $count]);
            echo "Done. Processed {$count} message(s).\n";
        } catch (\RuntimeException) {
            // Limit reached — graceful shutdown.
            $pool->stop();
            $this->logger->info('Watch stopped (limit reached)', ['queue' => $queueName, 'processed' => $count]);
            echo "Done. Processed {$count} message(s).\n";
        } catch (\Throwable) {
            // Graceful exit on Ctrl+C or other signals.
            $pool->stop();
            $this->logger->warning('Watch interrupted', ['queue' => $queueName, 'processed' => $count]);
            echo "Interrupted. Processed {$count} message(s).\n";
        }
    }

    private function displayMessage(Message $message, int &$count = 0): int
    {
        ++$count;

        echo \sprintf(
            "[%s] #%d: %s\n  Body:   %s\n  Headers: %s\n\n",
            $message->createdAt->format('Y-m-d H:i:s'),
            $count,
            $message->id,
            mb_strcut($message->body, 0, 200),
            $message->headers !== [] ? json_encode($message->headers) : '{}',
        );

        return $count;
    }
}
