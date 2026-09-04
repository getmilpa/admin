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

use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Contracts\PluginRegistryInterface;

/**
 * An in-memory plugin registry seeded with records — what `storage/plugins.json` would hold.
 */
final class ArrayPluginRegistry implements PluginRegistryInterface
{
    /** @var array<string, PluginRecord> */
    private array $records = [];

    /** @param list<PluginRecord> $records */
    public function __construct(array $records = [])
    {
        foreach ($records as $record) {
            $this->records[$record->name] = $record;
        }
    }

    public function enabledNames(): array
    {
        return array_values(array_map(
            static fn (PluginRecord $r): string => $r->name,
            array_filter($this->records, static fn (PluginRecord $r): bool => $r->enabled),
        ));
    }

    public function find(string $name): ?PluginRecord
    {
        return $this->records[$name] ?? null;
    }

    public function installed(): array
    {
        return array_values($this->records);
    }

    public function installedAndEnabled(): array
    {
        return array_values(array_filter($this->records, static fn (PluginRecord $r): bool => $r->enabled));
    }

    public function register(PluginRecord $record): void
    {
        $this->records[$record->name] = $record;
    }

    public function save(PluginRecord $record): void
    {
        $this->records[$record->name] = $record;
    }

    public function setEnabled(string $name, bool $enabled): void
    {
        if (isset($this->records[$name])) {
            $this->records[$name] = $this->records[$name]->withEnabled($enabled);
        }
    }

    public function unregister(string $name): void
    {
        unset($this->records[$name]);
    }

    public function invalidateActivationCache(): void
    {
    }
}
