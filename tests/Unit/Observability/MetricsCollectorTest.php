<?php

declare(strict_types=1);

namespace FileBroker\Tests\Unit\Observability;

use FileBroker\Observability\MetricsCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricsCollector::class)]
final class MetricsCollectorTest extends TestCase
{
    public function test_increment_counter(): void
    {
        $collector = new MetricsCollector();

        $collector->incrementCounter('test_counter');
        $collector->incrementCounter('test_counter', 2);

        $snapshot = $collector->getSnapshot();

        $this->assertSame(3, $snapshot['counters']['test_counter']);
    }

    public function test_record_histogram_and_get_snapshot(): void
    {
        $collector = new MetricsCollector();

        $collector->recordHistogram('response_time', 1.5);

        $snapshot = $collector->getSnapshot();

        $this->assertArrayHasKey('response_time', $snapshot['histograms']);
        $this->assertSame(1, $snapshot['histograms']['response_time']['count']);
        $this->assertSame(1.5, $snapshot['histograms']['response_time']['sum']);
        $this->assertSame(1.5, $snapshot['histograms']['response_time']['min']);
        $this->assertSame(1.5, $snapshot['histograms']['response_time']['max']);
        $this->assertSame(1.5, $snapshot['histograms']['response_time']['avg']);
    }

    public function test_multiple_histogram_values(): void
    {
        $collector = new MetricsCollector();

        $collector->recordHistogram('latency', 10.0);
        $collector->recordHistogram('latency', 20.0);
        $collector->recordHistogram('latency', 30.0);

        $snapshot = $collector->getSnapshot();

        $this->assertSame(3, $snapshot['histograms']['latency']['count']);
        $this->assertSame(60.0, $snapshot['histograms']['latency']['sum']);
        $this->assertSame(10.0, $snapshot['histograms']['latency']['min']);
        $this->assertSame(30.0, $snapshot['histograms']['latency']['max']);
        $this->assertSame(20.0, $snapshot['histograms']['latency']['avg']);
    }

    public function test_snapshot_with_no_data(): void
    {
        $collector = new MetricsCollector();

        $snapshot = $collector->getSnapshot();

        $this->assertSame(0, $collector->getCounter('nonexistent'));
        $this->assertSame([], $snapshot['counters']);
        $this->assertSame([], $snapshot['histograms']);
    }
}
