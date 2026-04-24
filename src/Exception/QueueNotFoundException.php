<?php

declare(strict_types=1);

namespace FileBroker\Exception;

final class QueueNotFoundException extends BrokerException
{
    public function __construct(string $queueName)
    {
        parent::__construct(\sprintf('Queue "%s" not found', $queueName));
    }
}
