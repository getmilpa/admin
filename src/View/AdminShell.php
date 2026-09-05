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

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Components\ComponentBook;
use Milpa\Admin\Components\SectionHeaderComponent;
use Milpa\Admin\Components\SidebarComponent;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Admin\Section\SectionRender;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\ValueObjects\ComponentContext;

/**
 * The panel's shell, composed — not hand-written — from Milpa Components.
 *
 * `<milpa:dashboard-shell>` holds the panel's own sidebar (`admin-sidebar`: one group per distinct section
 * group — ADMIN, APP, AGENT, then any other — each item with its glyph), a topbar (the active section's
 * title, and the chips: who signed in when a gate authenticated the request, the gate in effect, the locale)
 * and a main region carrying the section header (`admin-section-header`: the title and «declared by
 * <Plugin>», read from the catalogue) above the active section's component, wrapped in the section body
 * (`.admin-section__body` — the shell's, not the section's: the part of main that scrolls, and the box a
 * section fills when it wants the remaining height), all compiled by `XhtmlComponentCompiler` over the
 * {@see ComponentBook}.
 * Two lifecycle pairs make it extensible without touching it: `admin.section.before_render`/`after_render`
 * (the section's props, then its HTML) and `admin.shell.before_render`/`after_render` (the composition
 * and items, then the HTML).
 *
 * What every section receives (greenhouse decisions/0210): the {@see ComponentContext} it mounts with
 * carries the `principal` the gate authenticated — the same actor id the topbar shows, null when nobody —
 * the `locale` the page answers in and the panel's `route`, so a section decides its own state by what
 * the host knows and agrees with the topbar; and the request's query params reach it as `props['query']`
 * (decisions/0205) — a drill-down, a filter — without the shell knowing what they mean.
 */
final class AdminShell
{
    public const COMPONENT_ID = 'milpa-admin';
    public const BEFORE_RENDER = 'admin.shell.before_render';
    public const AFTER_RENDER = 'admin.shell.after_render';
    public const SECTION_BEFORE_RENDER = 'admin.section.before_render';
    public const SECTION_AFTER_RENDER = 'admin.section.after_render';

    public function __construct(
        private readonly AdminSettings $settings,
        private Catalog $catalog,
        private readonly StateTransferCodecInterface $codec,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
    }

    /** The same shell answering in another catalog — a request's `?lang=` — with everything else shared. */
    public function withCatalog(Catalog $catalog): self
    {
        $shell = clone $this;
        $shell->catalog = $catalog;

        return $shell;
    }

    /**
     * Renders the shell with every discovered section in the sidebar, under its group, and the active one in
     * main under its header.
     *
     * @param array<string, mixed> $query     the request's query params, handed to the active section as `props['query']`
     * @param string|null          $principal who the gate let in — the authenticated actor's id, never a session id — or null when nobody is signed in;
     *                                        handed to every component's `ComponentContext`, the active section's included
     */
    public function render(SectionCatalogue $catalogue, AdminSection $active, array $query = [], ?string $principal = null): string
    {
        $book = new ComponentBook($this->codec, $this->events);
        foreach ($catalogue->sections() as $section) {
            $book->adopt($section);
        }

        $context = $this->context($principal);
        $sectionHtml = $this->renderSection($book, $active, $context, $query);
        $headerHtml = $this->renderHeader($book, $active, $catalogue->declaredBy($active->id), $context);

        $shell = new ShellRender(
            markup: $this->composition($active),
            items: $this->navItems($catalogue),
        );
        $this->events?->dispatch(self::BEFORE_RENDER, ['shell' => $shell]);

        $defaults = [
            SidebarComponent::NAME => ['items' => $shell->items],
            // A subscriber that swapped the primitive into the composition still gets the items.
            'dashboard-sidebar' => ['items' => $shell->items],
            'dashboard-topbar' => ['childrenHtml' => $this->chips($principal)],
            'dashboard-main' => ['childrenHtml' => $headerHtml . "\n" . self::body($sectionHtml, self::COMPONENT_ID . '-header')],
        ];
        $shell->html = $book->compiler($defaults)->compile($shell->markup, $context)->output;
        $this->events?->dispatch(self::AFTER_RENDER, ['shell' => $shell]);

        return $shell->html;
    }

