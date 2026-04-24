<?php

declare(strict_types=1);

namespace FileBroker\Logging;

use Psr\Log\LoggerInterface;
use Stringable;

/**
 * PSR-3 compliant structured JSON logger.
 *
 * Writes one JSON object per line to a stream (defaults to STDERR).
 */
final class Logger implements LoggerInterface
{
    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream Write target (defaults to STDERR)
     */
    public function __construct($stream = null)
    {
        if ($stream === null) {
            $stream = \STDERR;
        }

        $this->stream = $stream;
    }

    /**
     * System is unusable.
     */
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Emergency->value, $message, $context);
    }

    /**
     * Action must be taken immediately.
     */
    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Alert->value, $message, $context);
    }

    /**
     * Critical conditions.
     */
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Critical->value, $message, $context);
    }

    /**
     * Runtime errors that do not require immediate action.
     */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Error->value, $message, $context);
    }

    /**
     * Exceptional occurrences that are not errors.
     */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Warning->value, $message, $context);
    }

    /**
     * Normal but significant events.
     */
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Notice->value, $message, $context);
    }

    /**
     * Interesting events.
     */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Info->value, $message, $context);
    }

    /**
     * Detailed debug information.
     */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::Debug->value, $message, $context);
    }

    /**
     * Log a message with structured JSON output (PSR-3).
     *
     * Accepts both {@see LogLevel} enum and PSR-3 string levels.
     *
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $levelString = \is_scalar($level) ? (string) $level : $level->value;

        $entry = [
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'level' => $levelString,
            'message' => (string) $message,
            'context' => (object) $context,
        ];

        $json = json_encode($entry, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        fwrite($this->stream, $json . "\n");
    }
}
