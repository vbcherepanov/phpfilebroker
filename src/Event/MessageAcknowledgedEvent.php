<?php

declare(strict_types=1);

namespace FileBroker\Event;

use FileBroker\Message\Message;

final class MessageAcknowledgedEvent
{
    public function __construct(
        public readonly Message $message,
        public readonly string $queueName,
    ) {}
}
