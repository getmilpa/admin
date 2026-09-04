<?php

/**
 * This file is part of Milpa Admin — the administration panel of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/admin
 */

declare(strict_types=1);

namespace Milpa\Admin\Tests\Fixtures;

use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;

/**
 * A synchronous dispatcher that remembers every event name it saw — enough to observe the lifecycle.
 */
final class RecordingDispatcher implements MilpaEventDispatcherInterface
{
    /** @var list<string> */
    public array $dispatched = [];

    /** @var array<string, list<callable>> */
    private array $subscribers = [];

    public function dispatch(string $eventName, array $payload = [], bool $async = false): void
    {
        $this->dispatched[] = $eventName;
        foreach ($this->subscribers[$eventName] ?? [] as $handler) {
            $handler($eventName, $payload);
        }
    }

    public function subscribe(string $eventName, callable $handler, int $priority = 0): void
    {
        $this->subscribers[$eventName][] = $handler;
    }

    public function getSubscribers(string $eventName): array
    {
        return $this->subscribers[$eventName] ?? [];
    }

    public function hasSubscribers(string $eventName): bool
    {
        return ($this->subscribers[$eventName] ?? []) !== [];
    }
}
