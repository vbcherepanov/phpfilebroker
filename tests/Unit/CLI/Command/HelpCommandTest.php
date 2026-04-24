<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\CLI\Command;

use FileBroker\CLI\Command\HelpCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HelpCommand::class)]
final class HelpCommandTest extends TestCase
{
    public function test_execute_outputs_help(): void
    {
        $command = new HelpCommand();

        ob_start();
        $command->execute();
        $output = ob_get_clean();

        self::assertStringContainsString('File Broker', $output);
        self::assertStringContainsString('produce', $output);
        self::assertStringContainsString('consume', $output);
        self::assertStringContainsString('list', $output);
        self::assertStringContainsString('stats', $output);
        self::assertStringContainsString('Options:', $output);
        self::assertStringContainsString('Examples:', $output);
    }

    public function test_execute_contains_all_commands(): void
    {
        $command = new HelpCommand();

        ob_start();
        $command->execute();
        $output = ob_get_clean();

        $requiredCommands = [
            'produce',
            'consume',
            'list',
            'stats',
            'purge',
            'create-queue',
            'delete-queue',
            'dead-letter',
            'retry',
            'watch',
            'health',
            'help',
        ];

        foreach ($requiredCommands as $cmd) {
            self::assertStringContainsString($cmd, $output, "Help should contain command: {$cmd}");
        }
    }
}
