<?php

declare(strict_types=1);

namespace FileBroker\Stream;

use FileBroker\Exception\BrokerException;

/**
 * Core stream logic — persistent message log with consumer-group offsets.
 *
 * In stream mode, messages are NOT deleted after acknowledge().
 * Consumer groups track their own offset and can replay from any position.
 *
 * @phpstan-type StreamMessage array{id: string, body: string, headers: array<string, string>, created_at: string, offset: int}
 * @phpstan-type StreamStats array{queue_name: string, total_messages: int, consumer_groups: list<string>, total_size_bytes: int, oldest_message: string|null, newest_message: string|null}
 */
final class Stream
{
    /** @var resource|null */
    private $activeLock = null;

    public function __construct(
        private readonly StreamConfig $config,
        private readonly OffsetManager $offsetManager,
        private readonly string $queuePath,
    ) {}

    // ──────────────────────────── Public API ────────────────────────────

    /**
     * Consume the next message for a consumer group (load-balanced within the group).
     *
     * Uses flock-based coordination so multiple consumers in the same group
     * receive different messages.
     *
     * @return StreamMessage|null
     */
    public function consume(string $consumerGroup): ?array
    {
        $messages = $this->getSortedMessages();

        if ($messages === []) {
            return null;
        }

        $lockPath = $this->groupLockPath($consumerGroup);
        $acquired = false;

        try {
            $acquired = $this->tryLock($lockPath);
            if (!$acquired) {
                return null; // Another consumer is active — skip
            }

            // Use effective offset: max(committed, claimed) to prevent re-delivery
            $committedOffset = $this->offsetManager->get($this->config->queueName, $consumerGroup)->offset;
            $claimedOffset = $this->readClaimedOffset($consumerGroup);
            $currentOffset = max($committedOffset, $claimedOffset);

            if ($currentOffset >= \count($messages)) {
                return null; // All messages have been consumed
            }

            $messageFile = $messages[$currentOffset];

            // Read message from file
            $content = file_get_contents($this->queuePath . '/' . $messageFile);
            if ($content === false || $content === '') {
                return null;
            }

            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($data)) {
                return null;
            }

            // Mark this offset as claimed so other consumers skip it
            $this->writeClaimedOffset($consumerGroup, $currentOffset + 1);

            return [
                'id' => $data['id'] ?? '',
                'body' => $data['body'] ?? '',
                'headers' => $data['headers'] ?? [],
                'created_at' => $data['created_at'] ?? '',
                'offset' => $currentOffset,
            ];
        } catch (\JsonException $e) {
            throw new BrokerException(
                \sprintf('Failed to decode stream message: %s', $e->getMessage()),
                0,
                $e,
            );
        } finally {
            if ($acquired) {
                $this->unlock();
            }
        }
    }

    /**
     * Acknowledge message processing — commits the offset.
     * In stream mode, the message file is NOT deleted.
     */
    public function acknowledge(string $consumerGroup, int $offset): void
    {
        // Commit the next offset (offset + 1) since $offset is the one just processed
        $this->offsetManager->commit($this->config->queueName, $consumerGroup, $offset + 1);

        // Clean up the claimed offset file if it matches
        $claimedOffset = $this->readClaimedOffset($consumerGroup);
        if ($claimedOffset === $offset + 1) {
            $this->clearClaimedOffset($consumerGroup);
        }
    }

    /**
     * Replay messages from a given offset for a consumer group.
     *
     * @return list<StreamMessage>
     */
    public function replay(string $consumerGroup, int $fromOffset = 0, ?int $limit = null): array
    {
        $messages = $this->getSortedMessages();
        $result = [];

        foreach ($messages as $index => $messageFile) {
            if ($index < $fromOffset) {
                continue;
            }

            if ($limit !== null && \count($result) >= $limit) {
                break;
            }

            $content = file_get_contents($this->queuePath . '/' . $messageFile);
            if ($content === false || $content === '') {
                continue;
            }

            try {
                $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
                if (!\is_array($data)) {
                    continue;
                }
            } catch (\JsonException) {
                continue;
            }

            $result[] = [
                'id' => $data['id'] ?? '',
                'body' => $data['body'] ?? '',
                'headers' => $data['headers'] ?? [],
                'created_at' => $data['created_at'] ?? '',
                'offset' => $index,
            ];
        }

        return $result;
    }

    /**
     * Get the current committed offset for a consumer group.
     */
    public function getOffset(string $consumerGroup): int
    {
        return $this->offsetManager->get($this->config->queueName, $consumerGroup)->offset;
    }

    /**
     * List all consumer groups for this stream.
     *
     * @return list<string>
     */
    public function listGroups(): array
    {
        return $this->offsetManager->listGroups($this->config->queueName);
    }

    /**
     * Delete messages that exceed the retention policy.
     *
     * @return int Number of messages deleted
     */
    public function enforceRetention(): int
    {
        $messages = $this->getSortedMessages();
        $deleted = 0;

        // 1. Delete messages older than maxRetentionSeconds
        $cutoffTime = time() - $this->config->maxRetentionSeconds;

        foreach ($messages as $messageFile) {
            $filePath = $this->queuePath . '/' . $messageFile;
            $mtime = filemtime($filePath);

            if ($mtime !== false && $mtime < $cutoffTime) {
                unlink($filePath);
                $deleted++;
            }
        }

        // Refresh message list after time-based deletion
        if ($deleted > 0) {
            $messages = $this->getSortedMessages();
        }

        // 2. If total size exceeds maxRetentionBytes, delete oldest messages
        $totalSize = $this->calculateTotalSize($messages);
        while ($totalSize > $this->config->maxRetentionBytes && $messages !== []) {
            $oldest = array_shift($messages);
            $filePath = $this->queuePath . '/' . $oldest;
            $fileSize = filesize($filePath) ?: 0;
            unlink($filePath);
            $totalSize -= $fileSize;
            $deleted++;
        }

        // Refresh message list after size-based deletion
        if ($deleted > 0) {
            $messages = $this->getSortedMessages();
        }

        // 3. If count exceeds maxRetentionMessages, delete oldest
        while (\count($messages) > $this->config->maxRetentionMessages) {
            $oldest = array_shift($messages);
            $filePath = $this->queuePath . '/' . $oldest;
            unlink($filePath);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Get stream statistics.
     *
     * @return StreamStats
     */
    public function stats(): array
    {
        $messages = $this->getSortedMessages();
        $totalSize = $this->calculateTotalSize($messages);

        $oldestMessage = null;
        $newestMessage = null;

        if ($messages !== []) {
            $oldestMessage = $messages[0];
            $newestMessage = $messages[\count($messages) - 1];
        }

        return [
            'queue_name' => $this->config->queueName,
            'total_messages' => \count($messages),
            'consumer_groups' => $this->listGroups(),
            'total_size_bytes' => $totalSize,
            'oldest_message' => $oldestMessage,
            'newest_message' => $newestMessage,
        ];
    }

    // ──────────────────────────── Internal ────────────────────────────

    /**
     * Get all .msg files sorted by filemtime (oldest first, FIFO).
     *
     * @return list<string>
     */
    private function getSortedMessages(): array
    {
        if (!is_dir($this->queuePath)) {
            return [];
        }

        $files = scandir($this->queuePath);
        if ($files === false) {
            return [];
        }

        $messageFiles = [];
        foreach ($files as $file) {
            if (str_starts_with($file, '.') || !str_ends_with($file, '.msg')) {
                continue;
            }
            $messageFiles[] = $file;
        }

        usort(
            $messageFiles,
            fn(string $a, string $b): int => (filemtime($this->queuePath . '/' . $a) <=> filemtime($this->queuePath . '/' . $b))
                ?: ($a <=> $b),
        );

        return $messageFiles;
    }

    /**
     * Calculate total size in bytes for a list of message filenames.
     *
     * @param list<string> $messages
     */
    private function calculateTotalSize(array $messages): int
    {
        $totalSize = 0;
        foreach ($messages as $file) {
            $size = filesize($this->queuePath . '/' . $file);
            if ($size !== false) {
                $totalSize += $size;
            }
        }
        return $totalSize;
    }

    // ──────────────────────────── Claimed offset tracking ──────────────

    /**
     * Read the claimed (in-flight) offset for a consumer group.
     * This prevents multiple consumers in the same group from getting the same message.
     */
    private function readClaimedOffset(string $consumerGroup): int
    {
        $path = $this->claimedOffsetPath($consumerGroup);

        if (!file_exists($path)) {
            return 0;
        }

        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return 0;
        }

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            if (\is_array($data) && isset($data['next_offset'])) {
                return (int) $data['next_offset'];
            }
        } catch (\JsonException) {
            // Corrupt — treat as no claim
        }

        return 0;
    }

    /**
     * Write the claimed (in-flight) offset for a consumer group.
     */
    private function writeClaimedOffset(string $consumerGroup, int $nextOffset): void
    {
        $path = $this->claimedOffsetPath($consumerGroup);
        $dir = \dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = json_encode(['next_offset' => $nextOffset], \JSON_THROW_ON_ERROR);
        file_put_contents($path, $data, \LOCK_EX);
    }

    /**
     * Remove the claimed offset file (cleanup after acknowledge).
     */
    private function clearClaimedOffset(string $consumerGroup): void
    {
        $path = $this->claimedOffsetPath($consumerGroup);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function claimedOffsetPath(string $consumerGroup): string
    {
        return $this->offsetManager->getStoragePath() . '/streams/' . $this->config->queueName . '/groups/' . $consumerGroup . '.claimed';
    }

    // ──────────────────────────── Locking ──────────────────────────────

    private function groupLockPath(string $consumerGroup): string
    {
        return $this->offsetManager->getStoragePath() . '/streams/' . $this->config->queueName . '/groups/' . $consumerGroup . '.lock';
    }

    /**
     * Try to acquire an exclusive lock on a file.
     */
    private function tryLock(string $lockPath): bool
    {
        if ($this->activeLock !== null) {
            return false;
        }

        $dir = \dirname($lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            return false;
        }

        $this->activeLock = $handle;

        $start = time();

        while (true) {
            if (flock($this->activeLock, \LOCK_EX | \LOCK_NB)) {
                return true;
            }

            if (time() - $start >= 5) { // 5-second timeout for stream locks
                $this->activeLock = null;
                return false;
            }

            usleep(50_000); // 50ms
        }
    }

    private function unlock(): void
    {
        if ($this->activeLock === null) {
            return;
        }

        flock($this->activeLock, \LOCK_UN);
        fclose($this->activeLock);
        $this->activeLock = null;
    }
}
