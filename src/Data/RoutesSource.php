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

use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Http\RouteProviderInterface;
use Milpa\Runtime\Kernel;

/**
 * Every route the booted plugins declared, read from the same source the kernel mounts them from.
 *
 * `milpa/runtime` keeps the assembled table private (`Router::$routes`), but every route in it came
 * from a plugin's `RouteProviderInterface::routes()` — so the panel asks the plugins, exactly as the
 * boot strategy did. A plugin's per-route middleware (`Route::$middleware`) and its handler are part of
 * the declaration, which is why they are columns and not a second inspector.
 */
final class RoutesSource
{
    /**
     * @param object|null $fallbackProvider a plugin to read when no kernel is in the container (the panel itself)
     */
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly ?object $fallbackProvider = null,
    ) {
    }

    /**
     * The routes table, sorted by path then method.
     *
     * @return array{kernel: bool, routes: list<array{method: string, path: string, name: string, handler: string, middleware: list<string>, plugin: string}>}
     */
    public function snapshot(): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        $plugins = $kernel instanceof Kernel
            ? $kernel->plugins()
            : ($this->fallbackProvider !== null ? [$this->fallbackProvider] : []);

        $rows = [];
        foreach ($plugins as $plugin) {
            if (!$plugin instanceof RouteProviderInterface) {
                continue;
            }
            foreach ($plugin->routes() as $route) {
                $rows[] = self::row($route, $plugin::class);
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return ['kernel' => $kernel instanceof Kernel, 'routes' => $rows];
    }

    /**
     * @return array{method: string, path: string, name: string, handler: string, middleware: list<string>, plugin: string}
     */
    private static function row(Route $route, string $plugin): array
    {
        $methods = array_map(static fn (HttpMethod $method): string => $method->value, $route->methods);
        $handler = $route->handler;

        return [
            'method' => implode(' ', $methods),
            'path' => $route->path,
            'name' => (string) ($route->name ?? ''),
            'handler' => $handler === null ? '' : self::shortName($handler->controller) . '::' . $handler->method,
            'middleware' => array_map(self::shortName(...), array_values(array_filter($route->middleware, 'is_string'))),
            'plugin' => self::shortName($plugin),
        ];
    }

    private static function shortName(string $class): string
    {
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }
}
