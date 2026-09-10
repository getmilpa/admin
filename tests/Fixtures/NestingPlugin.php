<?php

/**
 * This file is part of milpa/admin.
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
use Milpa\Interfaces\Di\DIContainerInterface;

/**
 * A guest that declares a section AND a section under it — the shape the gear exists for.
 *
 * It implements the section contract ONLY: `SectionCatalogue::discover()` asks for
 * `AdminSectionProvider` and nothing else, so a fixture that also claimed to be a plugin would be
 * carrying a lifecycle this test never exercises.
 *
 * The child's order is LOWER than its parent's on purpose: it must still not win the panel's front
 * page, and it must not appear in the menu above the section it belongs to.
 */
final class NestingPlugin implements AdminSectionProvider
{
    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /** @return list<AdminSection> */
    public function adminSections(): array
    {
        return [
            new AdminSection(
                id: 'parent',
                title: 'Parent',
                component: 'metric-card',
                props: ['title' => 'Parent', 'value' => '1'],
                order: 60,
            ),
            new AdminSection(
                id: 'child',
                title: 'Child',
                component: 'metric-card',
                props: ['title' => 'Child', 'value' => '2'],
                order: 1,
                parent: 'parent',
            ),
        ];
    }
}
