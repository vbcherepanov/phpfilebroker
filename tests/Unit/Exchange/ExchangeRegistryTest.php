<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Exchange;

use FileBroker\Exchange\Binding;
use FileBroker\Exchange\ExchangeRegistry;
use FileBroker\Exchange\ExchangeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExchangeRegistry::class)]
final class ExchangeRegistryTest extends TestCase
{
    private string $testDir;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/exchange-registry-test-' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function test_create_and_get_exchange(): void
    {
        $registry = new ExchangeRegistry($this->testDir);

        $created = $registry->create('orders', ExchangeType::Topic);

        self::assertSame('orders', $created->name);
        self::assertSame(ExchangeType::Topic, $created->type);
        self::assertCount(0, $created->bindings);

        $retrieved = $registry->get('orders');
        self::assertNotNull($retrieved);
        self::assertSame('orders', $retrieved->name);
        self::assertSame(ExchangeType::Topic, $retrieved->type);
    }

    public function test_list_returns_created_exchanges(): void
    {
        $registry = new ExchangeRegistry($this->testDir);

        $registry->create('orders', ExchangeType::Direct);
        $registry->create('events', ExchangeType::Topic);
        $registry->create('broadcast', ExchangeType::Fanout);

        $names = $registry->list();

        self::assertCount(3, $names);
        self::assertContains('broadcast', $names);
        self::assertContains('events', $names);
        self::assertContains('orders', $names);
    }

    public function test_delete_removes_exchange(): void
    {
        $registry = new ExchangeRegistry($this->testDir);

        $registry->create('temp', ExchangeType::Direct);
        self::assertNotNull($registry->get('temp'));

        $registry->delete('temp');
        self::assertNull($registry->get('temp'));
    }

    public function test_get_nonexistent_returns_null(): void
    {
        $registry = new ExchangeRegistry($this->testDir);

        self::assertNull($registry->get('nonexistent'));
    }

    public function test_bind_and_unbind(): void
    {
        $registry = new ExchangeRegistry($this->testDir);

        $registry->create('orders', ExchangeType::Topic);
        $registry->bind('orders', new Binding(queueName: 'order-queue', routingKey: 'orders.#'));

        $exchange = $registry->get('orders');
        self::assertNotNull($exchange);
        self::assertCount(1, $exchange->bindings);
        self::assertSame('order-queue', $exchange->bindings[0]->queueName);

        $registry->unbind('orders', 'order-queue');

        $exchange = $registry->get('orders');
        self::assertNotNull($exchange);
        self::assertCount(0, $exchange->bindings);
    }

    public function test_bind_nonexistent_exchange_throws(): void
    {
        $registry = new ExchangeRegistry($this->testDir);

        self::expectException(\RuntimeException::class);
        $registry->bind('nonexistent', new Binding(queueName: 'q'));
    }

    public function test_unbind_nonexistent_exchange_throws(): void
    {
        $registry = new ExchangeRegistry($this->testDir);

        self::expectException(\RuntimeException::class);
        $registry->unbind('nonexistent', 'q');
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
