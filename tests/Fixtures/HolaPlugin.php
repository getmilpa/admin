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

use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\AdminSectionProvider;
use Milpa\Attributes\PluginMetadata;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Http\RouteProviderInterface;

/**
 * A foreign plugin the panel never heard of: it declares one route (with middleware) and two sections —
 * one on a dashboard primitive, one on a component it brings itself.
 */
#[PluginMetadata(version: '1.2.3', author: 'Test', site: 'https://example.test', name: 'Hola', type: 'Web')]
final class HolaPlugin implements PluginInterface, RouteProviderInterface, AdminSectionProvider
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function boot(): void
    {
    }

    public function routes(): array
    {
        return [
            new Route(
                path: '/hola',
                methods: HttpMethod::GET,
                name: 'hola',
                middleware: [\Milpa\Admin\Http\LoopbackOnlyMiddleware::class],
                handler: HandlerReference::method(self::class, 'boot'),
            ),
        ];
    }

    public function adminSections(): array
    {
        return [
            new AdminSection(
                id: 'hola',
                title: 'Hola',
                component: 'metric-card',
                props: ['title' => 'Saludos', 'value' => '42', 'caption' => 'from HolaPlugin'],
                order: 5,
                icon: '✦',
            ),
            new AdminSection(
                id: 'echo',
                title: 'Echo',
                component: EchoComponent::NAME,
                props: ['text' => 'hola desde un plugin ajeno'],
                order: 50,
                definition: new EchoComponent(),
                renderer: new EchoRenderer(),
            ),
        ];
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }
}
