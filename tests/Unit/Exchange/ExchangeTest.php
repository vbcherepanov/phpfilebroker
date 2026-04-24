<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Exchange;

use FileBroker\Exchange\Binding;
use FileBroker\Exchange\Exchange;
use FileBroker\Exchange\ExchangeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Exchange::class)]
final class ExchangeTest extends TestCase
{
    public function test_direct_exchange_routes_exact_match(): void
    {
        $exchange = new Exchange(
            name: 'orders',
            type: ExchangeType::Direct,
            bindings: [
                new Binding(queueName: 'orders-queue', routingKey: 'orders'),
                new Binding(queueName: 'payments-queue', routingKey: 'payments'),
            ],
        );

        $queues = $exchange->route('orders');

        self::assertSame(['orders-queue'], $queues);
    }

    public function test_direct_exchange_ignores_non_matching(): void
    {
        $exchange = new Exchange(
            name: 'orders',
            type: ExchangeType::Direct,
            bindings: [
                new Binding(queueName: 'orders-queue', routingKey: 'orders'),
            ],
        );

        $queues = $exchange->route('nonexistent');

        self::assertSame([], $queues);
    }

    public function test_topic_exchange_wildcard_single_word(): void
    {
        $exchange = new Exchange(
            name: 'events',
            type: ExchangeType::Topic,
            bindings: [
                new Binding(queueName: 'order-events', routingKey: 'orders.*'),
            ],
        );

        self::assertSame(['order-events'], $exchange->route('orders.created'));
        self::assertSame(['order-events'], $exchange->route('orders.paid'));
        self::assertSame([], $exchange->route('orders.created.success'));
    }

    public function test_topic_exchange_wildcard_multi_word(): void
    {
        $exchange = new Exchange(
            name: 'events',
            type: ExchangeType::Topic,
            bindings: [
                new Binding(queueName: 'all-order-events', routingKey: 'orders.#'),
                new Binding(queueName: 'specific', routingKey: '*.created'),
            ],
        );

        // orders.# matches all sub-keys
        self::assertContains('all-order-events', $exchange->route('orders.created'));
        self::assertContains('all-order-events', $exchange->route('orders.created.success'));
        self::assertContains('all-order-events', $exchange->route('orders.created.success.email'));

        // orders.# also matches just "orders" (zero words after #)
        self::assertContains('all-order-events', $exchange->route('orders'));

        // *.created matches exactly one word before .created
        self::assertContains('specific', $exchange->route('orders.created'));
        self::assertContains('specific', $exchange->route('users.created'));
        self::assertNotContains('specific', $exchange->route('orders.created.success'));
    }

    public function test_topic_exchange_exact_pattern(): void
    {
        $exchange = new Exchange(
            name: 'events',
            type: ExchangeType::Topic,
            bindings: [
                new Binding(queueName: 'error-log', routingKey: '*.*.error'),
            ],
        );

        self::assertSame(['error-log'], $exchange->route('orders.created.error'));
        self::assertSame(['error-log'], $exchange->route('payments.processed.error'));
        self::assertSame([], $exchange->route('orders.error'));
        self::assertSame([], $exchange->route('error'));
    }

    public function test_topic_exchange_hash_at_beginning(): void
    {
        $exchange = new Exchange(
            name: 'events',
            type: ExchangeType::Topic,
            bindings: [
                new Binding(queueName: 'created-events', routingKey: '#.created'),
            ],
        );

        self::assertContains('created-events', $exchange->route('orders.created'));
        self::assertContains('created-events', $exchange->route('orders.payments.created'));
        self::assertContains('created-events', $exchange->route('created'));
    }

    public function test_fanout_exchange_routes_to_all(): void
    {
        $exchange = new Exchange(
            name: 'broadcast',
            type: ExchangeType::Fanout,
            bindings: [
                new Binding(queueName: 'queue-a', routingKey: 'ignored'),
                new Binding(queueName: 'queue-b', routingKey: 'also-ignored'),
                new Binding(queueName: 'queue-c', routingKey: ''),
            ],
        );

        $queues = $exchange->route('anything');

        self::assertSame(['queue-a', 'queue-b', 'queue-c'], $queues);
    }

