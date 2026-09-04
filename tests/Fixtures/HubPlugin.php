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
use Milpa\Runtime\Stack\EnvVar;
use Milpa\Runtime\Stack\PortMapping;
use Milpa\Runtime\Stack\ServiceDeclaration;
use Milpa\Runtime\Stack\StackProviderInterface;

/**
 * A foreign plugin that needs one backing service: a hub published on host port 3000, with a literal env,
 * one read from the app's config, and one secret that carries a value the panel must never show.
 */
#[PluginMetadata(version: '0.1.0', author: 'Test', site: 'https://example.test', name: 'Hub', type: 'Web')]
final class HubPlugin implements PluginInterface, StackProviderInterface
{
    public const SECRET = 'do-not-show-me';
    public const HOST_PORT = 3000;

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
                image: 'example/hub:1',
                ports: [new PortMapping(container: 80, host: self::HOST_PORT)],
                env: [
                    new EnvVar('SERVER_NAME', value: ':80'),
                    new EnvVar('HUB_PUBLIC_URL', configKey: 'hub.public_url'),
                    new EnvVar('HUB_JWT_KEY', value: self::SECRET, configKey: 'hub.key', secret: true),
                ],
                volumes: ['hub-data:/data'],
                summary: 'Pushes shell changes to the browser.',
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
