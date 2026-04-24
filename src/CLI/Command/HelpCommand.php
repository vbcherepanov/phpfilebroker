<?php

declare(strict_types=1);

namespace FileBroker\CLI\Command;

final class HelpCommand
{
    public function execute(array $options = []): void
    {
        $help = <<<HELP
    File Broker — CLI Tool
    ========================

    Usage: file-broker <command> [options]

    Commands:
      produce <queue> <body>          Send a message to a queue
      consume <queue>                 Receive the next message from a queue
      list                            List all queues
      stats <queue>                   Show queue statistics
      purge <queue>                   Delete all messages from a queue
      create-queue <name> <path>      Create a new queue
      delete-queue <name>             Delete a queue
      dead-letter <queue> <id> [r]    Move message to DLQ
      retry <queue> <id>              Retry a message
      watch <queue>                   Watch queue for new messages
      health                          Show broker health status
      help                            Show this help message

    Options:
      --config <path>     Path to config file (default: ./config/broker.json)
      --ttl <seconds>     Message TTL in seconds
      --id <id>           Custom message ID
      --headers <json>    JSON headers object
      --limit <n>         Limit output to n items
      --once              Exit after one iteration (for watch)
      --verbose           Show detailed output

    Examples:
      file-broker produce orders '{"order_id": 123}'
      file-broker consume orders
      file-broker stats orders
      file-broker watch orders --limit 100
      file-broker produce emails '{"to":"user@example.com"}' --ttl 3600
    HELP;

        echo $help . "\n";
    }
}
