<?php

declare(strict_types=1);

namespace FileBroker\Logging;

/**
 * PSR-3 compatible log levels.
 *
 * Maps to {@see \Psr\Log\LogLevel} string constants.
 */
enum LogLevel: string
{
    case Emergency = 'emergency';
    case Alert = 'alert';
    case Critical = 'critical';
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';
    case Info = 'info';
    case Debug = 'debug';
}
