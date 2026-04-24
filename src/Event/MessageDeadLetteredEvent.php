<?php

declare(strict_types=1);

namespace FileBroker\Event;

use FileBroker\Message\Message;

final class MessageDeadLetteredEvent
{
    public function __construct(
        public readonly Message $message,
        public readonly string $queueName,
        public readonly string $reason,
    ) {}
}
