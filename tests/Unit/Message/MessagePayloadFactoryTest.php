<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Message;

use FileBroker\Message\Message;
use FileBroker\Message\MessagePayloadFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessagePayloadFactory::class)]
final class MessagePayloadFactoryTest extends TestCase
{
    private MessagePayloadFactory $factory;

    public function setUp(): void
    {
        $this->factory = new MessagePayloadFactory();
    }

    public function test_from_json_valid(): void
    {
        $message = Message::create(
            body: 'test-body',
            id: 'factory-test-001',
            headers: ['key' => 'value'],
            correlationId: 'corr-123',
            replyTo: 'reply-queue',
            ttlSeconds: 3600,
        );

        $json = $this->factory->toJson($message);
        $deserialized = $this->factory->fromJson($json);

        self::assertSame($message->id, $deserialized->id);
        self::assertSame($message->body, $deserialized->body);
        self::assertSame($message->headers, $deserialized->headers);
        self::assertSame($message->correlationId, $deserialized->correlationId);
        self::assertSame($message->replyTo, $deserialized->replyTo);
        self::assertSame($message->deliveryCount, $deserialized->deliveryCount);
        self::assertNotNull($deserialized->expiresAt);
    }

    public function test_from_json_with_null_expires(): void
    {
        $message = Message::create(
            body: 'test-body',
            id: 'factory-test-002',
        );

        $json = $this->factory->toJson($message);
        $deserialized = $this->factory->fromJson($json);

        self::assertNull($deserialized->expiresAt);
    }

    public function test_from_json_with_empty_headers(): void
    {
        $message = Message::create(body: 'test', id: 'factory-test-003');
        $json = $this->factory->toJson($message);
        $deserialized = $this->factory->fromJson($json);

        self::assertSame([], $deserialized->headers);
    }

    public function test_from_json_with_unicode_body(): void
    {
        $unicodeBody = "Привет мир! 你好世界! مرحبا بالعالم! 🎉";
        $message = Message::create(body: $unicodeBody, id: 'factory-test-004');
        $json = $this->factory->toJson($message);
        $deserialized = $this->factory->fromJson($json);

        self::assertSame($unicodeBody, $deserialized->body);
    }

    public function test_from_json_throws_on_invalid_json(): void
    {
        self::expectException(\JsonException::class);
        $this->factory->fromJson('not valid json');
    }

    public function test_from_json_throws_on_array_payload(): void
    {
        self::expectException(\InvalidArgumentException::class);
        $this->factory->fromJson('[1, 2, 3]');
    }

    public function test_to_json_is_pretty_printed(): void
    {
        $message = Message::create(body: 'test', id: 'factory-test-005');
        $json = $this->factory->toJson($message);

        self::assertStringContainsString("\n", $json);
        self::assertStringContainsString("  ", $json);
    }

    public function test_to_json_is_valid_json(): void
    {
        $message = Message::create(body: 'test', id: 'factory-test-006');
        $json = $this->factory->toJson($message);

        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('id', $decoded);
        self::assertArrayHasKey('body', $decoded);
    }
}
