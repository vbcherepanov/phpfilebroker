<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Flow;

use FileBroker\Flow\PrefetchController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrefetchController::class)]
final class PrefetchControllerTest extends TestCase
{
    public function test_can_receive_when_under_limit(): void
    {
        $controller = new PrefetchController(prefetchCount: 10);

        self::assertTrue($controller->canReceive(0));
        self::assertTrue($controller->canReceive(5));
        self::assertTrue($controller->canReceive(9));
    }

    public function test_cannot_receive_when_at_limit(): void
    {
        $controller = new PrefetchController(prefetchCount: 10);

        self::assertFalse($controller->canReceive(10));
        self::assertFalse($controller->canReceive(15));
    }

    public function test_prefetch_size_zero_means_unlimited(): void
    {
        // prefetchSize = 0 means no size-based limiting
        $controller = new PrefetchController(
            prefetchCount: 5,
            prefetchSize: 0,
        );

        // prefetchCount still applies
        self::assertTrue($controller->canReceive(4));
        self::assertFalse($controller->canReceive(5));
    }
}
