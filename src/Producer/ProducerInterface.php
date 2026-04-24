<?php

declare(strict_types=1);

namespace FileBroker\Producer;

use FileBroker\Message\Message;

/**
 * Interface for message producers.
 */
interface ProducerInterface
{
    /**
     * Send a message to a queue.
     *
     * @param string $queueName Queue to send to
     * @param string $body Message body
     * @param string|null $messageId Optional message ID
     * @param array<string, string> $headers Optional headers
     * @param int|null $ttlSeconds Optional TTL in seconds
     * @return Message The produced message
     */
    public function send(
        string $queueName,
        string $body,
        ?string $messageId = null,
        array $headers = [],
        ?int $ttlSeconds = null,
    ): Message;

    /**
     * Send multiple messages in a batch.
     *
     * @param string $queueName Queue to send to
     * @param list<string> $bodies Message bodies
     * @return list<Message>
     */
    public function sendBatch(
        string $queueName,
        array $bodies,
        array $headers = [],
        ?int $ttlSeconds = null,
    ): array;

    /**
     * Send a message with a specific content type header.
     */
    public function sendWithContentType(
        string $queueName,
        string $body,
        string $contentType,
        ?string $messageId = null,
        ?int $ttlSeconds = null,
    ): Message;
}
