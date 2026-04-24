<?php

declare(strict_types=1);

namespace FileBroker\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\Logging\Logger;
use FileBroker\Message\Message;

final class ProduceCommand
{
    public function __construct(
        private readonly MessageBroker $broker,
        private readonly Logger $logger,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args): Message
    {
        $queueName = $args['queue'] ?? throw new \InvalidArgumentException('Queue name is required');
        $body = $args['body'] ?? throw new \InvalidArgumentException('Message body is required');

        $headers = [];
        if (isset($args['headers']) && \is_string($args['headers'])) {
            $decoded = json_decode($args['headers'], true, 512, \JSON_THROW_ON_ERROR);
            if (\is_array($decoded)) {
                $headers = $decoded;
            }
        }

        $ttl = isset($args['ttl']) ? (int) $args['ttl'] : null;
        $messageId = isset($args['id']) ? (string) $args['id'] : null;

        $message = $this->broker->produce(
            queueName: $queueName,
            body: $body,
            messageId: $messageId,
            headers: $headers,
            ttl: $ttl,
        );

        $this->logger->info('Message produced', [
            'id' => $message->id,
            'queue' => $queueName,
            'body' => mb_strcut($body, 0, 200),
        ]);

        return $message;
    }
}
