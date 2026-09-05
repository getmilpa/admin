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
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;

/**
 * A guest shaped like the first real one — the Desktop's Agent section (greenhouse decisions/0210): one
 * section under the `agent` group, with a glyph, at an order after the host's own (10..40), plus one under
 * a group the catalog does not know, to see the sidebar name it anyway.
 */
#[PluginMetadata(version: '0.1.0', author: 'Test', site: 'https://example.test', name: 'Guest', type: 'Web')]
final class GuestPlugin implements PluginInterface, AdminSectionProvider
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
                id: 'agent',
                title: 'Agent',
                component: 'metric-card',
                props: ['title' => 'Agent', 'value' => 'live', 'caption' => 'from GuestPlugin'],
                order: 60,
                group: AdminSection::GROUP_AGENT,
                icon: '◈',
            ),
            new AdminSection(
                id: 'lab',
                title: 'Lab',
                component: 'metric-card',
                props: ['title' => 'Lab', 'value' => '1'],
                order: 70,
                group: 'lab',
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
