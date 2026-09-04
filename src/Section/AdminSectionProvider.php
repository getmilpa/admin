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

namespace Milpa\Admin\Section;

/**
 * The contract by which a plugin adds sections to the admin panel.
 *
 * The panel discovers implementers by `instanceof` over the booted plugins, at request time — boot
 * order does not matter, and the panel names no plugin. Its own sections (Plugins, Routes) enter
 * through this same interface: there is no privileged path.
 */
interface AdminSectionProvider
{
    /**
     * The sections this plugin contributes to the panel.
     *
     * @return list<AdminSection>
     */
    public function adminSections(): array;
}
