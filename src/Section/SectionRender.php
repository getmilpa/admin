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
 * The mutable subject of a section's render lifecycle.
 *
 * Carried by `admin.section.before_render` (props are still open) and `admin.section.after_render`
 * (the HTML is still open). A subscriber changes what it finds; the panel renders what is left.
 * Milpa is event-driven: a section nobody can observe is a section nobody can extend.
 */
final class SectionRender
{
    /**
     * @param array<string, mixed> $props the props the component will mount with — mutable before render
     * @param string               $html  the rendered section — mutable after render
     */
    public function __construct(
        public readonly AdminSection $section,
        public array $props,
        public string $html = '',
    ) {
    }
}
