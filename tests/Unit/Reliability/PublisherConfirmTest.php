<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Reliability;

use FileBroker\Reliability\PublisherConfirm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublisherConfirm::class)]
final class PublisherConfirmTest extends TestCase
{
    public function test_register_and_confirm_message(): void
    {
        $confirm = new PublisherConfirm();

        self::assertSame(0, $confirm->pendingCount());

        $confirm->register('msg-001');

        self::assertSame(1, $confirm->pendingCount());
        self::assertSame(['msg-001'], $confirm->pendingIds());

        $confirm->confirm('msg-001');

        self::assertSame(0, $confirm->pendingCount());
        self::assertSame([], $confirm->pendingIds());
    }

    public function test_wait_for_all_with_pending_returns_false(): void
    {
        $confirm = new PublisherConfirm();
        $confirm->register('msg-001');

        $result = $confirm->waitForAll(1);

        self::assertFalse($result, 'Should timeout when messages remain unconfirmed');
        self::assertSame(1, $confirm->pendingCount());
    }

    public function test_pending_count_after_confirms(): void
    {
        $confirm = new PublisherConfirm();

        $confirm->register('msg-001');
        $confirm->register('msg-002');
        $confirm->register('msg-003');

        self::assertSame(3, $confirm->pendingCount());

        $confirm->confirm('msg-001');
        self::assertSame(2, $confirm->pendingCount());

        $confirm->confirm('msg-002');
        self::assertSame(1, $confirm->pendingCount());

        $confirm->confirm('msg-003');
        self::assertSame(0, $confirm->pendingCount());
    }

    public function test_callback_invoked_on_confirm(): void
    {
        $confirm = new PublisherConfirm();
        $receivedId = null;

        $confirm->register('msg-cb', function (string $messageId) use (&$receivedId): void {
            $receivedId = $messageId;
        });

        $confirm->confirm('msg-cb');

        self::assertSame('msg-cb', $receivedId, 'Callback must be invoked with message ID');
        self::assertSame(0, $confirm->pendingCount());
    }
}
