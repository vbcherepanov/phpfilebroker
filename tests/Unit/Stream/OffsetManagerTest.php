<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Stream;

use FileBroker\Stream\OffsetManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OffsetManager::class)]
final class OffsetManagerTest extends TestCase
{
    private string $testDir;

    public function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/file-broker-offset-test-' . bin2hex(random_bytes(4));
        mkdir($this->testDir, 0755, true);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->testDir);
    }

    public function test_commit_and_get_offset(): void
    {
        $manager = new OffsetManager($this->testDir);

        // Initially, get returns offset 0
        $initial = $manager->get('my-queue', 'group-1');
        self::assertSame(0, $initial->offset);
        self::assertSame('group-1', $initial->consumerGroup);
        self::assertSame('my-queue', $initial->queueName);

        // Commit offset 5
        $manager->commit('my-queue', 'group-1', 5);

        // Get should now return 5
        $updated = $manager->get('my-queue', 'group-1');
        self::assertSame(5, $updated->offset);
    }

    public function test_reset_sets_offset_to_zero(): void
    {
        $manager = new OffsetManager($this->testDir);

        // Commit some offset
        $manager->commit('my-queue', 'group-1', 42);
        self::assertSame(42, $manager->get('my-queue', 'group-1')->offset);

        // Reset to 0
        $manager->reset('my-queue', 'group-1');
        self::assertSame(0, $manager->get('my-queue', 'group-1')->offset);
    }

    public function test_list_groups_returns_groups(): void
    {
        $manager = new OffsetManager($this->testDir);

        // Commit offsets for different groups
        $manager->commit('my-queue', 'group-a', 1);
        $manager->commit('my-queue', 'group-b', 2);
        $manager->commit('my-queue', 'group-c', 3);

        $groups = $manager->listGroups('my-queue');
        self::assertSame(['group-a', 'group-b', 'group-c'], $groups);

        // Empty queue should return empty list
        $empty = $manager->listGroups('nonexistent');
        self::assertSame([], $empty);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
