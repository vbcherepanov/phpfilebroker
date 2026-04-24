<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Logging;

use FileBroker\Logging\Logger;
use FileBroker\Logging\LogLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Logger::class)]
final class LoggerTest extends TestCase
{
    /** @var resource */
    private $stream;

    private Logger $logger;

    protected function setUp(): void
    {
        $this->stream = fopen('php://memory', 'w+');
        if ($this->stream === false) {
            self::fail('Failed to open memory stream');
        }
        $this->logger = new Logger($this->stream);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    private function getStreamContent(): string
    {
        rewind($this->stream);
        $content = stream_get_contents($this->stream);
        return $content !== false ? trim($content) : '';
    }

    public function test_log_writes_json_to_stream(): void
    {
        $this->logger->log(LogLevel::Info, 'test message');

        $content = $this->getStreamContent();
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertSame('info', $decoded['level']);
        $this->assertSame('test message', $decoded['message']);
        $this->assertArrayHasKey('timestamp', $decoded);
        $this->assertArrayHasKey('context', $decoded);
    }

    public function test_info_shortcut_writes_correct_level(): void
    {
        $this->logger->info('info message');

        $content = $this->getStreamContent();
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame('info', $decoded['level']);
    }

    public function test_error_shortcut_writes_correct_level(): void
    {
        $this->logger->error('error message');

        $content = $this->getStreamContent();
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame('error', $decoded['level']);
    }

    public function test_warning_shortcut_writes_correct_level(): void
    {
        $this->logger->warning('warning message');

        $content = $this->getStreamContent();
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame('warning', $decoded['level']);
    }

    public function test_debug_shortcut_writes_correct_level(): void
    {
        $this->logger->debug('debug message');

        $content = $this->getStreamContent();
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame('debug', $decoded['level']);
    }

    public function test_context_fields_appear_in_output(): void
    {
        $context = ['user_id' => 42, 'action' => 'purchase'];

        $this->logger->info('action performed', $context);

        $content = $this->getStreamContent();
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded['context']);
        $this->assertSame(42, $decoded['context']['user_id']);
        $this->assertSame('purchase', $decoded['context']['action']);
    }

    public function test_log_to_file_stream(): void
    {
        $filePath = sys_get_temp_dir() . '/filebroker_logger_test_' . uniqid() . '.log';

        try {
            $fileStream = fopen($filePath, 'w+');

            if ($fileStream === false) {
                self::fail('Failed to open file stream');
            }

            $logger = new Logger($fileStream);
            $logger->info('file stream test', ['key' => 'value']);

            fclose($fileStream);

            $content = file_get_contents($filePath);
            $this->assertIsString($content);
            $this->assertStringContainsString('file stream test', $content);
            $this->assertStringContainsString('"level":"info"', $content);
            $this->assertStringContainsString('"key":"value"', $content);
        } finally {
            @unlink($filePath);
        }
    }

    public function test_timestamp_is_valid_iso8601(): void
    {
        $this->logger->info('timestamp check');

        $content = $this->getStreamContent();
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertIsString($decoded['timestamp']);
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $decoded['timestamp']);
        $this->assertNotFalse($parsed, "Timestamp '{$decoded['timestamp']}' is not valid ISO8601/ATOM");
    }

    public function test_default_stream_is_stderr(): void
    {
        // Cannot easily assert it IS STDERR, but construction without arg must not throw.
        $logger = new Logger();
        // PHPStan: verify Logger is instantiated
        $this->assertInstanceOf(Logger::class, $logger);
    }

    public function test_multiple_logs_produce_multiple_lines(): void
    {
        $this->logger->info('first');
        $this->logger->info('second');

        $content = $this->getStreamContent();
        $lines = explode("\n", $content);

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('first', $lines[0]);
        $this->assertStringContainsString('second', $lines[1]);
    }
}
