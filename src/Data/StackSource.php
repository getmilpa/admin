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

use Milpa\Admin\Stack\ComposeProjection;
use Milpa\Admin\Stack\ReachabilityProbe;
use Milpa\Admin\Stack\ResolvedEnv;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;
use Milpa\Runtime\Stack\PortMapping;
use Milpa\Runtime\Stack\ServiceDeclaration;
use Milpa\Runtime\Stack\StackProviderInterface;

/**
 * Every backing service the booted plugins declared they need, with what the panel can observe about it.
 *
 * Discovery is `instanceof StackProviderInterface` over `Kernel::plugins()` — the same pattern the routes
 * table uses. For each declaration the snapshot carries the declaration (image, ports, env, volumes,
 * command, summary, the declaring plugin), the env with its values RESOLVED — a secret has no value here
 * and none downstream; the glyph a human sees is the renderer's —, the service's compose fragment, and
 * its state: `up` when the probe port accepts TCP on the probe's host, `down` when it refuses, `unknown`
 * when the service publishes no port to probe, `conflict` when another plugin declared the same name.
 * A collision drops nothing: every colliding row is kept and names the others, because a compose file
 * keys services by name and two declarations under one key would silently overwrite each other.
 * Nothing here starts or stops anything (greenhouse decisions/0201).
 */
final class StackSource
{
    public const CONFLICT = 'conflict';

    /**
     * @param object|null $fallbackProvider a provider to read when no kernel is in the container (tests)
     */
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly ReachabilityProbe $probe,
        private readonly ComposeProjection $projection,
        private readonly ?object $fallbackProvider = null,
    ) {
    }

    /**
     * The services table, sorted by name, each with its resolved env, compose fragment and probed state.
     *
     * @return array{kernel: bool, services: list<array{name: string, image: string, ports: list<string>, env: list<array{name: string, source: string, display: string|null, configKey: string|null}>, volumes: list<string>, command: list<string>, summary: string, plugin: string, probeHost: string, probePort: int|null, state: string, conflictsWith: list<string>, compose: string}>}
     */
    public function snapshot(): array
    {
        $discovery = $this->discover();
        $config = $this->config();
        $collisions = self::collisions($discovery['entries']);

        $rows = [];
        foreach ($discovery['entries'] as [$service, $plugin]) {
            $rows[] = $this->row($service, $plugin, $config, $collisions[$service->name] ?? []);
        }

        return ['kernel' => $discovery['kernel'], 'services' => $rows];
    }

    /**
     * The declarations themselves, sorted by name — what the compose route projects. Colliding
     * declarations are in here too: refusing them is the caller's decision ({@see self::conflicts()}).
     *
     * @return list<ServiceDeclaration>
     */
    public function declarations(): array
    {
        return array_map(static fn (array $entry): ServiceDeclaration => $entry[0], $this->discover()['entries']);
    }

    /**
     * The service names more than one plugin declared, each with every plugin that declared it —
     * empty when every name is declared once.
     *
     * @return array<string, list<string>>
     */
    public function conflicts(): array
    {
        $conflicts = [];
        foreach (self::collisions($this->discover()['entries']) as $name => $plugins) {
            $conflicts[$name] = array_map(self::shortName(...), $plugins);
        }

        return $conflicts;
    }

    /** The app's config bag when the container carries one — where `configKey` values are read from. */
    public function config(): ?Config
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;

        return $config instanceof Config ? $config : null;
    }

    /**
     * @return array{kernel: bool, entries: list<array{0: ServiceDeclaration, 1: string}>}
     */
    private function discover(): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        $plugins = $kernel instanceof Kernel
            ? $kernel->plugins()
            : ($this->fallbackProvider !== null ? [$this->fallbackProvider] : []);

        $entries = [];
        foreach ($plugins as $plugin) {
            if (!$plugin instanceof StackProviderInterface) {
                continue;
            }
            foreach ($plugin->services() as $service) {
                if (!$service instanceof ServiceDeclaration) {
                    continue;
                }
                $entries[] = [$service, $plugin::class];
            }
        }
        usort($entries, static fn (array $a, array $b): int => [$a[0]->name, $a[1]] <=> [$b[0]->name, $b[1]]);

        return ['kernel' => $kernel instanceof Kernel, 'entries' => $entries];
    }

    /**
     * Service name → the plugin classes that declared it, in discovery order, only for names declared
     * more than once. A plugin that declares one name twice collides with itself.
     *
     * @param list<array{0: ServiceDeclaration, 1: string}> $entries
     *
     * @return array<string, list<string>>
     */
    private static function collisions(array $entries): array
    {
        $byName = [];
        foreach ($entries as [$service, $plugin]) {
            $byName[$service->name][] = $plugin;
        }

        return array_filter($byName, static fn (array $plugins): bool => \count($plugins) > 1);
    }

    /**
     * @param list<string> $collision every plugin class that declared this name, when more than one did
     *
     * @return array{name: string, image: string, ports: list<string>, env: list<array{name: string, source: string, display: string|null, configKey: string|null}>, volumes: list<string>, command: list<string>, summary: string, plugin: string, probeHost: string, probePort: int|null, state: string, conflictsWith: list<string>, compose: string}
     */
    private function row(ServiceDeclaration $service, string $plugin, ?Config $config, array $collision): array
    {
        $env = [];
        foreach ($service->env as $var) {
            $resolved = ResolvedEnv::of($var, $config);
            $env[] = [
                'name' => $resolved->name,
                'source' => $resolved->source,
                'display' => $resolved->value,
                'configKey' => $resolved->configKey,
            ];
        }

        $conflictsWith = self::others($collision, $plugin);
        $probePort = $service->probePort();
        $state = match (true) {
            $collision !== [] => self::CONFLICT,
            $probePort === null => 'unknown',
            default => $this->probe->reachable($probePort) ? 'up' : 'down',
        };

        return [
            'name' => $service->name,
            'image' => $service->image,
            'ports' => array_map(static fn (PortMapping $port): string => $port->toCompose(), $service->ports),
            'env' => $env,
            'volumes' => $service->volumes,
            'command' => $service->command,
            'summary' => $service->summary,
            'plugin' => self::shortName($plugin),
            'probeHost' => $this->probe->host(),
            'probePort' => $probePort,
            'state' => $state,
            'conflictsWith' => $conflictsWith,
            'compose' => $this->projection->yaml([$service], $config),
        ];
    }

    /**
     * The short names of every plugin in the collision but one occurrence of this row's own — so a
     * plugin colliding with itself still names itself once.
     *
     * @param list<string> $collision
     *
     * @return list<string>
     */
    private static function others(array $collision, string $plugin): array
    {
        $self = array_search($plugin, $collision, true);
        if ($self !== false) {
            unset($collision[$self]);
        }

        return array_values(array_map(self::shortName(...), $collision));
    }

    private static function shortName(string $class): string
    {
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }
}
