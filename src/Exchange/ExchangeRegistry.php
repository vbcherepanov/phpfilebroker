<?php

declare(strict_types=1);

namespace FileBroker\Exchange;

final class ExchangeRegistry
{
    public function __construct(
        private readonly string $storagePath,
    ) {}

    /**
     * List all exchange names.
     *
     * @return list<string>
     */
    public function list(): array
    {
        $this->ensureDir();

        $files = scandir($this->storagePath) ?: [];
        $names = [];

        foreach ($files as $file) {
            if (str_ends_with($file, '.json')) {
                $names[] = substr($file, 0, -5);
            }
        }

        sort($names);
        return $names;
    }

    /**
     * Create a new exchange.
     */
    public function create(string $name, ExchangeType $type): Exchange
    {
        $this->ensureDir();

        $exchange = new Exchange(name: $name, type: $type);
        $this->persist($exchange);

        return $exchange;
    }

    /**
     * Get an exchange by name or null if not found.
     */
    public function get(string $name): ?Exchange
    {
        $path = $this->exchangePath($name);

        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data)) {
            return null;
        }

        if (!isset($data['name']) || !\is_string($data['name'])) {
            return null;
        }

        if (!isset($data['type']) || !\is_string($data['type'])) {
            return null;
        }

        return Exchange::fromArray($data);
    }

    /**
     * Delete an exchange by name.
     */
    public function delete(string $name): void
    {
        $path = $this->exchangePath($name);
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Add a binding to an exchange (persisted immediately).
     */
    public function bind(string $exchangeName, Binding $binding): void
    {
        $exchange = $this->get($exchangeName)
            ?? throw new \RuntimeException(\sprintf('Exchange not found: %s', $exchangeName));

        $updated = $exchange->withBinding($binding);
        $this->persist($updated);
    }

    /**
     * Remove a binding from an exchange by queue name (persisted immediately).
     */
    public function unbind(string $exchangeName, string $queueName): void
    {
        $exchange = $this->get($exchangeName)
            ?? throw new \RuntimeException(\sprintf('Exchange not found: %s', $exchangeName));

        $updated = $exchange->withoutBinding($queueName);
        $this->persist($updated);
    }

    /**
     * Get the filesystem path for an exchange JSON file.
     */
    private function exchangePath(string $name): string
    {
        return $this->storagePath . '/' . $name . '.json';
    }

    /**
     * Ensure the storage directory exists.
     */
    private function ensureDir(): void
    {
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * Persist an exchange to disk as JSON.
     */
    private function persist(Exchange $exchange): void
    {
        $this->ensureDir();

        $json = json_encode($exchange->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);
        $path = $this->exchangePath($exchange->name);

        $tmpPath = $path . '.tmp.' . getmypid();
        $result = file_put_contents($tmpPath, $json, \LOCK_EX);

        if ($result === false || $result !== \strlen($json)) {
            @unlink($tmpPath);
            throw new \RuntimeException(\sprintf('Failed to write exchange file: %s', $path));
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new \RuntimeException(\sprintf('Failed to atomically write exchange file: %s', $path));
        }
    }
}
