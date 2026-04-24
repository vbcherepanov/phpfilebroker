<?php

declare(strict_types=1);

namespace FileBroker\Stream;

/**
 * Manages consumer group offsets stored as JSON files on disk.
 *
 * Persistence path: storage/streams/<queueName>/offsets/<consumerGroup>.json
 */
final class OffsetManager
{
    public function __construct(
        private readonly string $storagePath,
    ) {}

    /**
     * Get the current offset for a consumer group, or create it starting at 0.
     */
    public function get(string $queueName, string $consumerGroup): ConsumerOffset
    {
        $filePath = $this->offsetFilePath($queueName, $consumerGroup);

        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            if ($content !== false && $content !== '') {
                try {
                    $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
                    if (
                        \is_array($data)
                        && isset($data['consumer_group'], $data['queue_name'], $data['offset'], $data['updated_at'])
                    ) {
                        /** @phpstan-var array{consumer_group: string, queue_name: string, offset: int, updated_at: string} $data */
                        return ConsumerOffset::fromArray($data);
                    }
                } catch (\JsonException) {
                    // Corrupt file — recreate
                }
            }
        }

        return ConsumerOffset::create($consumerGroup, $queueName, 0);
    }

    /**
     * Commit (save) a consumer offset.
     */
    public function commit(string $queueName, string $consumerGroup, int $offset): void
    {
        $filePath = $this->offsetFilePath($queueName, $consumerGroup);
        $dir = \dirname($filePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $consumerOffset = ConsumerOffset::create($consumerGroup, $queueName, $offset);
        $content = json_encode($consumerOffset, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT);

        file_put_contents($filePath, $content, \LOCK_EX);
    }

    /**
     * Reset the offset for a consumer group back to 0.
     */
    public function reset(string $queueName, string $consumerGroup): void
    {
        $this->commit($queueName, $consumerGroup, 0);
    }

    /**
     * List all consumer groups that have offset data for a given queue.
     *
     * @return list<string>
     */
    public function listGroups(string $queueName): array
    {
        $dir = $this->offsetsDir($queueName);

        if (!is_dir($dir)) {
            return [];
        }

        $files = scandir($dir);
        if ($files === false) {
            return [];
        }

        $groups = [];
        foreach ($files as $file) {
            if (str_ends_with($file, '.json')) {
                $groups[] = basename($file, '.json');
            }
        }

        sort($groups);
        return $groups;
    }

    private function offsetFilePath(string $queueName, string $consumerGroup): string
    {
        return $this->offsetsDir($queueName) . '/' . $consumerGroup . '.json';
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    private function offsetsDir(string $queueName): string
    {
        return $this->storagePath . '/streams/' . $queueName . '/offsets';
    }
}
