<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Message;

use FileBroker\Message\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Message::class)]
final class MessagePriorityTest extends TestCase
{
    public function test_message_has_default_priority_zero(): void
    {
        $message = Message::create(body: 'test');

        self::assertSame(0, $message->priority);
    }

    public function test_with_priority_returns_new_instance_with_priority(): void
    {
        $message = Message::create(body: 'test');
        $updated = $message->withPriority(255);

        self::assertSame(0, $message->priority, 'Original must be unchanged');
        self::assertSame(255, $updated->priority);
        self::assertSame($message->id, $updated->id);
        self::assertSame($message->body, $updated->body);
    }

    public function test_message_with_key_supports_compaction(): void
    {
        $message = Message::create(
            body: json_encode(['user_id' => 42]),
            key: 'user:42',
        );

        self::assertSame('user:42', $message->key);

        $updated = $message->withKey('user:43');
        self::assertSame('user:43', $updated->key);
        self::assertSame('user:42', $message->key, 'Original key must be unchanged');

        // Key can be nulled
        $noKey = $updated->withKey(null);
        self::assertNull($noKey->key);
    }
}
