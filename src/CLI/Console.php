<?php

declare(strict_types=1);

namespace FileBroker\CLI;

use FileBroker\Broker\MessageBroker;
use FileBroker\CLI\Command\ConsumeCommand;
use FileBroker\CLI\Command\CreateQueueCommand;
use FileBroker\CLI\Command\DeadLetterCommand;
use FileBroker\CLI\Command\DeleteQueueCommand;
use FileBroker\CLI\Command\HealthCommand;
use FileBroker\CLI\Command\HelpCommand;
use FileBroker\CLI\Command\ListQueuesCommand;
use FileBroker\CLI\Command\ProduceCommand;
use FileBroker\CLI\Command\PurgeCommand;
use FileBroker\CLI\Command\RetryCommand;
use FileBroker\CLI\Command\StatsCommand;
use FileBroker\CLI\Command\WatchCommand;
use FileBroker\Logging\Logger;

/**
 * CLI console — parses arguments and dispatches to commands.
 */
final class Console
{
    private MessageBroker $broker;
    private readonly Logger $logger;

    public function __construct(
        ?MessageBroker $broker = null,
        ?Logger $logger = null,
    ) {
        $this->broker = $broker ?? new MessageBroker();
        $this->logger = $logger ?? new Logger(\STDERR);
    }

    /**
     * Run the CLI.
     *
     * Immutable: can be called multiple times without state leakage.
     *
     * @param list<string> $argv Argument vector (defaults to $_SERVER['argv'])
     * @return int Exit code
     */
    public function run(array $argv = []): int
    {
        if ($argv === []) {
            $argv = $_SERVER['argv'] ?? ['file-broker'];
        }

        try {
            $this->broker->ensureInitialized();
        } catch (\Throwable $e) {
            $this->logger->error("Broker initialization error: {$e->getMessage()}");
            return 1;
        }

        $parsed = $this->parseArgs($argv);
        $command = $parsed['_command'];

        return match ($command) {
            'help' => $this->handleHelp($parsed['_options']),
            'produce' => $this->handleProduce(
                $parsed['_params'],
                $parsed['_options'],
            ),
            'consume' => $this->handleConsume($parsed['_params']),
            'list' => $this->handleList(),
            'stats' => $this->handleStats($parsed['_params']),
            'purge' => $this->handlePurge($parsed['_params']),
            'create-queue' => $this->handleCreateQueue($parsed['_params']),
            'delete-queue' => $this->handleDeleteQueue($parsed['_params']),
            'dead-letter' => $this->handleDeadLetter($parsed['_params']),
            'retry' => $this->handleRetry($parsed['_params']),
            'watch' => $this->handleWatch(
                $parsed['_params'],
                $parsed['_options'],
            ),
            'health' => $this->handleHealth(),
            default => $this->handleHelp($parsed['_options']),
        };
    }

    // ──────────────────────────── Command Handlers ────────────────────────────

    private function handleHelp(array $options = []): int
    {
        (new HelpCommand())->execute($options);
        return 0;
    }

    private function handleProduce(array $params, array $options): int
    {
        if (\count($params) < 2) {
            $this->logger->error('Usage: file-broker produce <queue> <body> [options]');
            return 1;
        }

        $command = new ProduceCommand($this->broker, $this->logger);
        $command->execute([
            'queue' => $params[0],
            'body' => $params[1],
            'id' => $options['id'] ?? null,
            'headers' => $options['headers'] ?? null,
            'ttl' => isset($options['ttl']) ? (int) $options['ttl'] : null,
        ]);
        return 0;
    }

    private function handleConsume(array $params): int
    {
        if (\count($params) < 1) {
            $this->logger->error('Usage: file-broker consume <queue>');
            return 1;
        }

        $command = new ConsumeCommand($this->broker, $this->logger);
        $command->execute(['queue' => $params[0]]);
        return 0;
    }

    private function handleList(): int
    {
        (new ListQueuesCommand($this->broker, $this->logger))->execute();
        return 0;
    }

    private function handleStats(array $params): int
    {
        $command = new StatsCommand($this->broker, $this->logger);
        $command->execute(['queue' => $params[0] ?? null]);
        return 0;
    }

    private function handlePurge(array $params): int
    {
        if (\count($params) < 1) {
            $this->logger->error('Usage: file-broker purge <queue>');
            return 1;
        }

        (new PurgeCommand($this->broker, $this->logger))->execute(['queue' => $params[0]]);
        return 0;
    }

    private function handleCreateQueue(array $params): int
    {
        if (\count($params) < 1) {
            $this->logger->error('Usage: file-broker create-queue <name> [path]');
            return 1;
        }

        (new CreateQueueCommand($this->broker, $this->logger))->execute([
            'name' => $params[0],
            'path' => $params[1] ?? null,
        ]);
        return 0;
    }

    private function handleDeleteQueue(array $params): int
    {
        if (\count($params) < 1) {
            $this->logger->error('Usage: file-broker delete-queue <name>');
            return 1;
        }

        (new DeleteQueueCommand($this->broker, $this->logger))->execute(['name' => $params[0]]);
        return 0;
    }

    private function handleDeadLetter(array $params): int
    {
        if (\count($params) < 2) {
            $this->logger->error('Usage: file-broker dead-letter <queue> <message_id> [reason]');
            return 1;
        }

        (new DeadLetterCommand($this->broker, $this->logger))->execute([
            'queue' => $params[0],
            'id' => $params[1],
            'reason' => $params[2] ?? 'CLI dead-letter',
        ]);
        return 0;
    }

    private function handleRetry(array $params): int
    {
        if (\count($params) < 2) {
            $this->logger->error('Usage: file-broker retry <queue> <message_id>');
            return 1;
        }

        (new RetryCommand($this->broker, $this->logger))->execute([
            'queue' => $params[0],
            'id' => $params[1],
        ]);
        return 0;
    }

    private function handleWatch(array $params, array $options): int
    {
        if (\count($params) < 1) {
            $this->logger->error('Usage: file-broker watch <queue> [options]');
            return 1;
        }

        (new WatchCommand($this->broker, $this->logger))->execute([
            'queue' => $params[0],
            'limit' => $options['limit'] ?? null,
            'once' => isset($options['once']),
        ]);
        return 0;
    }

    private function handleHealth(): int
    {
        (new HealthCommand($this->broker, $this->logger))->execute();
        return 0;
    }

    // ──────────────────────────── Argument Parsing ────────────────────────────

    /**
     * Parse argv into command, params, and options.
     *
     * @param list<string> $argv
     * @return array{
     *   _command: string,
     *   _params: list<string>,
     *   _options: array<string, mixed>
     * }
     */
    private function parseArgs(array $argv): array
    {
        $parsed = [
            '_command' => 'help',
            '_params' => [],
            '_options' => [],
        ];

        $i = 1; // Skip program name

        while ($i < \count($argv)) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--')) {
                $key = substr($arg, 2);
                $next = $argv[$i + 1] ?? null;

                if ($next !== null && !str_starts_with($next, '--')) {
                    $parsed['_options'][$key] = $next;
                    $i += 2;
                } else {
                    $parsed['_options'][$key] = true;
                    $i += 1;
                }
            } elseif ($parsed['_command'] === 'help') {
                $parsed['_command'] = $arg;
                $i += 1;
            } else {
                $parsed['_params'][] = $arg;
                $i += 1;
            }
        }

        return $parsed;
    }
}
