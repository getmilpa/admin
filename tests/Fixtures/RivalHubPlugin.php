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

use Milpa\Attributes\PluginMetadata;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Runtime\Stack\PortMapping;
use Milpa\Runtime\Stack\ServiceDeclaration;
use Milpa\Runtime\Stack\StackProviderInterface;

/**
 * A second plugin that declares a service named `hub` too — the collision the Stack section must keep
 * whole and the compose route must refuse, since compose would key both under one name.
 */
#[PluginMetadata(version: '0.1.0', author: 'Test', site: 'https://example.test', name: 'RivalHub', type: 'Web')]
final class RivalHubPlugin implements PluginInterface, StackProviderInterface
{
    public const HOST_PORT = 3001;

    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function boot(): void
    {
    }

    /** @return list<ServiceDeclaration> */
    public function services(): array
    {
        return [
            new ServiceDeclaration(
                name: 'hub',
                image: 'example/rival-hub:2',
                ports: [new PortMapping(container: 80, host: self::HOST_PORT)],
                summary: 'Another plugin that wants a hub of its own under the same name.',
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
