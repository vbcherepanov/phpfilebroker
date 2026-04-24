<?php

declare(strict_types=1);

namespace FileBroker\Config;

/**
 * Configuration for a single message queue.
 * Defines storage paths, TTL, retry policy, and DLQ settings.
 */
final class QueueConfig
{
    public function __construct(
        public readonly string $name,
        public readonly string $basePath,
        public readonly ?int $defaultTtlSeconds = null,
        public readonly int $maxRetryAttempts = 3,
        public readonly int $retryDelaySeconds = 60,
        public readonly bool $enableDeadLetter = true,
        public readonly ?string $deadLetterQueueName = null,
        public readonly int $maxMessageSizeBytes = 10_485_760, // 10MB
    ) {}

    /**
     * Build a QueueConfig from an associative array.
     */
    public static function fromArray(array $data): self
    {
        $name = $data['name'] ?? $data['queue'] ?? throw new \InvalidArgumentException('Queue name is required');

        $basePath = $data['base_path']
            ?? rtrim($data['path'] ?? sys_get_temp_dir() . '/file-broker', '/');

        return new self(
            name: (string) $name,
            basePath: (string) $basePath,
            defaultTtlSeconds: $data['default_ttl'] ?? null,
            maxRetryAttempts: (int) ($data['max_retry'] ?? 3),
            retryDelaySeconds: (int) ($data['retry_delay'] ?? 60),
            enableDeadLetter: (bool) ($data['dead_letter'] ?? true),
            deadLetterQueueName: $data['dead_letter_queue'] ?? null,
            maxMessageSizeBytes: (int) ($data['max_message_size'] ?? 10_485_760),
        );
    }

    /**
     * Convert to a plain array for serialization / CLI display.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'base_path' => $this->basePath,
            'default_ttl' => $this->defaultTtlSeconds,
            'max_retry' => $this->maxRetryAttempts,
            'retry_delay' => $this->retryDelaySeconds,
            'dead_letter' => $this->enableDeadLetter,
            'dead_letter_queue' => $this->deadLetterQueueName,
            'max_message_size' => $this->maxMessageSizeBytes,
        ];
    }

    public function deadLetterPath(): string
    {
        $dlqName = $this->deadLetterQueueName ?? "{$this->name}.dlq";
        return rtrim($this->basePath, '/') . "/dead-letter/{$dlqName}";
    }

    public function retryPath(): string
    {
        return rtrim($this->basePath, '/') . "/retry/{$this->name}";
    }

    public function messagesPath(): string
    {
        return rtrim($this->basePath, '/') . "/queues/{$this->name}";
    }
}
