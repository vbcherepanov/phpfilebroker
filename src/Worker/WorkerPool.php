<?php

declare(strict_types=1);

namespace FileBroker\Worker;

use FileBroker\Broker\MessageBroker;
use FileBroker\Event\WorkerStartedEvent;
use FileBroker\Event\WorkerStoppedEvent;

/**
 * Manages concurrent consume workers for a queue.
 *
 * Each worker acquires its own lock context, so multiple workers can process
 * messages from the same queue in parallel. Uses exponential backoff when no
 * messages are available to reduce contention.
 *
 * IMPORTANT: Without pcntl_fork, workers run sequentially in a single process.
 * The foreach loop iterates workers one by one, and each Worker::run() blocks
 * indefinitely (while loop). For true parallelism, run multiple processes via
 * `file-broker watch` or a process manager.
 */
final class WorkerPool
{
    private int $poolSize;
    /** @var list<Worker> */
    private array $workers = [];

    public function __construct(
        private readonly string $queueName,
        private readonly MessageBroker $broker,
        private readonly ?\Closure $handler = null,
    ) {
        $this->poolSize = max(1, min($broker->getConfig()->maxWorkers, 16));
    }

    /**
     * Number of workers in the pool.
     */
    public function size(): int
    {
        return $this->poolSize;
    }

    /**
     * Start all workers and run until one signals stop.
     */
    public function run(): void
    {
        $this->broker->getEventDispatcher()->dispatch(new WorkerStartedEvent(
            queueName: $this->queueName,
            poolSize: $this->poolSize,
        ));

        // Build workers with fresh lock state each time.
        for ($i = 0; $i < $this->poolSize; $i++) {
            $this->workers[] = new Worker($this->queueName, $this->broker, $this->handler);
        }

        foreach ($this->workers as $worker) {
            $worker->run();
        }

        $this->broker->getEventDispatcher()->dispatch(new WorkerStoppedEvent(
            queueName: $this->queueName,
        ));
    }

    /**
     * Stop all workers (signals them to exit their consume loops).
     */
    public function stop(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop();
        }
    }

    /**
     * Update worker count at runtime.
     */
    public function resize(int $newSize): void
    {
        $this->stop();

        $diff = $newSize - $this->poolSize;
        if ($diff > 0) {
            // Add workers.
            for ($i = $this->poolSize; $i < $newSize; $i++) {
                $this->workers[] = new Worker($this->queueName, $this->broker, $this->handler);
            }
        } elseif ($diff < 0) {
            // Remove workers (keep the first ones).
            $this->workers = \array_slice($this->workers, 0, $newSize);
        }

        $this->poolSize = $newSize;
    }
}
