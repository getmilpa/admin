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

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;

/**
 * One section of the admin panel, as a plugin declares it.
 *
 * A section IS a Milpa Component. It either names a component the panel already knows (the dashboard
 * primitives — `metric-card`, `data-table`, `dashboard-grid`… — rendered with `$props`), or it brings
 * its own: a `$definition` plus the `$renderer` that paints it, which the panel registers under
 * `$component` before composing. Either way the panel never learns the plugin's name — it learns the
 * section's, and that is enough to list it, route it and render it.
 */
final readonly class AdminSection
{
    public const ID_PATTERN = '/^[a-z][a-z0-9-]{0,40}$/';

    /**
     * @param string                            $id         the section's identity and URL segment (`^[a-z][a-z0-9-]{0,40}$`)
     * @param string                            $title      a catalog key the panel knows, or the literal title
     * @param string                            $component  the Milpa component name that renders the section
     * @param array<string, mixed>              $props      the props the component mounts with
     * @param int                               $order      sidebar position (lower first; ties break by id)
     * @param string                            $group      a nav group name — data for the sidebar, no semantics yet
     * @param ComponentDefinitionInterface|null $definition the component, when the section brings its own
     * @param ComponentRendererInterface|null   $renderer   the renderer for that component (required with it)
     * @param string                            $icon       a glyph for the sidebar item, optional
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $component,
        public array $props = [],
        public int $order = 0,
        public string $group = 'app',
        public ?ComponentDefinitionInterface $definition = null,
        public ?ComponentRendererInterface $renderer = null,
        public string $icon = '',
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Admin section id «%s» must match %s.', $id, self::ID_PATTERN));
        }
        if (trim($component) === '') {
            throw new \InvalidArgumentException(\sprintf('Admin section «%s» names no component.', $id));
        }
        if (($definition === null) !== ($renderer === null)) {
            throw new \InvalidArgumentException(\sprintf(
                'Admin section «%s» brings a custom component: give BOTH a definition and a renderer, or neither (a component the panel already registers).',
                $id,
            ));
        }
    }

    /** True when the section brings its own component instead of naming a registered one. */
    public function isCustom(): bool
    {
        return $this->definition !== null;
    }
}
