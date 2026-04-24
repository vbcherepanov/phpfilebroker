<?php

declare(strict_types=1);

namespace FileBroker\Observability;

/**
 * Thread-safe in-memory metrics collector.
 *
 * Provides counters and histograms for broker observability.
 *
 * @phpstan-type MetricsSnapshot array{
 *   counters: array<string, int>,
 *   histograms: array<string, array{count: int, sum: float, min: float, max: float, avg: float}>
 * }
 */
final class MetricsCollector
{
    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, list<float>> */
    private array $histograms = [];

    /**
     * Increment a named counter. Initializes to 0 on first call if not set.
     */
    public function incrementCounter(string $name, int $by = 1): void
    {
        if (!isset($this->counters[$name])) {
            $this->counters[$name] = 0;
        }

        $this->counters[$name] += $by;
    }

    /**
     * Record a value in a named histogram.
     */
    public function recordHistogram(string $name, float $value): void
    {
        if (!isset($this->histograms[$name])) {
            $this->histograms[$name] = [];
        }

        $this->histograms[$name][] = $value;
    }

    /**
     * Get a snapshot of all counters and histogram summaries.
     * Unregistered counter names return 0.
     *
     * @return MetricsSnapshot
     */
    public function getSnapshot(): array
    {
        $histogramSummaries = [];

        foreach ($this->histograms as $name => $values) {
            $count = \count($values);

            if ($count === 0) {
                $histogramSummaries[$name] = [
                    'count' => 0,
                    'sum' => 0.0,
                    'min' => 0.0,
                    'max' => 0.0,
                    'avg' => 0.0,
                ];
                continue;
            }

            $sum = array_sum($values);
            $min = min($values);
            $max = max($values);

            $histogramSummaries[$name] = [
                'count' => $count,
                'sum' => $sum,
                'min' => $min,
                'max' => $max,
                'avg' => $sum / $count,
            ];
        }

        return [
            'counters' => $this->counters,
            'histograms' => $histogramSummaries,
        ];
    }

    /**
     * Get a specific counter value. Returns 0 for unregistered counters.
     */
    public function getCounter(string $name): int
    {
        return $this->counters[$name] ?? 0;
    }

    /**
     * Reset all counters and histograms.
     */
    public function reset(): void
    {
        $this->counters = [];
        $this->histograms = [];
    }
}
