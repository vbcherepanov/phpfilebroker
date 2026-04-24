<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Worker;

use FileBroker\Broker\MessageBroker;
use FileBroker\Config\BrokerConfig;
use FileBroker\Message\Message;
use FileBroker\Worker\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Worker::class)]
final class WorkerTest extends TestCase
{
    private string $testDir;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/file-broker-worker-test-' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function test_worker_stop_stops_loop(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => ['test' => ['name' => 'test']],
        ]);

        $broker = new MessageBroker($config);
        $worker = new Worker('test', $broker);

        // Stop before run — loop should exit immediately.
        $worker->stop();
        $worker->run();

        self::assertFalse($worker->isRunning());
    }

    public function test_worker_process_auto_acknowledges_message(): void
    {
        $config = BrokerConfig::fromArray([
            'storage_path' => $this->testDir,
            'queues' => ['test' => ['name' => 'test']],
        ]);

        $broker = new MessageBroker($config);

        // Produce a message.
        $message = $broker->produce('test', '{"id":1}');

        $worker = new Worker('test', $broker);

        // Invoke process() directly to verify auto-acknowledge behaviour.
        $refMethod = new \ReflectionMethod(Worker::class, 'process');
        $refMethod->invoke($worker, $message);

        // After auto-acknowledge, the message file should be deleted.
        $stats = $broker->getQueueStats('test');
        self::assertSame(0, $stats['message_count'], 'Message should be acknowledged (deleted)');
    }

    public function test_worker_constructor_accepts_handler(): void
    {
        $broker = $this->createMock(MessageBroker::class);

        $handler = function (Message $_msg, MessageBroker $_brk): void {
            // No-op for test.
        };

        $worker = new Worker('test', $broker, $handler);

        self::assertTrue($worker->isRunning(), 'Worker should be running after construction');
    }

    public function test_worker_is_running_defaults_to_true(): void
    {
        $broker = $this->createMock(MessageBroker::class);
        $worker = new Worker('test', $broker);

        self::assertTrue($worker->isRunning());
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
