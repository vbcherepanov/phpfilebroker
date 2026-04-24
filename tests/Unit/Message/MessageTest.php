<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Message;

use FileBroker\Message\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Message::class)]
final class MessageTest extends TestCase
{
    private string $testDir;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/file-broker-test-' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function test_create_returns_immutable_message(): void
    {
        $body = json_encode(['test' => 'data']);
        $message = Message::create(
            body: $body,
            id: 'test-msg-001',
            headers: ['content_type' => 'application/json'],
            correlationId: 'corr-123',
            replyTo: 'responses',
        );

        self::assertSame('test-msg-001', $message->id);
        self::assertSame($body, $message->body);
        self::assertSame('application/json', $message->headers['content_type']);
        self::assertSame('corr-123', $message->correlationId);
        self::assertSame('responses', $message->replyTo);
        self::assertSame(0, $message->deliveryCount);
        self::assertInstanceOf(\DateTimeImmutable::class, $message->createdAt);
        self::assertNull($message->expiresAt);
    }

    public function test_create_with_ttl_sets_expires_at(): void
    {
        $message = Message::create(body: 'test', ttlSeconds: 3600);
        self::assertNotNull($message->expiresAt);

        $expected = (new \DateTimeImmutable())->add(
            \DateInterval::createFromDateString('3600 seconds'),
        );
        $diff = $message->expiresAt->getTimestamp() - $expected->getTimestamp();
        self::assertLessThan(2, abs($diff));
    }

    public function test_create_without_id_generates_uuid(): void
    {
        $message = Message::create(body: 'test');
        self::assertNotSame('', $message->id);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4-[0-9a-f][0-3]-[0-9a-f]{6}$/',
            $message->id,
        );
    }

    public function test_is_expired_returns_false_when_no_ttl(): void
    {
        $message = Message::create(body: 'test');
        self::assertFalse($message->isExpired());
    }

    public function test_is_expired_returns_true_when_expired(): void
    {
        $message = Message::create(body: 'test', ttlSeconds: -10);
        self::assertTrue($message->isExpired());
    }

    public function test_increment_delivery_count(): void
    {
        $message = Message::create(body: 'test');
        $incremented = $message->incrementDeliveryCount();

        self::assertSame(0, $message->deliveryCount);
        self::assertSame(1, $incremented->deliveryCount);

        $double = $incremented->incrementDeliveryCount();
        self::assertSame(1, $incremented->deliveryCount);
        self::assertSame(2, $double->deliveryCount);
    }

    public function test_with_body_returns_new_message(): void
    {
        $message = Message::create(body: 'original');
        $updated = $message->withBody('updated');

        self::assertSame('original', $message->body);
        self::assertSame('updated', $updated->body);
        self::assertSame($message->id, $updated->id);
    }

    public function test_with_headers_returns_new_message(): void
    {
        $message = Message::create(body: 'test', headers: ['a' => '1']);
        $updated = $message->withHeaders(['b' => '2']);

        self::assertSame(['a' => '1'], $message->headers);
        self::assertSame(['b' => '2'], $updated->headers);
    }

    public function test_json_serialize_and_deserialize(): void
    {
        $original = Message::create(
            body: 'test-data',
            id: 'test-msg-002',
            headers: ['key' => 'value'],
            correlationId: 'corr-456',
            replyTo: 'reply-queue',
            ttlSeconds: 7200,
            priority: 10,
            key: 'dedup-key-001',
        );

        $serialized = $original->jsonSerialize();
        $deserialized = Message::fromArray($serialized);

        self::assertSame($original->id, $deserialized->id);
        self::assertSame($original->body, $deserialized->body);
        self::assertSame($original->headers, $deserialized->headers);
        self::assertSame($original->correlationId, $deserialized->correlationId);
        self::assertSame($original->replyTo, $deserialized->replyTo);
        self::assertSame($original->deliveryCount, $deserialized->deliveryCount);
        self::assertSame(10, $deserialized->priority);
        self::assertSame('dedup-key-001', $deserialized->key);
    }

    public function test_from_array_throws_on_missing_id(): void
    {
        self::expectException(\InvalidArgumentException::class);
        self::expectExceptionMessage('Message id is required');
        Message::fromArray(['body' => 'test']);
    }

    public function test_from_array_throws_on_missing_body(): void
    {
        self::expectException(\InvalidArgumentException::class);
        self::expectExceptionMessage('Message body is required');
        Message::fromArray(['id' => 'test']);
    }

    public function test_to_string_returns_id(): void
    {
        $message = Message::create(body: 'test', id: 'test-id-123');
        self::assertSame('test-id-123', (string) $message);
    }

    /**
     * @return list<array{int|null}>
     */
    public static function ttlDataProvider(): array
    {
        return [
            [null],
            [1],
            [60],
            [3600],
            [86400],
        ];
    }

    #[DataProvider('ttlDataProvider')]
    public function test_create_with_various_ttls(?int $ttl): void
    {
        $message = Message::create(body: 'test', ttlSeconds: $ttl);

        if ($ttl === null) {
            self::assertNull($message->expiresAt);
        } else {
            self::assertNotNull($message->expiresAt);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
