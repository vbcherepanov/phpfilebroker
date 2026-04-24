<?php

declare(strict_types=1);

namespace FileBroker\Reliability;

/**
 * Manages publisher confirmations for produced messages.
 *
 * Implements RabbitMQ-style publisher confirms: producers register
 * messages and wait for durable-write confirmation.
 */
final class PublisherConfirm
{
    /** @var array<string, bool> message_id => confirmed */
    private array $pending = [];

    /** @var array<string, \Closure> message_id => callback */
    private array $callbacks = [];

    /**
     * Register a message awaiting confirmation.
     *
     * @param \Closure|null $onConfirm Callback invoked on confirm, receives messageId
     */
    public function register(string $messageId, ?callable $onConfirm = null): void
    {
        $this->pending[$messageId] = false;

        if ($onConfirm !== null) {
            $this->callbacks[$messageId] = \Closure::fromCallable($onConfirm);
        }
    }

    /**
     * Confirm a message was durably written.
     */
    public function confirm(string $messageId): void
    {
        $this->pending[$messageId] = true;

        if (isset($this->callbacks[$messageId])) {
            ($this->callbacks[$messageId])($messageId);
            unset($this->callbacks[$messageId]);
        }
    }

    /**
     * Wait for all pending confirms (sync mode).
     *
     * @param int|null $timeoutSeconds Max seconds to wait, null = no timeout
     * @return bool True if all confirmed, false if timeout reached with pending remaining
     */
    public function waitForAll(?int $timeoutSeconds = null): bool
    {
        if (\count($this->pending) === 0) {
            return true;
        }

        $start = time();
        $sleepMicroseconds = 10000; // 10ms

        while (true) {
            if ($this->pendingCount() === 0) {
                return true;
            }

            if ($timeoutSeconds !== null && (time() - $start) >= $timeoutSeconds) {
                return false;
            }

            usleep($sleepMicroseconds);
        }
    }

    /**
     * Check how many pending (unconfirmed) messages.
     */
    public function pendingCount(): int
    {
        $count = 0;
        foreach ($this->pending as $confirmed) {
            if (!$confirmed) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get list of unconfirmed message IDs.
     *
     * @return list<string>
     */
    public function pendingIds(): array
    {
        $ids = [];
        foreach ($this->pending as $id => $confirmed) {
            if (!$confirmed) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
