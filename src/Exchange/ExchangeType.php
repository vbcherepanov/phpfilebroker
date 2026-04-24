<?php

declare(strict_types=1);

namespace FileBroker\Exchange;

enum ExchangeType: string
{
    case Direct = 'direct';
    case Topic = 'topic';
    case Fanout = 'fanout';
    case Headers = 'headers';
}
