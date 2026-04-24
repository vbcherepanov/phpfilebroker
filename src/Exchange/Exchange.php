<?php

declare(strict_types=1);

namespace FileBroker\Exchange;

final class Exchange
{
    /**
     * @param list<Binding> $bindings
     */
    public function __construct(
        public readonly string $name,
        public readonly ExchangeType $type,
        public readonly array $bindings = [],
    ) {}

    /**
     * Route a message to matching queues based on exchange type.
     *
     * @param array<string, string> $headers Message headers for headers exchange matching
     * @return list<string> List of matching queue names
     */
    public function route(string $routingKey, array $headers = []): array
    {
        return match ($this->type) {
            ExchangeType::Direct => $this->routeDirect($routingKey),
            ExchangeType::Topic => $this->routeTopic($routingKey),
            ExchangeType::Fanout => $this->routeFanout(),
            ExchangeType::Headers => $this->routeHeaders($headers),
        };
    }

    /**
     * Create a new exchange with an added binding (immutable).
     */
    public function withBinding(Binding $binding): self
    {
        $bindings = $this->bindings;
        $bindings[] = $binding;

        return new self($this->name, $this->type, $bindings);
    }

    /**
     * Create a new exchange with a binding removed by queue name (immutable).
     */
    public function withoutBinding(string $queueName): self
    {
        $bindings = array_values(array_filter(
            $this->bindings,
            static fn(Binding $b): bool => $b->queueName !== $queueName,
        ));

        return new self($this->name, $this->type, $bindings);
    }

    /**
     * Serialize to array for persistence.
     *
     * @return array{
     *     name: string,
     *     type: string,
     *     bindings: list<array{
     *         queue_name: string,
     *         routing_key: string,
     *         header_match: array<string, string>,
     *         xmatch: string|null
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'bindings' => array_map(
                static fn(Binding $b): array => $b->toArray(),
                $this->bindings,
            ),
        ];
    }

    /**
     * Deserialize from an array.
     *
     * @param array{
     *     name?: string,
     *     type?: string,
     *     bindings?: list<array{
     *         queue_name: string,
     *         routing_key?: string,
     *         header_match?: array<string, string>,
     *         xmatch?: string|null
     *     }>
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $bindings = [];
        $bindingsRaw = $data['bindings'] ?? null;
        if (\is_array($bindingsRaw)) {
            foreach ($bindingsRaw as $b) {
                /** @var array $b */
                $bindings[] = Binding::fromArray($b);
            }
        }

        return new self(
            name: $data['name'] ?? throw new \InvalidArgumentException('Exchange name is required'),
            type: ExchangeType::from($data['type'] ?? throw new \InvalidArgumentException('Exchange type is required')),
            bindings: $bindings,
        );
    }

    // ── Routing logic ──

    /**
     * @return list<string>
     */
    private function routeDirect(string $routingKey): array
    {
        foreach ($this->bindings as $binding) {
            if ($binding->routingKey === $routingKey) {
                return [$binding->queueName];
            }
        }
        return [];
    }

    /**
     * @return list<string>
     */
    private function routeTopic(string $routingKey): array
    {
        $queues = [];
        foreach ($this->bindings as $binding) {
            if ($this->topicMatches($binding->routingKey, $routingKey)) {
                $queues[] = $binding->queueName;
            }
        }
        return $queues;
    }

    /**
     * @return list<string>
     */
    private function routeFanout(): array
    {
        return array_map(
            static fn(Binding $b): string => $b->queueName,
            $this->bindings,
        );
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private function routeHeaders(array $headers): array
    {
        $queues = [];
        foreach ($this->bindings as $binding) {
            if ($this->headersMatch($binding, $headers)) {
                $queues[] = $binding->queueName;
            }
        }
        return $queues;
    }

    /**
     * Match topic pattern against a routing key.
     *
     * Patterns use dot-separated words:
     * - `*` matches exactly one word
     * - `#` matches zero or more words
     */
    private function topicMatches(string $pattern, string $routingKey): bool
    {
        $patternWords = explode('.', $pattern);
        $keyWords = explode('.', $routingKey);

        $pn = \count($patternWords);
        $kn = \count($keyWords);

        // dp[i][j] = pattern[0..i-1] matches key[0..j-1]
        $dp = array_fill(0, $pn + 1, array_fill(0, $kn + 1, false));
        $dp[0][0] = true;

        // Handle patterns starting with # that match zero words
        for ($i = 0; $i < $pn; $i++) {
            if ($patternWords[$i] === '#') {
                $dp[$i + 1][0] = $dp[$i][0];
            }
        }

        for ($i = 0; $i < $pn; $i++) {
            for ($j = 0; $j <= $kn; $j++) {
                if (!$dp[$i][$j]) {
                    continue;
                }

                $pw = $patternWords[$i];

                if ($pw === '#') {
                    // # matches zero or more key words
                    $dp[$i + 1][$j] = true; // match 0 words, advance pattern
                    if ($j < $kn) {
                        $dp[$i][$j + 1] = true; // match 1 word, stay on #
                        $dp[$i + 1][$j + 1] = true; // match 1 word, advance pattern
                    }
                } elseif ($j < $kn && ($pw === '*' || $pw === $keyWords[$j])) {
                    $dp[$i + 1][$j + 1] = true;
                }
            }
        }

        return $dp[$pn][$kn];
    }

    /**
     * Match headers exchange binding against message headers.
     *
     * @param array<string, string> $headers
     */
    private function headersMatch(Binding $binding, array $headers): bool
    {
        if ($binding->headerMatch === []) {
            return false;
        }

        $xmatch = $binding->xmatch ?? 'any';

        if ($xmatch === 'all') {
            foreach ($binding->headerMatch as $key => $value) {
                if (!\array_key_exists($key, $headers) || $headers[$key] !== $value) {
                    return false;
                }
            }
            return true;
        }

        // 'any' — at least one match
        foreach ($binding->headerMatch as $key => $value) {
            if (\array_key_exists($key, $headers) && $headers[$key] === $value) {
                return true;
            }
        }
        return false;
    }
}
