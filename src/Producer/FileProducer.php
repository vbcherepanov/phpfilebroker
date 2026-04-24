<?php

declare(strict_types=1);

namespace FileBroker\Producer;

use FileBroker\Broker\MessageBroker;
use FileBroker\Message\Message;

/**
 * Default producer implementation backed by the MessageBroker.
 */
final class FileProducer implements ProducerInterface
{
    public function __construct(
        private readonly MessageBroker $broker,
    ) {}

    public function send(
        string $queueName,
        string $body,
        ?string $messageId = null,
        array $headers = [],
        ?int $ttlSeconds = null,
    ): Message {
        return $this->broker->produce(
            queueName: $queueName,
            body: $body,
            messageId: $messageId,
            headers: $headers,
            ttl: $ttlSeconds,
        );
    }

    public function sendBatch(
        string $queueName,
        array $bodies,
        array $headers = [],
        ?int $ttlSeconds = null,
    ): array {
        $messages = [];
        foreach ($bodies as $i => $body) {
            $messages[] = $this->send(
                $queueName,
                $body,
                headers: $headers,
                ttlSeconds: $ttlSeconds,
            );
        }
        return $messages;
    }

    public function sendWithContentType(
        string $queueName,
        string $body,
        string $contentType,
        ?string $messageId = null,
        ?int $ttlSeconds = null,
    ): Message {
        return $this->send(
            $queueName,
            $body,
            $messageId,
            headers: ['content_type' => $contentType],
            ttlSeconds: $ttlSeconds,
        );
    }
}
