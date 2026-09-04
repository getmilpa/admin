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

namespace Milpa\Admin\Data;

use Milpa\Attributes\PluginMetadata;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Plugin\Activation\DeclaredPlugins;
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\Runtime\Kernel;

/**
 * What the app knows about its plugins, read from the same places the kernel reads.
 *
 * `DeclaredPlugins` (config/plugins.php, as `ActivePlugins::wire()` left it) says what was declared;
 * `PluginRegistryInterface` (storage/plugins.json) says what was installed or toggled. Both are optional
 * — the panel degrades to "declared only" — and the metadata comes from each class's own
 * `#[PluginMetadata]`, never from a second list. Capabilities come from `milpa/app-runtime` when it is
 * installed, and are simply absent when it is not.
 */
final class PluginsSource
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /**
     * The plugins table plus, when available, the capability catalogue.
     *
     * @return array{registry: bool, plugins: list<array{name: string, version: string, type: string, enabled: bool, source: string, class: string|null}>, capabilities: array{installed: list<array<string, mixed>>, available: list<array<string, mixed>>, source: string}|null}
     */
    public function snapshot(): array
    {
        $registry = $this->tryGet(PluginRegistryInterface::class);
        $declared = $this->tryGet(DeclaredPlugins::class);

        /** @var array<string, PluginRecord> $records */
        $records = [];
        if ($registry instanceof PluginRegistryInterface) {
            foreach ($registry->installed() as $record) {
                $records[$record->name] = $record;
            }
        }

        /** @var array<string, string> $classes class → how the panel learned of it */
        $classes = [];
        if ($declared instanceof DeclaredPlugins) {
            foreach ($declared->classes as $class) {
                $classes[$class] = 'declared';
            }
        }
        // The instances the kernel actually booted are the truest list: an app that does not go through
        // ActivePlugins::wire() has no DeclaredPlugins, but it still has a kernel.
        $kernel = $this->tryGet(Kernel::class);
        if ($kernel instanceof Kernel) {
            foreach ($kernel->plugins() as $plugin) {
                $classes[$plugin::class] ??= 'booted';
            }
        }

        $rows = [];
        foreach ($classes as $class => $how) {
            $meta = self::metadata($class);
            $name = $meta['name'] ?? self::shortName($class);
            $record = $records[$name] ?? null;
            unset($records[$name]);
            $rows[] = [
                'name' => $name,
                'version' => $meta['version'] ?? ($record->version ?? ''),
                'type' => $meta['type'] ?? ($record->type ?? ''),
                'enabled' => $record->enabled ?? true,
                'source' => $record->source ?? $how,
                'class' => $class,
            ];
        }
        foreach ($records as $record) {
            $rows[] = [
                'name' => $record->name,
                'version' => $record->version,
                'type' => $record->type,
                'enabled' => $record->enabled,
                'source' => $record->source ?? 'installed',
                'class' => null,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'registry' => $registry instanceof PluginRegistryInterface,
            'plugins' => $rows,
            'capabilities' => $this->capabilities(),
        ];
    }

    private function tryGet(string $id): ?object
    {
        if (!$this->container->has($id)) {
            return null;
        }
        $service = $this->container->get($id);

        return \is_object($service) ? $service : null;
    }

    /**
     * @param class-string|string $class
     *
     * @return array{name?: string, version?: string, type?: string}
     */
    private static function metadata(string $class): array
    {
        if (!class_exists($class)) {
            return [];
        }
        $attributes = (new \ReflectionClass($class))->getAttributes(PluginMetadata::class);
        if ($attributes === []) {
            return [];
        }
        $meta = $attributes[0]->newInstance();

        return ['name' => $meta->name, 'version' => $meta->version, 'type' => $meta->type];
    }

    private static function shortName(string $class): string
    {
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }

    /**
     * The capability catalogue of `milpa/app-runtime`, when that package is installed.
     *
     * @codeCoverageIgnore — exercised on cattle; the panel's own suite runs without app-runtime
     *
     * @return array{installed: list<array<string, mixed>>, available: list<array<string, mixed>>, source: string}|null
     */
    private function capabilities(): ?array
    {
        $class = 'Milpa\\AppRuntime\\Support\\Capabilities';
        if (!class_exists($class)) {
            return null;
        }
        try {
            /** @var array<string, mixed> $answer */
            $answer = $class::answer();
        } catch (\Throwable) {
            return null;
        }

        return [
            'installed' => \is_array($answer['installed'] ?? null) ? array_values($answer['installed']) : [],
            'available' => \is_array($answer['available'] ?? null) ? array_values($answer['available']) : [],
            'source' => \is_string($answer['source'] ?? null) ? $answer['source'] : '',
        ];
    }
}
