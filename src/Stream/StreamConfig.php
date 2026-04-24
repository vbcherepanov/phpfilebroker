<?php

declare(strict_types=1);

namespace FileBroker\Stream;

/**
 * Configuration for a single stream-enabled queue.
 */
final class StreamConfig
{
    public function __construct(
        public readonly string $queueName,
        public readonly bool $enabled = false,
        public readonly int $maxRetentionSeconds = 86_400,       // 24h
        public readonly int $maxRetentionBytes = 1_073_741_824,  // 1GB
        public readonly int $maxRetentionMessages = 1_000_000,
        public readonly bool $autoCompact = false,
    ) {}

    /**
     * @param array{
     *   queue_name?: string,
     *   queueName?: string,
     *   enabled?: bool,
     *   max_retention_seconds?: int,
     *   max_retention_bytes?: int,
     *   max_retention_messages?: int,
     *   auto_compact?: bool,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $queueName = $data['queue_name'] ?? $data['queueName'] ?? throw new \InvalidArgumentException('queue_name is required');

        return new self(
            queueName: (string) $queueName,
            enabled: (bool) ($data['enabled'] ?? false),
            maxRetentionSeconds: (int) ($data['max_retention_seconds'] ?? 86_400),
            maxRetentionBytes: (int) ($data['max_retention_bytes'] ?? 1_073_741_824),
            maxRetentionMessages: (int) ($data['max_retention_messages'] ?? 1_000_000),
            autoCompact: (bool) ($data['auto_compact'] ?? false),
        );
    }

    /**
     * @return array{
     *   queue_name: string,
     *   enabled: bool,
     *   max_retention_seconds: int,
     *   max_retention_bytes: int,
     *   max_retention_messages: int,
     *   auto_compact: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'queue_name' => $this->queueName,
            'enabled' => $this->enabled,
            'max_retention_seconds' => $this->maxRetentionSeconds,
            'max_retention_bytes' => $this->maxRetentionBytes,
            'max_retention_messages' => $this->maxRetentionMessages,
            'auto_compact' => $this->autoCompact,
        ];
    }
}
