<?php

declare(strict_types=1);

namespace FileBroker\Exception;

use FileBroker\Message\Message;

final class MessageExpiredException extends BrokerException
{
    public function __construct(Message $message)
    {
        parent::__construct(\sprintf('Message %s has expired', $message->id));
    }
}