    /**
     * The empty-state body when no plugin declared a section — still inside the shell, chips included.
     *
     * @param string|null $principal who the gate let in, or null when nobody is signed in
     */
    public function renderEmpty(SectionCatalogue $catalogue, ?string $principal = null): string
    {
        $book = new ComponentBook($this->codec, $this->events);
        $markup = \sprintf(
            '<milpa:dashboard-shell id="%1$s" main-id="%1$s-main">'
            . '<milpa:%3$s id="%1$s-sidebar" brand="%2$s" home="%4$s"/>'
            . '<milpa:dashboard-topbar id="%1$s-topbar" title="%2$s" controls="%1$s-sidebar"/>'
            . '<milpa:dashboard-main id="%1$s-main"/>'
            . '</milpa:dashboard-shell>',
            self::COMPONENT_ID,
            self::attr($this->settings->title),
            SidebarComponent::NAME,
            self::attr($this->settings->route),
        );
        $notice = '<p class="mui-alert mui-alert--info admin-notice">' . htmlspecialchars($this->catalog->tr('section.none'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        $defaults = [
            SidebarComponent::NAME => ['items' => []],
            'dashboard-topbar' => ['childrenHtml' => $this->chips($principal)],
            'dashboard-main' => ['childrenHtml' => self::body($notice)],
        ];

        return $book->compiler($defaults)->compile($markup, $this->context($principal))->output;
    }

    /**
     * The section body: the shell's wrapper around whatever main carries under the header — the active
     * section's HTML as its subscribers left it, or the empty state's notice. Main is a column; this is the
     * part of it that scrolls (the page's stylesheet, {@see AdminPage}) — and a scroller the keyboard can
     * reach, the way the design bundle's `.mui-table-wrap` is: `tabindex="0"` and a region named by the
     * section header above it. The empty state has no header and nothing to scroll: a plain wrapper.
     *
     * @param string|null $labelledBy the id of the header that names the region, null for the empty state
     */
    private static function body(string $html, ?string $labelledBy = null): string
    {
        $region = $labelledBy === null ? '' : ' tabindex="0" role="region" aria-labelledby="' . self::attr($labelledBy) . '"';

        return '<div class="admin-section__body"' . $region . '>' . $html . '</div>';
    }

    /** The title the panel shows for a section: a catalog key when it knows it, the literal otherwise. */
    public function title(AdminSection $section): string
    {
        return $this->catalog->has($section->title) ? $this->catalog->tr($section->title) : $section->title;
    }

    /**
     * The context every component of this page mounts with — the compiler copies it to each node under
     * that node's own component id: the principal the gate authenticated, the locale, the panel's route.
     */
    private function context(?string $principal): ComponentContext
    {
        return new ComponentContext(
            componentId: self::COMPONENT_ID,
            principal: $principal,
            locale: $this->catalog->locale(),
            route: $this->settings->route,
        );
    }

    /**
     * The active section's HTML, its declared props plus the request's query under `query` — the one prop
     * every section gets from the shell, open to `admin.section.before_render` like the rest.
     *
     * @param array<string, mixed> $query
     */
    private function renderSection(ComponentBook $book, AdminSection $active, ComponentContext $context, array $query): string
    {
        $subject = new SectionRender($active, [...$active->props, 'query' => $query]);
        $this->events?->dispatch(self::SECTION_BEFORE_RENDER, ['section' => $subject]);

        $markup = \sprintf(
            '<milpa:%s id="%s-section-%s"/>',
            $active->component,
            self::COMPONENT_ID,
            self::attr($active->id),
        );
        $subject->html = $book
            ->compiler([$active->component => $subject->props])
            ->compileFragment($markup, $context)
            ->output;
        $this->events?->dispatch(self::SECTION_AFTER_RENDER, ['section' => $subject]);

        return $subject->html;
    }

    /**
     * The host's header above the section: its title, and the plugin the catalogue says declared it — the
     * section never names itself. The declaring class travels as a prop, never through the markup, so a
     * name nobody can cite (an anonymous class) breaks nothing.
     */
    private function renderHeader(ComponentBook $book, AdminSection $active, ?string $declaredBy, ComponentContext $context): string
    {
        $markup = \sprintf('<milpa:%s id="%s-header"/>', SectionHeaderComponent::NAME, self::COMPONENT_ID);

        return $book
            ->compiler([SectionHeaderComponent::NAME => ['title' => $this->title($active), 'declaredBy' => (string) $declaredBy]])
            ->compileFragment($markup, $context)
            ->output;
    }

    private function composition(AdminSection $active): string
    {
        return \sprintf(
            '<milpa:dashboard-shell id="%1$s" title="%2$s" main-id="%1$s-main">'
            . '<milpa:%5$s id="%1$s-sidebar" brand="%3$s" home="%6$s" active="%4$s"/>'
            . '<milpa:dashboard-topbar id="%1$s-topbar" title="%2$s" controls="%1$s-sidebar"/>'
            . '<milpa:dashboard-main id="%1$s-main"/>'
            . '</milpa:dashboard-shell>',
            self::COMPONENT_ID,
            self::attr($this->title($active)),
            self::attr($this->settings->title),
            self::attr($active->id),
            SidebarComponent::NAME,
            self::attr($this->settings->route),
        );
    }

    /**
     * The topbar's end slot: who signed in (`signed in as <actor id>` — only when a gate authenticated
     * the request; the panel invents no identity — the whole of it in `title` too, because a passkey id is
     * longer than the chip the stylesheet lets it have and the ellipsis must be hoverable), the gate in
     * effect (`gate: loopback | custom | passkey | open | fallback` — a fallback wears the warning badge,
     * because a misdeclared gate is something to fix) and the locale answering.
     */
    private function chips(?string $principal): string
    {
        $label = $this->settings->gateLabel();
        $gateClass = 'mui-badge admin-chip admin-chip--gate' . ($this->settings->gateKind() === AdminSettings::GATE_FALLBACK ? ' mui-badge--warning' : '');
        $locale = $this->catalog->locale();
        $signedIn = $principal === null
            ? ''
            : '<span class="mui-badge admin-chip admin-chip--principal" title="' . self::attr($this->catalog->tr('topbar.signed_in', $principal)) . '" data-principal="' . self::attr($principal) . '">'
                . self::attr($this->catalog->tr('topbar.signed_in', $principal))
                . '</span>';

        return $signedIn
            . '<span class="' . $gateClass . '" data-gate="' . self::attr($label) . '">'
            . self::attr($this->catalog->tr('chip.gate', $this->catalog->tr('gate.kind.' . $label)))
            . '</span>'
            . '<span class="mui-badge admin-chip admin-chip--locale" data-locale="' . self::attr($locale) . '">'
            . self::attr($locale)
            . '</span>';
    }

    /**
     * One flat item per section, in the catalogue's order (`order`, then `id`), each naming its group — the
     * sidebar groups them when it mounts, so an item a subscriber adds names its group the same way.
     *
     * @return list<array{key: string, label: string, href: string, icon: string, group: string}>
     */
    private function navItems(SectionCatalogue $catalogue): array
    {
        $items = [];
        foreach ($catalogue->sections() as $section) {
            $items[] = [
                'key' => $section->id,
                'label' => $this->title($section),
                'href' => $this->settings->sectionUrl($section->id),
                'icon' => $section->icon,
                'group' => $section->group,
            ];
        }

        return $items;
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
