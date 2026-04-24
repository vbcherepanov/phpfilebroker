<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Config;

use FileBroker\Config\BrokerConfig;
use FileBroker\Config\QueueConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrokerConfig::class)]
final class BrokerConfigTest extends TestCase
{
    public function test_default(): void
    {
        $config = BrokerConfig::default();

        self::assertStringContainsString('file-broker', $config->storagePath);
        self::assertSame([], $config->queues);
        self::assertNull($config->defaultQueue);
        self::assertSame(30, $config->lockTimeout);
        self::assertSame(1, $config->pollInterval);
        self::assertSame(4, $config->maxWorkers);
    }

    public function test_from_array(): void
    {
        $data = [
            'storage_path' => '/tmp/test-broker',
            'default_queue' => 'orders',
            'lock_timeout' => 60,
            'poll_interval' => 2,
            'max_workers' => 8,
            'queues' => [
                'orders' => [
                    'name' => 'orders',
                    'base_path' => '/tmp/orders',
                ],
                'emails' => [
                    'name' => 'emails',
                    'base_path' => '/tmp/emails',
                    'default_ttl' => 3600,
                ],
            ],
        ];

        $config = BrokerConfig::fromArray($data);

        self::assertSame('/tmp/test-broker', $config->storagePath);
        self::assertSame('orders', $config->defaultQueue);
        self::assertSame(60, $config->lockTimeout);
        self::assertSame(2, $config->pollInterval);
        self::assertSame(8, $config->maxWorkers);
        self::assertCount(2, $config->queues);
        self::assertInstanceOf(QueueConfig::class, $config->queues['orders']);
        self::assertSame(3600, $config->queues['emails']->defaultTtlSeconds);
    }

    public function test_from_file(): void
    {
        $testDir = sys_get_temp_dir() . '/file-broker-config-test-' . bin2hex(random_bytes(4));
        mkdir($testDir, 0755, true);

        try {
            $configPath = $testDir . '/broker.json';
            $content = json_encode([
                'storage_path' => $testDir . '/storage',
                'default_queue' => 'default',
                'queues' => [
                    'default' => ['name' => 'default', 'base_path' => $testDir . '/storage'],
                ],
            ]);

            file_put_contents($configPath, $content);

            $config = BrokerConfig::fromFile($configPath);

            self::assertSame($testDir . '/storage', $config->storagePath);
            self::assertSame('default', $config->defaultQueue);
        } finally {
            $this->removeDir($testDir);
        }
    }

    public function test_from_file_throws_on_missing_file(): void
    {
        self::expectException(\RuntimeException::class);
        BrokerConfig::fromFile('/nonexistent/path/broker.json');
    }

    public function test_with_queue(): void
    {
        $config = BrokerConfig::default();
        $queue = new QueueConfig(name: 'new-queue', basePath: '/tmp/new-queue');

        $updated = $config->withQueue($queue);

        self::assertCount(0, $config->queues);
        self::assertCount(1, $updated->queues);
        self::assertSame('new-queue', $updated->queues['new-queue']->name);
    }

    public function test_to_array(): void
    {
        $config = BrokerConfig::default();
        $array = $config->toArray();

        self::assertArrayHasKey('storage_path', $array);
        self::assertArrayHasKey('queues', $array);
        self::assertArrayHasKey('default_queue', $array);
        self::assertArrayHasKey('lock_timeout', $array);
        self::assertArrayHasKey('poll_interval', $array);
        self::assertArrayHasKey('max_workers', $array);
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
