<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\CLI;

use FileBroker\Broker\MessageBroker;
use FileBroker\CLI\Console;
use FileBroker\Config\BrokerConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Console::class)]
final class ConsoleTest extends TestCase
{
    private string $testDir;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/file-broker-console-test-' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function test_run_with_no_args_shows_help(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        ob_start();
        $result = $console->run(['file-broker', 'help']);
        ob_end_clean();

        self::assertSame(0, $result);
    }

    public function test_run_with_unknown_command_shows_help(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        ob_start();
        $result = $console->run(['file-broker', 'nonexistent']);
        ob_end_clean();

        self::assertSame(0, $result);
    }

    public function test_run_produce_command(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => ['test-queue' => ['name' => 'test-queue', 'path' => $this->testDir]],
        ]);

        $broker = new MessageBroker($config);

        $console = new Console($broker);

        ob_start();
        $result = $console->run([
            'file-broker',
            'produce',
            'test-queue',
            '{"a":1}',
        ]);
        ob_end_clean();

        self::assertSame(0, $result);
    }

    public function test_run_help_command(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        ob_start();
        $result = $console->run(['file-broker', 'help']);
        ob_end_clean();

        self::assertSame(0, $result);
    }

    public function test_run_consume_command_validates_queue(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        $result = $console->run(['file-broker', 'consume']);

        self::assertSame(1, $result);
    }

    public function test_run_purge_command_validates_queue(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        $result = $console->run(['file-broker', 'purge']);
        self::assertSame(1, $result);
    }

    public function test_run_create_queue_validates_name(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        $result = $console->run(['file-broker', 'create-queue']);
        self::assertSame(1, $result);
    }

    public function test_run_delete_queue_validates_name(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        $result = $console->run(['file-broker', 'delete-queue']);
        self::assertSame(1, $result);
    }

    public function test_run_dead_letter_validates_args(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        $result = $console->run(['file-broker', 'dead-letter']);
        self::assertSame(1, $result);
    }

    public function test_run_retry_validates_args(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        $result = $console->run(['file-broker', 'retry']);
        self::assertSame(1, $result);
    }

    public function test_run_watch_validates_queue(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        $result = $console->run(['file-broker', 'watch']);
        self::assertSame(1, $result);
    }

    public function test_run_stats_shows_stats(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => ['test' => ['name' => 'test']],
        ]);

        $console = new Console(new MessageBroker($config));

        ob_start();
        $result = $console->run(['file-broker', 'stats']);
        ob_end_clean();

        self::assertSame(0, $result);
    }

    public function test_run_health_shows_health(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        ob_start();
        $result = $console->run(['file-broker', 'health']);
        ob_end_clean();

        self::assertSame(0, $result);
    }

    public function test_run_unknown_command(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        ob_start();
        $result = $console->run(['file-broker', 'nonexistent']);
        ob_end_clean();

        self::assertSame(0, $result);
    }

    public function test_run_missing_params(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        // produce without queue name and body — should return 1
        $result = $console->run(['file-broker', 'produce']);
        self::assertSame(1, $result);
    }

    public function test_run_list_command(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        ob_start();
        $result = $console->run(['file-broker', 'list']);
        ob_end_clean();

        self::assertSame(0, $result);
    }

    public function test_run_stats_command(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => ['test' => ['name' => 'test']],
        ]);

        $console = new Console(new MessageBroker($config));

        ob_start();
        $result = $console->run(['file-broker', 'stats', 'test']);
        ob_end_clean();

        self::assertSame(0, $result);
    }

    public function test_run_with_config_option(): void
    {
        $configPath = $this->testDir . '/test-config.json';
        file_put_contents($configPath, json_encode([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]));

        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => [],
        ]);

        $console = new Console(new MessageBroker($config));

        // Use reflection to test that --config is parsed as an option
        $refMethod = new \ReflectionMethod($console, 'parseArgs');
        $parsed = $refMethod->invoke($console, ['file-broker', 'help', '--config', $configPath]);

        self::assertSame('help', $parsed['_command']);
        self::assertArrayHasKey('config', $parsed['_options']);
        self::assertSame($configPath, $parsed['_options']['config']);
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
