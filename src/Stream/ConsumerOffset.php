<?php

declare(strict_types=1);

namespace FileBroker\Stream;

/**
 * Readonly DTO tracking the last acknowledged offset for a consumer group.
 */
final class ConsumerOffset implements \JsonSerializable
{
    public function __construct(
        public readonly string $consumerGroup,
        public readonly string $queueName,
        public readonly int $offset,
        public readonly \DateTimeImmutable $updatedAt,
    ) {}

    public static function create(string $consumerGroup, string $queueName, int $offset = 0): self
    {
        return new self(
            consumerGroup: $consumerGroup,
            queueName: $queueName,
            offset: $offset,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * @param array{consumer_group: string, queue_name: string, offset: int, updated_at: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            consumerGroup: $data['consumer_group'],
            queueName: $data['queue_name'],
            offset: (int) $data['offset'],
            updatedAt: new \DateTimeImmutable($data['updated_at']),
        );
    }

    /**
     * @return array{consumer_group: string, queue_name: string, offset: int, updated_at: string}
     */
    public function toArray(): array
    {
        return [
            'consumer_group' => $this->consumerGroup,
            'queue_name' => $this->queueName,
            'offset' => $this->offset,
            'updated_at' => $this->updatedAt->format(\DateTimeImmutable::ATOM),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
