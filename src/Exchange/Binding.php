<?php

declare(strict_types=1);

namespace FileBroker\Exchange;

final class Binding
{
    public function __construct(
        public readonly string $queueName,
        public readonly string $routingKey = '',
        /** @var array<string, string> */
        public readonly array $headerMatch = [],
        public readonly ?string $xmatch = null,
    ) {}

    /**
     * @return array{
     *     queue_name: string,
     *     routing_key: string,
     *     header_match: array<string, string>,
     *     xmatch: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'queue_name' => $this->queueName,
            'routing_key' => $this->routingKey,
            'header_match' => $this->headerMatch,
            'xmatch' => $this->xmatch,
        ];
    }

    /**
     * @param array{
     *     queue_name?: string,
     *     routing_key?: string,
     *     header_match?: array<string, string>,
     *     xmatch?: string|null
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            queueName: $data['queue_name'] ?? throw new \InvalidArgumentException('Binding queue_name is required'),
            routingKey: $data['routing_key'] ?? '',
            headerMatch: $data['header_match'] ?? [],
            xmatch: $data['xmatch'] ?? null,
        );
    }
}
