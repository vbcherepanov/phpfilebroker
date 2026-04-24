<?php

declare(strict_types=1);

namespace FileBroker\Message;

/**
 * Factory for creating messages from serialized payloads.
 * Provides a type-safe way to deserialize messages from files.
 */
final class MessagePayloadFactory
{
    /**
     * @phpstan-type MessagePayload array{
     *   id: string,
     *   body: string,
     *   headers: array<string, string>,
     *   created_at: string,
     *   expires_at?: string|null,
     *   delivery_count: int,
     *   correlation_id?: string|null,
     *   reply_to?: string|null,
     *   priority?: int,
     *   key?: string|null
     * }
     */
    public function __construct() {}

    /**
     * Deserialize a JSON payload into a Message.
     *
     * @param string $json JSON-encoded message payload
     * @throws \JsonException on invalid JSON
     * @throws \InvalidArgumentException on missing required fields
     */
    public function fromJson(string $json): Message
    {
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new \InvalidArgumentException('Message payload must be a JSON object');
        }

        return Message::fromArray($data);
    }

    /**
     * Serialize a Message into a JSON payload.
     *
     * @throws \JsonException on serialization failure
     */
    public function toJson(Message $message): string
    {
        return json_encode($message, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);
    }
}
