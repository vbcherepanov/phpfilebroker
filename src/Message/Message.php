<?php

declare(strict_types=1);

namespace FileBroker\Message;

use JsonSerializable;
use Stringable;

/**
 * Immutable message envelope for the file-based broker.
 */
final class Message implements JsonSerializable, Stringable
{
    private function __construct(
        public readonly string $id,
        public readonly string $body,
        public readonly array $headers,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly int $deliveryCount,
        public readonly ?string $correlationId,
        public readonly ?string $replyTo,
        public readonly int $priority = 0,
        public readonly ?string $key = null,
    ) {}

    public function __toString(): string
    {
        return $this->id;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        return new \DateTimeImmutable() > $this->expiresAt;
    }

    public function withBody(string $body): self
    {
        return new self(
            $this->id,
            $body,
            $this->headers,
            $this->createdAt,
            $this->expiresAt,
            $this->deliveryCount,
            $this->correlationId,
            $this->replyTo,
            $this->priority,
            $this->key,
        );
    }

    public function withHeaders(array $headers): self
    {
        return new self(
            $this->id,
            $this->body,
            $headers,
            $this->createdAt,
            $this->expiresAt,
            $this->deliveryCount,
            $this->correlationId,
            $this->replyTo,
            $this->priority,
            $this->key,
        );
    }

    public function incrementDeliveryCount(): self
    {
        return new self(
            $this->id,
            $this->body,
            $this->headers,
            $this->createdAt,
            $this->expiresAt,
            $this->deliveryCount + 1,
            $this->correlationId,
            $this->replyTo,
            $this->priority,
            $this->key,
        );
    }

    public function withPriority(int $priority): self
    {
        return new self(
            $this->id,
            $this->body,
            $this->headers,
            $this->createdAt,
            $this->expiresAt,
            $this->deliveryCount,
            $this->correlationId,
            $this->replyTo,
            $priority,
            $this->key,
        );
    }

    public function withKey(?string $key): self
    {
        return new self(
            $this->id,
            $this->body,
            $this->headers,
            $this->createdAt,
            $this->expiresAt,
            $this->deliveryCount,
            $this->correlationId,
            $this->replyTo,
            $this->priority,
            $key,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'headers' => $this->headers,
            'created_at' => $this->createdAt->format(\DateTimeImmutable::ATOM),
            'expires_at' => $this->expiresAt?->format(\DateTimeImmutable::ATOM),
            'delivery_count' => $this->deliveryCount,
            'correlation_id' => $this->correlationId,
            'reply_to' => $this->replyTo,
            'priority' => $this->priority,
            'key' => $this->key,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? throw new \InvalidArgumentException('Message id is required'),
            body: $data['body'] ?? throw new \InvalidArgumentException('Message body is required'),
            headers: $data['headers'] ?? [],
            createdAt: new \DateTimeImmutable($data['created_at'] ?? date(\DateTimeImmutable::ATOM)),
            expiresAt: isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
            deliveryCount: (int) ($data['delivery_count'] ?? 0),
            correlationId: $data['correlation_id'] ?? null,
            replyTo: $data['reply_to'] ?? null,
            priority: (int) ($data['priority'] ?? 0),
            key: $data['key'] ?? null,
        );
    }

    public static function create(
        string $body,
        ?string $id = null,
        array $headers = [],
        ?int $ttlSeconds = null,
        ?string $correlationId = null,
        ?string $replyTo = null,
        int $priority = 0,
        ?string $key = null,
    ): self {
        $expiresAt = null;
        if ($ttlSeconds !== null) {
            $interval = \DateInterval::createFromDateString((int) $ttlSeconds . ' seconds');
            $expiresAt = (new \DateTimeImmutable())->add($interval !== false ? $interval : new \DateInterval('PT0S'));
        }

        return new self(
            id: $id ?? self::generateId(),
            body: $body,
            headers: $headers,
            createdAt: new \DateTimeImmutable(),
            expiresAt: $expiresAt,
            deliveryCount: 0,
            correlationId: $correlationId,
            replyTo: $replyTo,
            priority: $priority,
            key: $key,
        );
    }

    private static function generateId(): string
    {
        return \sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            4,
            bin2hex(random_bytes(2))[0] . dechex(0x3 & hexdec(bin2hex(random_bytes(2))[0])),
            bin2hex(random_bytes(3)),
        );
    }
}
