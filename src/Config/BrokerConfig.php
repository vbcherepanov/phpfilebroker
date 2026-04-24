<?php

declare(strict_types=1);

namespace FileBroker\Config;

/**
 * Global broker configuration.
 *
 * @phpstan-type BrokerConfigData array{
 *   storage_path: string,
 *   queues: array<string, array{name?: string, base_path?: string, default_ttl?: int, max_retry?: int, retry_delay?: int, dead_letter?: bool, dead_letter_queue?: string, max_message_size?: int}>,
 *   default_queue: string|null,
 *   lock_timeout: int,
 *   poll_interval: int,
 *   max_workers: int
 * }
 */
final class BrokerConfig
{
    public function __construct(
        public readonly string $storagePath,
        /** @var array<string, QueueConfig> */
        public readonly array $queues,
        public readonly ?string $defaultQueue,
        public readonly int $lockTimeout,
        public readonly int $pollInterval,
        public readonly int $maxWorkers,
    ) {}

    /**
     * Load configuration from a YAML-like array (or JSON file).
     */
    public static function fromArray(array $data): self
    {
        $storagePath = $data['storage_path'] ?? sys_get_temp_dir() . '/file-broker';

        $queues = [];
        if (isset($data['queues']) && \is_array($data['queues'])) {
            foreach ($data['queues'] as $name => $q) {
                $queues[$name] = QueueConfig::fromArray(\is_array($q) ? $q : ['name' => $name]);
            }
        }

        return new self(
            storagePath: (string) $storagePath,
            queues: $queues,
            defaultQueue: $data['default_queue'] ?? null,
            lockTimeout: (int) ($data['lock_timeout'] ?? 30),
            pollInterval: (int) ($data['poll_interval'] ?? 1),
            maxWorkers: (int) ($data['max_workers'] ?? 4),
        );
    }

    /**
     * Load configuration from a JSON file.
     *
     * @throws \RuntimeException on file not found or invalid JSON
     */
    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException(\sprintf('Config file not found: %s', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(\sprintf('Cannot read config file: %s', $path));
        }

        $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new \InvalidArgumentException('Config must be a JSON object');
        }

        return self::fromArray($data);
    }

    /**
     * Default configuration — no pre-defined queues.
     */
    public static function default(): self
    {
        return new self(
            storagePath: sys_get_temp_dir() . '/file-broker',
            queues: [],
            defaultQueue: null,
            lockTimeout: 30,
            pollInterval: 1,
            maxWorkers: 4,
        );
    }

    /**
     * Convert to a plain array.
     */
    public function toArray(): array
    {
        return [
            'storage_path' => $this->storagePath,
            'queues' => array_map(
                fn(QueueConfig $q) => $q->toArray(),
                $this->queues,
            ),
            'default_queue' => $this->defaultQueue,
            'lock_timeout' => $this->lockTimeout,
            'poll_interval' => $this->pollInterval,
            'max_workers' => $this->maxWorkers,
        ];
    }

    /**
     * Register a queue at runtime.
     */
    public function withQueue(QueueConfig $queue): self
    {
        $queues = $this->queues;
        $queues[$queue->name] = $queue;
        return new self(
            $this->storagePath,
            $queues,
            $this->defaultQueue,
            $this->lockTimeout,
            $this->pollInterval,
            $this->maxWorkers,
        );
    }

    /**
     * Remove a queue from configuration.
     */
    public function withoutQueue(string $name): self
    {
        $queues = $this->queues;
        unset($queues[$name]);
        return new self(
            $this->storagePath,
            $queues,
            $this->defaultQueue,
            $this->lockTimeout,
            $this->pollInterval,
            $this->maxWorkers,
        );
    }
}
