<?php

declare(strict_types=1);

namespace FileBroker\Broker;

use FileBroker\Config\BrokerConfig;
use FileBroker\Config\QueueConfig;
use FileBroker\Consumer\ConsumerInterface;
use FileBroker\Consumer\FileConsumer;
use FileBroker\Event\EventDispatcher;
use FileBroker\Event\MessageAcknowledgedEvent;
use FileBroker\Event\MessageConsumedEvent;
use FileBroker\Event\MessageDeadLetteredEvent;
use FileBroker\Event\MessageProducedEvent;
use FileBroker\Event\MessageRejectedEvent;
use FileBroker\Event\MessageRetryEvent;
use FileBroker\Exception\BrokerException;
use FileBroker\Exception\DeserializationException;
use FileBroker\Exception\MessageTooLargeException;
use FileBroker\Exception\QueueNotFoundException;
use FileBroker\Exchange\ExchangeRegistry;
use FileBroker\Flow\PrefetchController;
use FileBroker\Logging\Logger;
use FileBroker\Logging\LogLevel;
use FileBroker\Message\Message;
use FileBroker\Message\MessagePayloadFactory;
use FileBroker\Observability\MetricsCollector;
use FileBroker\Observability\MetricsSubscriber;
use FileBroker\Producer\FileProducer;
use FileBroker\Producer\ProducerInterface;
use FileBroker\Reliability\PublisherConfirm;
use FileBroker\Stream\OffsetManager;
use FileBroker\Stream\Stream;
use FileBroker\Stream\StreamConfig;

/**
 * Core message broker — thread-safe, filesystem-backed.
 *
 * Provides the main API for producing and consuming messages
 * across named queues backed by the filesystem.
 *
 * @phpstan-type QueueStats array{
 *   queue: string,
 *   message_count: int,
 *   retry_count: int,
 *   dead_letter_count: int,
 *   oldest_message: string|null,
 *   newest_message: string|null,
 *   total_size_bytes: int
 * }
 */
class MessageBroker
{
    private BrokerConfig $config;
    private MessagePayloadFactory $payloadFactory;
    private EventDispatcher $dispatcher;
    private ?MetricsCollector $metrics = null;
    private ?Logger $logger = null;
    private ExchangeRegistry $exchangeRegistry;
    private ?PublisherConfirm $publisherConfirm = null;
    private ?PrefetchController $prefetchController = null;
    /** @var array<string, Stream> */
    private array $streams = [];
    private ?OffsetManager $offsetManager = null;
    /** @var array<string, string> key → message_id */
    private array $deduplicationCache = [];
    private bool $initialized = false;
    /** @var resource|null */
    private $activeLock = null;

    public function __construct(
        ?BrokerConfig $config = null,
        ?MessagePayloadFactory $payloadFactory = null,
        ?EventDispatcher $dispatcher = null,
        ?MetricsCollector $metrics = null,
        ?Logger $logger = null,
        ?ExchangeRegistry $exchangeRegistry = null,
        ?PublisherConfirm $publisherConfirm = null,
        ?PrefetchController $prefetchController = null,
        ?OffsetManager $offsetManager = null,
    ) {
        $this->config = $config ?? BrokerConfig::default();
        $this->payloadFactory = $payloadFactory ?? new MessagePayloadFactory();
        $this->dispatcher = $dispatcher ?? new EventDispatcher();
        $this->metrics = $metrics;
        $this->logger = $logger;
        $this->exchangeRegistry = $exchangeRegistry ?? new ExchangeRegistry($this->config->storagePath . '/exchanges');
        $this->publisherConfirm = $publisherConfirm;
        $this->prefetchController = $prefetchController;
        $this->offsetManager = $offsetManager;

        if ($this->metrics !== null) {
            $subscriber = new MetricsSubscriber($this->metrics);
            $subscriber->subscribe($this->dispatcher);
        }
    }

    // ──────────────────────────── Public API ────────────────────────────