    public function test_headers_exchange_match_all_succeeds(): void
    {
        $exchange = new Exchange(
            name: 'header-ex',
            type: ExchangeType::Headers,
            bindings: [
                new Binding(
                    queueName: 'matched-queue',
                    routingKey: '',
                    headerMatch: ['content_type' => 'application/json', 'version' => 'v2'],
                    xmatch: 'all',
                ),
            ],
        );

        $queues = $exchange->route('', [
            'content_type' => 'application/json',
            'version' => 'v2',
            'extra' => 'ignored',
        ]);

        self::assertSame(['matched-queue'], $queues);
    }

    public function test_headers_exchange_match_all_fails(): void
    {
        $exchange = new Exchange(
            name: 'header-ex',
            type: ExchangeType::Headers,
            bindings: [
                new Binding(
                    queueName: 'matched-queue',
                    routingKey: '',
                    headerMatch: ['content_type' => 'application/json', 'version' => 'v2'],
                    xmatch: 'all',
                ),
            ],
        );

        // Missing version header
        $queues = $exchange->route('', [
            'content_type' => 'application/json',
        ]);

        self::assertSame([], $queues);
    }

    public function test_headers_exchange_match_any_succeeds(): void
    {
        $exchange = new Exchange(
            name: 'header-ex',
            type: ExchangeType::Headers,
            bindings: [
                new Binding(
                    queueName: 'any-match',
                    routingKey: '',
                    headerMatch: ['content_type' => 'application/json', 'version' => 'v2'],
                    xmatch: 'any',
                ),
            ],
        );

        // Only one header matches
        $queues = $exchange->route('', [
            'content_type' => 'application/json',
            'version' => 'v1',
        ]);

        self::assertSame(['any-match'], $queues);
    }

    public function test_headers_exchange_match_any_fails(): void
    {
        $exchange = new Exchange(
            name: 'header-ex',
            type: ExchangeType::Headers,
            bindings: [
                new Binding(
                    queueName: 'any-match',
                    routingKey: '',
                    headerMatch: ['content_type' => 'application/json', 'version' => 'v2'],
                    xmatch: 'any',
                ),
            ],
        );

        $queues = $exchange->route('', [
            'other_header' => 'value',
        ]);

        self::assertSame([], $queues);
    }

    public function test_headers_exchange_no_header_match_returns_empty(): void
    {
        $exchange = new Exchange(
            name: 'header-ex',
            type: ExchangeType::Headers,
            bindings: [
                new Binding(
                    queueName: 'empty-match',
                    routingKey: '',
                    headerMatch: [],
                    xmatch: 'any',
                ),
            ],
        );

        $queues = $exchange->route('', ['content_type' => 'application/json']);

        self::assertSame([], $queues);
    }

    public function test_with_binding(): void
    {
        $exchange = new Exchange(name: 'ex', type: ExchangeType::Direct);

        $updated = $exchange->withBinding(new Binding(queueName: 'q1', routingKey: 'k1'));

        self::assertCount(0, $exchange->bindings);
        self::assertCount(1, $updated->bindings);
        self::assertSame('q1', $updated->bindings[0]->queueName);
    }

    public function test_without_binding(): void
    {
        $exchange = new Exchange(
            name: 'ex',
            type: ExchangeType::Fanout,
            bindings: [
                new Binding(queueName: 'q1'),
                new Binding(queueName: 'q2'),
                new Binding(queueName: 'q1'), // duplicate queue
            ],
        );

        $updated = $exchange->withoutBinding('q1');

        self::assertCount(3, $exchange->bindings);
        self::assertCount(1, $updated->bindings);
        self::assertSame('q2', $updated->bindings[0]->queueName);
    }

    public function test_to_array_and_from_array(): void
    {
        $exchange = new Exchange(
            name: 'orders',
            type: ExchangeType::Topic,
            bindings: [
                new Binding(
                    queueName: 'order-queue',
                    routingKey: 'orders.#',
                    headerMatch: [],
                    xmatch: null,
                ),
            ],
        );

        $array = $exchange->toArray();
        $restored = Exchange::fromArray($array);

        self::assertSame($exchange->name, $restored->name);
        self::assertSame($exchange->type, $restored->type);
        self::assertCount(1, $restored->bindings);
        self::assertSame('order-queue', $restored->bindings[0]->queueName);
        self::assertSame('orders.#', $restored->bindings[0]->routingKey);
    }

    public function test_from_array_throws_on_missing_name(): void
    {
        self::expectException(\InvalidArgumentException::class);
        Exchange::fromArray(['type' => 'direct']);
    }

    public function test_from_array_throws_on_missing_type(): void
    {
        self::expectException(\InvalidArgumentException::class);
        Exchange::fromArray(['name' => 'ex']);
    }
}
