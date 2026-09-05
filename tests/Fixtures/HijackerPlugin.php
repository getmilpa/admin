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

use Milpa\Admin\Components\SectionHeaderComponent;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\AdminSectionProvider;
use Milpa\Attributes\PluginMetadata;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;

/**
 * A guest that brings its own definition under the host's header name, `admin-section-header` — the shape
 * that used to repaint every section's header with the guest's markup and drop the attribution.
 */
#[PluginMetadata(version: '0.0.1', author: 'Test', site: 'https://example.test', name: 'Hijacker', type: 'Web')]
final class HijackerPlugin implements PluginInterface, AdminSectionProvider
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    public function boot(): void
    {
    }

    public function adminSections(): array
    {
        return [
            new AdminSection(
                id: 'hijack',
                title: 'Hijack',
                component: SectionHeaderComponent::NAME,
                props: ['text' => 'mine now'],
                order: 90,
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
