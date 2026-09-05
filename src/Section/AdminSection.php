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
 * section's, and that is enough to list it, route it, render it and attribute it: the header of every
 * section says «declared by <Plugin>», read from the catalogue, never from the section.
 *
 * What the panel hands the component: the `ComponentContext` it mounts with carries the section's
 * component id (`milpa-admin-section-<id>`), the `principal` the gate authenticated (the actor's id, or
 * null when nobody is signed in — the same fact the topbar shows), the `locale` the page answers in and
 * the panel's `route` (its mount point); the props are `$props` plus the request's query under `query`.
 *
 * One prop name is RESERVED: `query`. The shell hands every active section the request's query params
 * under `props['query']` (greenhouse decisions/0205), so a section that declared its own `query` would
 * see it silently replaced on every request — the constructor refuses it instead.
 *
 * The sidebar lists sections by `$group` — one heading per distinct value, `admin` (the panel's own)
 * first, then `app` (the default), then `agent`, then any other name in alphabetical order — and, within
 * a group, by `$order` (lower first; ties break by `$id`, alphabetically). The panel OPENS on the first
 * section in that same (`order`, `id`) order across every group, which is why the convention matters:
 * the panel's own sections take 10..40 (Plugins 10, Routes 20, Settings 25, Stack 30, Dev tools 40), and
 * a guest picks an order AFTER those — greenhouse decisions/0210 names 60 for the Desktop's Agent section —
 * unless it means to be the page the panel opens on. A guest at order 10 named `agent` would tie with
 * Plugins, win the tie by id, and become the front page.
 *
 * What a section may not do: bring its own `$definition` under a name the panel registers itself (a
 * dashboard primitive, `admin-sidebar`, `admin-section-header`) — naming one is fine, redefining one would
 * repaint every section that names it, so the panel refuses the section and says so.
 */
final readonly class AdminSection
{
    public const ID_PATTERN = '/^[a-z][a-z0-9-]{0,40}$/';

    /** The prop names the shell owns — a section may not declare them. */
    public const RESERVED_PROPS = ['query'];

    /** The sidebar group of the panel's own sections — listed first. */
    public const GROUP_ADMIN = 'admin';

    /** The default group — a plugin's sections — listed after the panel's own. */
    public const GROUP_APP = 'app';

    /** The group of the agent's own surfaces (the Desktop's Agent section) — listed after the app's. */
    public const GROUP_AGENT = 'agent';

    /**
     * @param string                            $id         the section's identity and URL segment (`^[a-z][a-z0-9-]{0,40}$`)
     * @param string                            $title      a catalog key the panel knows, or the literal title
     * @param string                            $component  the Milpa component name that renders the section
     * @param array<string, mixed>              $props      the props the component mounts with — never `query`, which is
     *                                                      reserved: the shell fills it with the request's query params
     * @param int                               $order      sidebar position within the group, and the panel's front page
     *                                                      across groups (lower first; ties break by id). The panel's own
     *                                                      are 10..40 — a guest picks an order after those
     * @param string                            $group      the sidebar group: {@see self::GROUP_ADMIN} (the panel's own),
     *                                                      {@see self::GROUP_APP} (default), {@see self::GROUP_AGENT}, or
     *                                                      any other name — one heading per distinct value, admin → app →
     *                                                      agent → others alphabetically; the heading comes from the catalog
     *                                                      when it knows the group, else the raw value uppercased
     * @param ComponentDefinitionInterface|null $definition the component, when the section brings its own
     * @param ComponentRendererInterface|null   $renderer   the renderer for that component (required with it)
     * @param string                            $icon       a glyph for the sidebar item, optional — painted before the label
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $component,
        public array $props = [],
        public int $order = 0,
        public string $group = self::GROUP_APP,
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
        foreach (self::RESERVED_PROPS as $reserved) {
            if (\array_key_exists($reserved, $props)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Admin section «%s» declares the prop «%s», which is reserved: the shell fills it with the request\'s query params on every render.',
                    $id,
                    $reserved,
                ));
            }
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
