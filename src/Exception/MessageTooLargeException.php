<?php

declare(strict_types=1);

namespace FileBroker\Exception;

final class MessageTooLargeException extends BrokerException
{
    public function __construct(int $size, int $maxSize)
    {
        parent::__construct(\sprintf('Message size %d bytes exceeds maximum %d bytes', $size, $maxSize));
    }
}
