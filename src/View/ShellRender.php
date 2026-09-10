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

namespace Milpa\Admin\View;

/**
 * The mutable subject of the shell's render lifecycle.
 *
 * `admin.shell.before_render` hands out the XHTML composition and the sidebar items before the
 * compiler runs; `admin.shell.after_render` hands out the HTML before the page wraps it.
 *
 * The items are flat: each carries the sidebar `group` it lists under (the section's, or `app` for an
 * item a subscriber adds without one) and the sidebar groups them when it mounts — so a subscriber adds
 * an item and names its group, and never rebuilds the groups.
 *
 * An item may also carry `children`: the sections declared UNDER it, which the sidebar paints behind a
 * gear instead of listing beside their parent. They travel with the item that owns them so a subscriber
 * can add or remove one without holding the catalogue (greenhouse decisions/0268).
 */
final class ShellRender
{
    /**
     * @param string                                                                                                                                                              $markup the `<milpa:…>` composition — mutable before render
     * @param list<array{key: string, label: string, href: string, icon: string, group?: string, children?: list<array{key: string, label: string, href: string, icon: string}>}> $items  the sidebar items, in sidebar order — mutable before render
     * @param string                                                                                                                                                              $html   the rendered shell — mutable after render
     */
    public function __construct(
        public string $markup,
        public array $items,
        public string $html = '',
    ) {
    }
}
