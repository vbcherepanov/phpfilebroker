<?php

declare(strict_types=1);

namespace FileBroker\Worker;

use FileBroker\Broker\MessageBroker;
use FileBroker\Message\Message;

/**
 * A single consumer worker that polls a queue and processes messages.
 *
 * Supports callback-based processing: pass a callable(Message, MessageBroker): void
 * via constructor or run(). If no callback is provided, messages are auto-acknowledged.
 *
 * Signal handling: if pcntl is available, SIGTERM and SIGINT trigger stop()
 * for graceful shutdown.
 */
final class Worker
{
    private bool $running = true;

    public function __construct(
        private readonly string $queueName,
        private readonly MessageBroker $broker,
        private readonly ?\Closure $handler = null,
    ) {}

    /**
     * Start the worker — polls until stop() is called.
     *
     * @param ?\Closure $handler Optional handler, takes precedence over constructor handler.
     */
    public function run(?\Closure $handler = null): void
    {
        $callback = $handler ?? $this->handler;

        // Install signal handlers (if pcntl available).
        if (\function_exists('pcntl_signal')) {
            pcntl_signal(\SIGTERM, fn() => $this->stop());
            pcntl_signal(\SIGINT, fn() => $this->stop());
        }

        $maxBackoff = 30; // seconds
        $backoff = 1;

        while ($this->running) {
            // Dispatch pending signals.
            if (\function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $message = $this->broker->consume($this->queueName);

            if ($message === null) {
                // Sleep with exponential backoff (capped).
                usleep((int) min($backoff, $maxBackoff) * 1_000_000);
                $backoff = min($backoff * 2, $maxBackoff);
                continue;
            }

            // Reset backoff on successful consume.
            $backoff = 1;

            try {
                if ($callback !== null) {
                    ($callback)($message, $this->broker);
                } else {
                    $this->process($message);
                }
            } catch (\Throwable $e) {
                // Reject the message on handler failure.
                $this->broker->reject($this->queueName, $message->id, $e->getMessage());
            }
        }
    }

    /**
     * Stop the worker (exit the run loop).
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Whether the worker is still running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Process a single message (default handler).
     *
     * Called when no callback is provided. Auto-acknowledges the message.
     * Override in subclasses for custom logic.
     */
    protected function process(Message $message): void
    {
        $this->broker->acknowledge($this->queueName, $message->id);
    }
}
