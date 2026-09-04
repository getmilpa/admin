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
 */
final class ShellRender
{
    /**
     * @param string                                                              $markup the `<milpa:…>` composition — mutable before render
     * @param list<array{key: string, label: string, href: string, icon: string}> $items  the sidebar items — mutable before render
     * @param string                                                              $html   the rendered shell — mutable after render
     */
    public function __construct(
        public string $markup,
        public array $items,
        public string $html = '',
    ) {
    }
}
