<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Stream;

use FileBroker\Stream\ConsumerOffset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsumerOffset::class)]
final class ConsumerOffsetTest extends TestCase
{
    public function test_create_and_serialize(): void
    {
        $offset = ConsumerOffset::create(
            consumerGroup: 'group-a',
            queueName: 'orders',
            offset: 5,
        );

        self::assertSame('group-a', $offset->consumerGroup);
        self::assertSame('orders', $offset->queueName);
        self::assertSame(5, $offset->offset);
        self::assertInstanceOf(\DateTimeImmutable::class, $offset->updatedAt);

        $serialized = $offset->jsonSerialize();

        self::assertSame('group-a', $serialized['consumer_group']);
        self::assertSame('orders', $serialized['queue_name']);
        self::assertSame(5, $serialized['offset']);
        self::assertIsString($serialized['updated_at']);
    }

    public function test_from_array_deserializes(): void
    {
        $now = new \DateTimeImmutable();
        $data = [
            'consumer_group' => 'group-b',
            'queue_name' => 'events',
            'offset' => 10,
            'updated_at' => $now->format(\DateTimeImmutable::ATOM),
        ];

        $offset = ConsumerOffset::fromArray($data);

        self::assertSame('group-b', $offset->consumerGroup);
        self::assertSame('events', $offset->queueName);
        self::assertSame(10, $offset->offset);
        self::assertSame($now->getTimestamp(), $offset->updatedAt->getTimestamp());
    }
}
