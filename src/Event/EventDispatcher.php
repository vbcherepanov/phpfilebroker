<?php

declare(strict_types=1);

namespace FileBroker\Event;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * PSR-14 compliant synchronous event dispatcher.
 *
 * Supports subscribe/unsubscribe in addition to the PSR-14 dispatch interface.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    /**
     * @var array<class-string, array<callable>>
     */
    private array $listeners = [];

    /**
     * Subscribe a listener to an event class.
     *
     * @template T of object
     * @param class-string<T> $eventClass
     * @param callable(T): void $callback
     */
    public function subscribe(string $eventClass, callable $callback): void
    {
        $this->listeners[$eventClass][] = $callback;
    }

    /**
     * Dispatch an event to all matching listeners (PSR-14).
     *
     * @template T of object
     * @param T $event
     * @return T The original event (for chaining)
     */
    public function dispatch(object $event): object
    {
        $eventClass = $event::class;
        if (!isset($this->listeners[$eventClass])) {
            return $event;
        }

        foreach ($this->listeners[$eventClass] as $listener) {
            $listener($event);
        }

        return $event;
    }

    /**
     * Get all registered listeners for an event class.
     *
     * @return array<callable>
     */
    public function getListeners(string $eventClass): array
    {
        return $this->listeners[$eventClass] ?? [];
    }

    /**
     * Check if any listeners are registered for an event class.
     */
    public function hasListeners(string $eventClass): bool
    {
        return isset($this->listeners[$eventClass]) && $this->listeners[$eventClass] !== [];
    }
}
