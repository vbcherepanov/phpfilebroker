<?php

declare(strict_types=1);

namespace FileBroker\Exception;

final class DeserializationException extends BrokerException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