    /**
     * Produce (send) a message to a queue.
     *
     * Transactional: if event dispatcher fails, the written message file is removed.
     *
     * @throws QueueNotFoundException
     * @throws MessageTooLargeException
     * @throws BrokerException
     */
    public function produce(
        string $queueName,
        string $body,
        ?string $messageId = null,
        array $headers = [],
        ?int $ttl = null,
        int $priority = 0,
        ?string $key = null,
    ): Message {
        $this->ensureInitialized();

        $queueConfig = $this->getQueueConfig($queueName);

        // Deduplication: if key is set and already exists, return existing message (idempotent)
        if ($key !== null && isset($this->deduplicationCache[$key])) {
            $this->logger?->log(LogLevel::Info->value, 'Duplicate message key skipped', [
                'queue' => $queueName,
                'key' => $key,
                'existing_message_id' => $this->deduplicationCache[$key],
            ]);
            return Message::create(
                body: $body,
                id: $this->deduplicationCache[$key],
                headers: $headers,
                ttlSeconds: $ttl ?? $queueConfig->defaultTtlSeconds,
                priority: $priority,
                key: $key,
            );
        }

        // Check message size
        $messageSize = \strlen($body);
        if ($messageSize > $queueConfig->maxMessageSizeBytes) {
            $this->logger?->log(LogLevel::Error->value, 'Message too large for queue', [
                'queue' => $queueName,
                'message_size' => $messageSize,
                'max_size' => $queueConfig->maxMessageSizeBytes,
            ]);
            throw new MessageTooLargeException($messageSize, $queueConfig->maxMessageSizeBytes);
        }

        $message = Message::create(
            body: $body,
            id: $messageId,
            headers: $headers,
            ttlSeconds: $ttl ?? $queueConfig->defaultTtlSeconds,
            priority: $priority,
            key: $key,
        );

        // Write message to disk.
        $this->writeMessage($queueConfig, $message);

        // Track in deduplication cache
        if ($key !== null) {
            $this->deduplicationCache[$key] = $message->id;
        }

        // Publisher confirm — message was durably written
        $this->publisherConfirm?->confirm($message->id);

        try {
            // Dispatch event; if it throws, remove the message written above.
            $this->dispatcher->dispatch(new MessageProducedEvent(
                message: $message,
                queueName: $queueName,
                filePath: $this->getMessagePath($queueConfig, $message->id),
            ));
        } catch (\Throwable $e) {
            @unlink($this->getMessagePath($queueConfig, $message->id));

            $this->logger?->log(LogLevel::Error->value, 'Failed to dispatch MessageProducedEvent', [
                'queue' => $queueName,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            throw new BrokerException(
                \sprintf('Failed to dispatch MessageProducedEvent: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        return $message;
    }

    /**
     * Produce multiple messages to a queue atomically.
     *
     * @param list<array{body: string, headers?: array<string, string>, priority?: int, key?: string, ttl?: int}> $messages
     * @return list<string> Message IDs
     */
    public function produceBatch(string $queueName, array $messages): array
    {
        $this->ensureInitialized();
        $this->getQueueConfig($queueName); // Validates queue exists

        $ids = [];
        foreach ($messages as $msg) {
            $body = $msg['body'];
            $headers = (array) ($msg['headers'] ?? []);
            $priority = (int) ($msg['priority'] ?? 0);
            $key = $msg['key'] ?? null;
            $ttl = $msg['ttl'] ?? null;

            $message = $this->produce(
                queueName: $queueName,
                body: $body,
                headers: $headers,
                ttl: $ttl,
                priority: $priority,
                key: $key,
            );
            $ids[] = $message->id;
        }

        return $ids;
    }

    /**
     * Acknowledge multiple messages at once.
     *
     * @param list<string> $messageIds
     */
    public function acknowledgeBatch(string $queueName, array $messageIds): void
    {
        foreach ($messageIds as $messageId) {
            $this->acknowledge($queueName, $messageId);
        }
    }

    /**
     * Produce a message with publisher confirmation (blocking).
     *
     * Registers the message in PublisherConfirm, produces it, then waits
     * for all pending confirms.
     */
    public function produceWithConfirm(
        string $queueName,
        string $body,
        ?string $messageId = null,
        array $headers = [],
        ?int $ttl = null,
        int $priority = 0,
        ?string $key = null,
    ): Message {
        // Register in publisher confirm before producing
        $message = $this->produce(
            queueName: $queueName,
            body: $body,
            messageId: $messageId,
            headers: $headers,
            ttl: $ttl,
            priority: $priority,
            key: $key,
        );

        // Block until all pending confirms complete
        $this->publisherConfirm?->waitForAll(10);

        return $message;
    }

    /**
     * Publish a message through an exchange (routed to matching queues).
     *
     * @param array<string, mixed> $options Supports: message_id, headers, ttl
     * @return list<string> List of produced message IDs (one per matched queue)
     * @throws \RuntimeException
     * @throws BrokerException
     */
    public function publish(string $exchangeName, string $routingKey, string $body, array $options = []): array
    {
        $this->ensureInitialized();

        $exchange = $this->exchangeRegistry->get($exchangeName);
        if ($exchange === null) {
            throw new \RuntimeException(\sprintf('Exchange not found: %s', $exchangeName));
        }

        $messageHeaders = (array) ($options['headers'] ?? []);
        $matchedQueues = $exchange->route($routingKey, $messageHeaders);

        $ids = [];
        foreach ($matchedQueues as $queueName) {
            $message = $this->produce(
                queueName: $queueName,
                body: $body,
                messageId: $options['message_id'] ?? null,
                headers: $messageHeaders,
                ttl: $options['ttl'] ?? null,
            );
            $ids[] = $message->id;
        }

        return $ids;
    }

    /**
     * Consume (receive) the next message from a queue.
     * Returns null if queue is empty.
     *
     * @throws QueueNotFoundException
     * @throws DeserializationException
     * @throws BrokerException
     */
    public function consume(string $queueName): ?Message
    {
        $this->ensureInitialized();

        $queueConfig = $this->getQueueConfig($queueName);
        $messagesPath = $queueConfig->messagesPath();

        if (!is_dir($messagesPath)) {
            return null;
        }

        $lockPath = $messagesPath . '/.lock';
        $acquired = false;

        try {
            $acquired = $this->tryLock($lockPath);
            if (!$acquired) {
                // Another worker is processing — skip
                return null;
            }

            $files = $this->getSortedMessageFiles($messagesPath);

            foreach ($files as $filename) {
                $filePath = $messagesPath . '/' . $filename;

                // Skip lock files
                if (str_starts_with($filename, '.')) {
                    continue;
                }

                $message = $this->readMessageFile($filePath);

                // Check expiration
                if ($message->isExpired()) {
                    $this->moveToDeadLetter($queueConfig, $filePath, 'TTL expired');
                    continue;
                }

                // Check delivery count
                if ($message->deliveryCount >= $queueConfig->maxRetryAttempts) {
                    $this->moveToDeadLetter($queueConfig, $filePath, 'Max retry attempts exceeded');
                    continue;
                }

                // "Claim" the message by incrementing delivery count
                $claimedMessage = $message->incrementDeliveryCount();
                $this->writeMessageFile($filePath, $claimedMessage);

                // Release lock before dispatching
                $this->unlock();

                $this->dispatcher->dispatch(new MessageConsumedEvent(
                    message: $claimedMessage,
                    queueName: $queueName,
                    filePath: $filePath,
                ));

                return $claimedMessage;
            }

            // Check retry-path for messages whose retry time has arrived
            $retryMessage = $this->processRetryMessages($queueConfig);
            if ($retryMessage !== null) {
                return $retryMessage;
            }

            return null;

        } finally {
            if ($acquired) {
                $this->unlock();
            }
        }
    }

    /**
     * Check the retry directory for messages whose retry delay has elapsed.
     * When found, moves the message back to the main queue and returns it.
     */
    private function processRetryMessages(QueueConfig $queueConfig): ?Message
    {
        $retryPath = $queueConfig->retryPath();
        $messagesPath = $queueConfig->messagesPath();

        if (!is_dir($retryPath)) {
            return null;
        }

        $files = scandir($retryPath) ?: [];
        $retryFiles = [];

        foreach ($files as $file) {
            if (str_starts_with($file, '.')) {
                continue;
            }
            $retryFiles[] = $file;
        }

        usort(
            $retryFiles,
            fn(string $a, string $b): int => (filemtime($retryPath . '/' . $a) <=> filemtime($retryPath . '/' . $b))
                ?: ($a <=> $b),
        );

        foreach ($retryFiles as $filename) {
            $filePath = $retryPath . '/' . $filename;

            if (str_starts_with($filename, '.')) {
                continue;
            }

            $message = $this->readMessageFile($filePath);

            // Check if retry time has elapsed
            $retryAt = $message->headers['_broker_retry_at'] ?? null;
            if ($retryAt !== null) {
                $retryTime = \DateTimeImmutable::createFromFormat(\DateTimeImmutable::ATOM, $retryAt);
                if ($retryTime !== false && new \DateTimeImmutable() < $retryTime) {
                    continue; // Not yet time to retry
                }
            }

            // Remove retry headers before moving back to main queue
            $headers = $message->headers;
            unset($headers['_broker_retry_reason'], $headers['_broker_retry_at']);

            $cleanMessage = $message->withHeaders($headers);

            // Write to main queue
            $destPath = $this->getMessagePath($queueConfig, $cleanMessage->id);
            $this->writeMessageFile($destPath, $cleanMessage);

            // Remove from retry
            @unlink($filePath);

            // Increment delivery count and return
            $claimed = $cleanMessage->incrementDeliveryCount();
            $this->writeMessageFile($destPath, $claimed);

            $this->dispatcher->dispatch(new MessageConsumedEvent(
                message: $claimed,
                queueName: $queueConfig->name,
                filePath: $destPath,
            ));

            return $claimed;
        }

        return null;
    }

    /**
     * Acknowledge a message has been processed successfully.
     * Deletes the message file from the queue.
     *
     * @throws BrokerException
     */
    public function acknowledge(string $queueName, string $messageId): void
    {
        $this->ensureInitialized();
        $queueConfig = $this->getQueueConfig($queueName);
        $filePath = $this->getMessagePath($queueConfig, $messageId);

        if (file_exists($filePath)) {
            // Read message before deletion for event payload
            $message = $this->readMessageFile($filePath);

            // Use atomic rename for safe deletion
            $trashPath = $filePath . '.deleted.' . getmypid();
            if (!rename($filePath, $trashPath)) {
                unlink($filePath);
            }

            $this->dispatcher->dispatch(new MessageAcknowledgedEvent(
                message: $message,
                queueName: $queueName,
            ));
        }
    }

    /**
     * Reject a message — either retry or send to dead letter.
     *
     * @throws BrokerException
     */
    public function reject(string $queueName, string $messageId, string $reason = 'Rejected'): void
    {
        $this->ensureInitialized();
        $queueConfig = $this->getQueueConfig($queueName);
        $filePath = $this->getMessagePath($queueConfig, $messageId);

        $message = $this->readMessageFile($filePath);

        $this->dispatcher->dispatch(new MessageRejectedEvent(
            message: $message,
            queueName: $queueName,
            reason: $reason,
        ));

        $this->dispatcher->dispatch(new MessageRetryEvent(
            message: $message,
            queueName: $queueName,
            attempt: $message->deliveryCount,
            delaySeconds: $queueConfig->retryDelaySeconds,
        ));

        $this->moveToRetry($queueConfig, $filePath, $message, $reason);
    }

    /**
     * Move a message directly to the dead letter queue.
     *
     * @throws BrokerException
     */
    public function deadLetter(string $queueName, string $messageId, string $reason = 'Manual'): void
    {
        $this->ensureInitialized();
        $queueConfig = $this->getQueueConfig($queueName);
        $filePath = $this->getMessagePath($queueConfig, $messageId);

        $message = $this->readMessageFile($filePath);

        $this->dispatcher->dispatch(new MessageDeadLetteredEvent(
            message: $message,
            queueName: $queueName,
            reason: $reason,
        ));

        $this->moveToDeadLetter($queueConfig, $filePath, $reason);
    }

    // ──────────────────────────── Queue Management ────────────────────────────

    /**
     * Get a list of all registered queue names.
     *
     * @return list<string>
     */
    public function listQueues(): array
    {
        return array_keys($this->config->queues);
    }

    /**
     * Check if a queue exists.
     */
    public function hasQueue(string $queueName): bool
    {
        return isset($this->config->queues[$queueName]);
    }

    /**
     * Get statistics for a queue.
     *
     * @return QueueStats
     */
    public function getQueueStats(string $queueName): array
    {
        $this->ensureInitialized();
        $queueConfig = $this->getQueueConfig($queueName);

        $stats = [
            'queue' => $queueName,
            'message_count' => 0,
            'retry_count' => 0,
            'dead_letter_count' => 0,
            'oldest_message' => null,
            'newest_message' => null,
            'total_size_bytes' => 0,
        ];

        $messagesPath = $queueConfig->messagesPath();
        if (is_dir($messagesPath)) {
            $files = scandir($messagesPath) ?: [];
            foreach ($files as $file) {
                if (!str_ends_with($file, '.msg')) {
                    continue;
                }
                $filePath = $messagesPath . '/' . $file;
                if (is_file($filePath)) {
                    $stats['message_count']++;
                    $stats['total_size_bytes'] += filesize($filePath) ?: 0;
                    if ($stats['oldest_message'] === null || $file < $stats['oldest_message']) {
                        $stats['oldest_message'] = $file;
                    }
                    if ($stats['newest_message'] === null || $file > $stats['newest_message']) {
                        $stats['newest_message'] = $file;
                    }
                }
            }
        }

        // Retry count
        $retryPath = $queueConfig->retryPath();
        if (is_dir($retryPath)) {
            $retryFiles = array_values(array_filter(
                scandir($retryPath) ?: [],
                static fn(string $f): bool => str_ends_with($f, '.msg'),
            ));
            $stats['retry_count'] = \count($retryFiles);
        }

        // Dead letter count
        $dlqPath = $queueConfig->deadLetterPath();
        if (is_dir($dlqPath)) {
            $dlqFiles = array_values(array_filter(
                scandir($dlqPath) ?: [],
                static fn(string $f): bool => str_ends_with($f, '.msg'),
            ));
            $stats['dead_letter_count'] = \count($dlqFiles);
        }

        return $stats;
    }

    /**
     * Purge (delete all messages) from a queue.
     *
     * @throws BrokerException
     */
    public function purge(string $queueName): void
    {
        $this->ensureInitialized();
        $queueConfig = $this->getQueueConfig($queueName);

        $this->purgePath($queueConfig->messagesPath());
        $this->purgePath($queueConfig->retryPath());

        if ($queueConfig->enableDeadLetter) {
            $this->purgePath($queueConfig->deadLetterPath());
        }
    }

    /**
     * Create a dynamic queue at runtime.
     */
    public function createQueue(QueueConfig $config): void
    {
        // Create a new config with the queue and update.
        $newConfig = $this->config->withQueue($config);

        // Flush and reinitialize to avoid stale state.
        $this->initialized = false;
        $this->config = $newConfig;

        // Re-initialize all queue paths including the new one.
        foreach ($this->config->queues as $queueConfig) {
            $this->initializeQueuePaths($queueConfig);
        }

        $this->initialized = true;
    }

    /**
     * Delete a queue (and all its messages).
     *
     * @throws QueueNotFoundException
     */
    public function deleteQueue(string $queueName): void
    {
        if (!$this->hasQueue($queueName)) {
            throw new QueueNotFoundException($queueName);
        }

        $queueConfig = $this->config->queues[$queueName];
        $this->purgePath($queueConfig->messagesPath());
        $this->purgePath($queueConfig->retryPath());
        $this->purgePath($queueConfig->deadLetterPath());

        $this->config = $this->config->withoutQueue($queueName);
    }

    // ──────────────────────────── Low-level Producer/Consumer ────────────────────────────

    public function getProducer(): ProducerInterface
    {
        return new FileProducer($this);
    }

    public function getConsumer(): ConsumerInterface
    {
        return new FileConsumer($this);
    }

    // ──────────────────────────── Event Dispatcher ────────────────────────────

    public function getEventDispatcher(): EventDispatcher
    {
        return $this->dispatcher;
    }

    public function getMetrics(): ?MetricsCollector
    {
        return $this->metrics;
    }

    public function getConfig(): BrokerConfig
    {
        return $this->config;
    }

    public function getExchangeRegistry(): ExchangeRegistry
    {
        return $this->exchangeRegistry;
    }

    public function getOffsetManager(): OffsetManager
    {
        $this->ensureInitialized();
        \assert($this->offsetManager !== null);
        return $this->offsetManager;
    }

    public function getPublisherConfirm(): ?PublisherConfirm
    {
        return $this->publisherConfirm;
    }

    public function getPrefetchController(): ?PrefetchController
    {
        return $this->prefetchController;
    }

    // ──────────────────────────── Stream API ────────────────────────────

    /**
     * Enable stream mode for a queue. Messages are NOT deleted after acknowledge.
     */
    public function enableStream(string $queueName, ?StreamConfig $config = null): void
    {
        $this->ensureInitialized();
        $this->getQueueConfig($queueName); // Validate queue exists

        $streamConfig = $config ?? new StreamConfig(queueName: $queueName, enabled: true);
        $stream = new Stream(
            config: $streamConfig,
            offsetManager: $this->getOffsetManager(),
            queuePath: $this->getQueueConfig($queueName)->messagesPath(),
        );
        $this->streams[$queueName] = $stream;
    }

    /**
     * Disable stream mode for a queue (back to regular queue).
     */
    public function disableStream(string $queueName): void
    {
        unset($this->streams[$queueName]);
    }

    /**
     * Consume the next message from a stream queue with consumer group.
     *
     * @return array{id: string, body: string, headers: array<string, string>, created_at: string, offset: int}|null
     */
    public function streamConsume(string $queueName, string $consumerGroup): ?array
    {
        $stream = $this->getStream($queueName);
        if ($stream === null) {
            throw new \RuntimeException(\sprintf('Stream not enabled for queue: %s', $queueName));
        }

        return $stream->consume($consumerGroup);
    }

    /**
     * Acknowledge a stream message (commit offset).
     */
    public function streamAcknowledge(string $queueName, string $consumerGroup, int $offset): void
    {
        $stream = $this->getStream($queueName);
        if ($stream === null) {
            throw new \RuntimeException(\sprintf('Stream not enabled for queue: %s', $queueName));
        }

        $stream->acknowledge($consumerGroup, $offset);
    }

    /**
     * Replay messages from a stream.
     *
     * @return list<array{id: string, body: string, headers: array<string, string>, created_at: string, offset: int}>
     */
    public function streamReplay(string $queueName, string $consumerGroup, int $fromOffset = 0, ?int $limit = null): array
    {
        $stream = $this->getStream($queueName);
        if ($stream === null) {
            throw new \RuntimeException(\sprintf('Stream not enabled for queue: %s', $queueName));
        }

        return $stream->replay($consumerGroup, $fromOffset, $limit);
    }

    /**
     * Get stream statistics.
     *
     * @return array{queue_name: string, total_messages: int, consumer_groups: list<string>, total_size_bytes: int, oldest_message: string|null, newest_message: string|null}
     */
    public function streamStats(string $queueName): array
    {
        $stream = $this->getStream($queueName);
        if ($stream === null) {
            throw new \RuntimeException(\sprintf('Stream not enabled for queue: %s', $queueName));
        }

        return $stream->stats();
    }

    /**
     * Get the Stream instance for a queue, or null if stream is not enabled.
     */
    public function getStream(string $queueName): ?Stream
    {
        return $this->streams[$queueName] ?? null;
    }

    // ──────────────────────────── Internal ────────────────────────────

    /**
     * Get the full file path for a message.
     */
    public function getMessagePath(QueueConfig $queueConfig, string $messageId): string
    {
        return $queueConfig->messagesPath() . '/' . $messageId . '.msg';
    }

    /**
     * Ensure all queue directories exist.
     */
    public function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        // Create storage root
        if (!is_dir($this->config->storagePath)) {
            mkdir($this->config->storagePath, 0755, true);
        }

        foreach ($this->config->queues as $queueConfig) {
            $this->initializeQueuePaths($queueConfig);
        }

        if ($this->offsetManager === null) {
            $this->offsetManager = new OffsetManager($this->config->storagePath);
        }

        $this->initialized = true;
    }

    private function initializeQueuePaths(QueueConfig $queueConfig): void
    {
        $paths = [
            $queueConfig->messagesPath(),
            $queueConfig->retryPath(),
        ];

        if ($queueConfig->enableDeadLetter) {
            $paths[] = $queueConfig->deadLetterPath();
        }

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    private function getQueueConfig(string $queueName): QueueConfig
    {
        if (!isset($this->config->queues[$queueName])) {
            throw new QueueNotFoundException($queueName);
        }
        return $this->config->queues[$queueName];
    }

    private function writeMessage(QueueConfig $queueConfig, Message $message): void
    {
        $filePath = $this->getMessagePath($queueConfig, $message->id);
        $this->writeMessageFile($filePath, $message);
    }

    private function writeMessageFile(string $filePath, Message $message): void
    {
        $dir = \dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = $this->payloadFactory->toJson($message);

        // Atomic write: write to temp file, then rename
        $tmpPath = $filePath . '.tmp.' . getmypid();
        $result = file_put_contents($tmpPath, $content, \LOCK_EX);

        if ($result === false || $result !== \strlen($content)) {
            @unlink($tmpPath);
            $this->logger?->log(LogLevel::Error->value, 'Failed to write message file', [
                'file_path' => $filePath,
                'tmp_path' => $tmpPath,
            ]);
            throw new BrokerException(\sprintf('Failed to write message to %s', $filePath));
        }

        // Atomic rename
        if (!rename($tmpPath, $filePath)) {
            @unlink($tmpPath);
            $this->logger?->log(LogLevel::Error->value, 'Failed to atomically write message file', [
                'file_path' => $filePath,
                'tmp_path' => $tmpPath,
            ]);
            throw new BrokerException(\sprintf('Failed to atomically write message to %s', $filePath));
        }
    }

    private function readMessageFile(string $filePath): Message
    {
        if (!file_exists($filePath)) {
            throw new BrokerException(\sprintf('Message file not found: %s', $filePath));
        }

        $content = file_get_contents($filePath);
        if ($content === false || $content === '') {
            $this->logger?->log(LogLevel::Error->value, 'Empty or unreadable message file', [
                'file_path' => $filePath,
            ]);
            throw new DeserializationException(\sprintf('Empty message file: %s', $filePath));
        }

        try {
            return $this->payloadFactory->fromJson($content);
        } catch (\JsonException $e) {
            $this->logger?->log(LogLevel::Error->value, 'Failed to deserialize message', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw new DeserializationException(
                \sprintf('Failed to deserialize message from %s: %s', $filePath, $e->getMessage()),
                $e->getCode(),
                $e,
            );
        }
    }

    private function moveToRetry(QueueConfig $queueConfig, string $sourcePath, Message $message, string $reason): void
    {
        $retryPath = $queueConfig->retryPath();
        $timestamp = (int) microtime(true) * 1000;
        $destPath = $retryPath . '/' . \sprintf('%s_%s_%s', $message->id, $timestamp, bin2hex(random_bytes(4)));

        // Attach retry metadata as header
        $headers = $message->headers;
        $headers['_broker_retry_reason'] = $reason;
        $delayInterval = \DateInterval::createFromDateString((int) $queueConfig->retryDelaySeconds . ' seconds');
        $headers['_broker_retry_at'] = (new \DateTimeImmutable())
            ->add($delayInterval !== false ? $delayInterval : new \DateInterval('PT0S'))
            ->format(\DateTimeImmutable::ATOM);

        $retryMessage = $message->withHeaders($headers);
        $this->writeMessageFile($destPath, $retryMessage);

        @unlink($sourcePath);
    }

    private function moveToDeadLetter(QueueConfig $queueConfig, string $sourcePath, string $reason): void
    {
        if (!$queueConfig->enableDeadLetter) {
            @unlink($sourcePath);
            return;
        }

        $message = $this->readMessageFile($sourcePath);

        $headers = $message->headers;
        $headers['_broker_dlq_reason'] = $reason;
        $headers['_broker_dlq_at'] = (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM);

        $dlqMessage = $message->withHeaders($headers);

        $dlqPath = $queueConfig->deadLetterPath();
        $timestamp = (int) microtime(true) * 1000;
        $destPath = $dlqPath . '/' . \sprintf('%s_%s_%s', $message->id, $timestamp, bin2hex(random_bytes(4)));

        $this->writeMessageFile($destPath, $dlqMessage);
        @unlink($sourcePath);
    }

    /**
     * Get sorted message files by priority (ascending — 0 first), then by modification time (FIFO).
     *
     * @return list<string>
     */
    private function getSortedMessageFiles(string $path): array
    {
        $files = scandir($path) ?: [];
        $messageFiles = [];

        foreach ($files as $file) {
            if (str_starts_with($file, '.') || !str_ends_with($file, '.msg')) {
                continue;
            }
            $messageFiles[] = $file;
        }

        usort(
            $messageFiles,
            function (string $a, string $b) use ($path): int {
                return ($this->readMessagePriority($path . '/' . $a) <=> $this->readMessagePriority($path . '/' . $b))
                    ?: (filemtime($path . '/' . $a) <=> filemtime($path . '/' . $b))
                    ?: ($a <=> $b);
            },
        );
        return $messageFiles;
    }

    /**
     * Read priority from a message file without full deserialization.
     * Returns 0 if the file is corrupt or priority is not set.
     */
    private function readMessagePriority(string $filePath): int
    {
        $content = @file_get_contents($filePath);
        if ($content === false || $content === '') {
            return 0;
        }

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            if (\is_array($data)) {
                return (int) ($data['priority'] ?? 0);
            }
        } catch (\JsonException) {
            // Corrupt file — treat as default priority
        }

        return 0;
    }

    /**
     * Purge all files from a directory.
     */
    private function purgePath(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = scandir($path) ?: [];
        foreach ($files as $file) {
            $filePath = $path . '/' . $file;
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }
    }

    /**
     * File-based locking for concurrent access.
     */
    private function tryLock(string $lockPath): bool
    {
        if ($this->activeLock !== null) {
            return false;
        }

        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            return false;
        }

        $this->activeLock = $handle;

        $lockTimeout = $this->config->lockTimeout;
        $start = time();

        while (true) {
            if (flock($this->activeLock, \LOCK_EX | \LOCK_NB)) {
                return true;
            }

            if (time() - $start >= $lockTimeout) {
                $this->activeLock = null;
                return false;
            }

            usleep(50000); // 50ms
        }
    }

    private function unlock(): void
    {
        if ($this->activeLock === null) {
            return;
        }

        flock($this->activeLock, \LOCK_UN);
        $this->activeLock = null;
    }
}
