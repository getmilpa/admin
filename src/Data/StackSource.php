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
 * command, summary, the declaring plugin), the env with its values RESOLVED — a secret is masked here
 * and stays masked everywhere downstream —, the service's compose fragment, and its state: `up` when
 * the probe port accepts TCP on loopback, `down` when it refuses, `unknown` when the service publishes
 * no port to probe. Nothing here starts or stops anything (greenhouse decisions/0201).
 */
final class StackSource
{
    public const MASK = '●●●';
    public const UNSET = '(unset)';

    private readonly ComposeProjection $projection;

    /**
     * @param object|null $fallbackProvider a plugin to read when no kernel is in the container (the panel itself)
     */
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly ReachabilityProbe $probe,
        private readonly ?object $fallbackProvider = null,
    ) {
        $this->projection = new ComposeProjection();
    }

    /**
     * The services table, sorted by name, each with its resolved env, compose fragment and probed state.
     *
     * @return array{kernel: bool, services: list<array{name: string, image: string, ports: list<string>, env: list<array{name: string, source: string, display: string, configKey: string|null}>, volumes: list<string>, command: list<string>, summary: string, plugin: string, probePort: int|null, state: string, compose: string}>}
     */
    public function snapshot(): array
    {
        $discovery = $this->discover();
        $config = $this->config();

        $rows = [];
        foreach ($discovery['entries'] as [$service, $plugin]) {
            $rows[] = $this->row($service, $plugin, $config);
        }

        return ['kernel' => $discovery['kernel'], 'services' => $rows];
    }

    /**
     * The declarations themselves, sorted by name — what the compose route projects.
     *
     * @return list<ServiceDeclaration>
     */
    public function declarations(): array
    {
        return array_map(static fn (array $entry): ServiceDeclaration => $entry[0], $this->discover()['entries']);
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
     * @return array{name: string, image: string, ports: list<string>, env: list<array{name: string, source: string, display: string, configKey: string|null}>, volumes: list<string>, command: list<string>, summary: string, plugin: string, probePort: int|null, state: string, compose: string}
     */
    private function row(ServiceDeclaration $service, string $plugin, ?Config $config): array
    {
        $env = [];
        foreach ($service->env as $var) {
            $resolved = ResolvedEnv::of($var, $config);
            $env[] = [
                'name' => $resolved->name,
                'source' => $resolved->source,
                'display' => self::display($resolved),
                'configKey' => $resolved->configKey,
            ];
        }

        $probePort = $service->probePort();
        $state = $probePort === null ? 'unknown' : ($this->probe->reachable($probePort) ? 'up' : 'down');

        return [
            'name' => $service->name,
            'image' => $service->image,
            'ports' => array_map(static fn (PortMapping $port): string => $port->toCompose(), $service->ports),
            'env' => $env,
            'volumes' => $service->volumes,
            'command' => $service->command,
            'summary' => $service->summary,
            'plugin' => self::shortName($plugin),
            'probePort' => $probePort,
            'state' => $state,
            'compose' => $this->projection->yaml([$service], $config),
        ];
    }

    private static function display(ResolvedEnv $env): string
    {
        return match ($env->source) {
            ResolvedEnv::SECRET => self::MASK,
            ResolvedEnv::UNSET => self::UNSET,
            default => $env->value ?? self::UNSET,
        };
    }

    private static function shortName(string $class): string
    {
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }
}
