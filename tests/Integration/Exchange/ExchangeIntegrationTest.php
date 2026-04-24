<?php

declare(strict_types=1);

namespace FileBroker\Tests\Integration\Exchange;

use FileBroker\Broker\MessageBroker;
use FileBroker\Config\BrokerConfig;
use FileBroker\Config\QueueConfig;
use FileBroker\Exchange\Binding;
use FileBroker\Exchange\ExchangeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageBroker::class)]
final class ExchangeIntegrationTest extends TestCase
{
    private string $testDir;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/exchange-integration-' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function test_publish_routes_to_correct_queues(): void
    {
        $config = BrokerConfig::default();
        $config = $config->withQueue(new QueueConfig(
            name: 'orders',
            basePath: $this->testDir . '/orders',
            enableDeadLetter: false,
        ));
        $config = $config->withQueue(new QueueConfig(
            name: 'payments',
            basePath: $this->testDir . '/payments',
            enableDeadLetter: false,
        ));
        $config = $config->withQueue(new QueueConfig(
            name: 'emails',
            basePath: $this->testDir . '/emails',
            enableDeadLetter: false,
        ));

        $broker = new MessageBroker($config);
        $broker->ensureInitialized();

        // Create exchange with bindings
        $registry = $broker->getExchangeRegistry();
        $registry->create('order-events', ExchangeType::Topic);
        $registry->bind('order-events', new Binding(queueName: 'orders', routingKey: 'order.created'));
        $registry->bind('order-events', new Binding(queueName: 'payments', routingKey: 'order.paid'));
        $registry->bind('order-events', new Binding(queueName: 'emails', routingKey: 'order.#'));

        // Publish: should route to orders (exact) and emails (wildcard)
        $ids = $broker->publish('order-events', 'order.created', json_encode(['order_id' => 1]));

        self::assertCount(2, $ids);

        // Verify messages in correct queues
        $orderMsg = $broker->consume('orders');
        self::assertNotNull($orderMsg);
        $body = json_decode($orderMsg->body, true);
        self::assertIsArray($body);
        self::assertSame(1, $body['order_id']);

        $emailMsg = $broker->consume('emails');
        self::assertNotNull($emailMsg);

        $paymentMsg = $broker->consume('payments');
        self::assertNull($paymentMsg, 'Payment queue should be empty — routing key did not match');

        // Publish to payment-matching key
        $ids2 = $broker->publish('order-events', 'order.paid', json_encode(['order_id' => 2]));
        self::assertCount(2, $ids2); // payments + emails

        $paymentMsg2 = $broker->consume('payments');
        self::assertNotNull($paymentMsg2);
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
