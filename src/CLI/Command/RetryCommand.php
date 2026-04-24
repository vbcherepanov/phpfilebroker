<?php

declare(strict_types=1);

namespace FileBroker\CLI\Command;

use FileBroker\Broker\MessageBroker;
use FileBroker\Logging\Logger;

final class RetryCommand
{
    public function __construct(
        private readonly MessageBroker $broker,
        private readonly Logger $logger,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args): void
    {
        $queueName = $args['queue'] ?? throw new \InvalidArgumentException('Queue name is required');
        $messageId = $args['id'] ?? throw new \InvalidArgumentException('Message ID is required');

        $config = $this->broker->getConfig();
        $queueConfig = $config->queues[$queueName]
            ?? throw new \RuntimeException(\sprintf('Queue "%s" not found', $queueName));

        $retryPath = $queueConfig->retryPath();
        $dlqPath = $queueConfig->deadLetterPath();
        $filePath = null;

        if (is_dir($retryPath)) {
            foreach (scandir($retryPath) ?: [] as $file) {
                if (str_starts_with($file, $messageId . '_')) {
                    $filePath = $retryPath . '/' . $file;
                    break;
                }
            }
        }

        if ($filePath === null && is_dir($dlqPath)) {
            foreach (scandir($dlqPath) ?: [] as $file) {
                if (str_starts_with($file, $messageId . '_')) {
                    $filePath = $dlqPath . '/' . $file;
                    break;
                }
            }
        }

        if ($filePath === null || !file_exists($filePath)) {
            $this->logger->error("Message '{$messageId}' not found in retry or DLQ", [
                'queue' => $queueName,
                'message_id' => $messageId,
            ]);
            return;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            $this->logger->error('Cannot read message file', [
                'queue' => $queueName,
                'message_id' => $messageId,
                'file' => $filePath,
            ]);
            return;
        }

        $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        $message = \FileBroker\Message\Message::fromArray($data);

        $this->broker->produce(
            queueName: $queueName,
            body: $message->body,
            messageId: $message->id,
            headers: $message->headers,
            ttl: $message->expiresAt !== null
                ? (int) max(0, $message->expiresAt->getTimestamp() - (new \DateTimeImmutable())->getTimestamp())
                : null,
        );

        @unlink($filePath);
        $this->logger->info("Message '{$messageId}' has been retried", [
            'queue' => $queueName,
            'message_id' => $messageId,
        ]);
    }
}
