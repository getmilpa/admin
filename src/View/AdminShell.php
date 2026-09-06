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
use Milpa\Admin\Section\DeclaredView;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Admin\Section\SectionRender;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\ValueObjects\ClientAssets;
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
 *
 * A section may also declare a whole {@see DeclaredView} instead of one component (greenhouse
 * decisions/0211): then the shell compiles the guest's tree in the same place, ROOT BY ROOT, so a
 * component that throws while mounting paints its failure inside its own region and the panel around it
 * stands ({@see ViewMarkup}); it collects what every rendered renderer DECLARED, so the document emits
 * each stylesheet and module once through `LiveBoot`; and it merges the view's signal seeds with the
 * panel's own ({@see LiveSeeds}). Host facts a guest needs but no contract carries travel in
 * `ComponentContext::$meta`: {@see self::META_GATE}, {@see self::META_SECTION}, {@see self::META_QUERY}.
 *
 * {@see self::compose()} returns all three (HTML, assets, seeds); {@see self::render()} is the same call
 * when only the HTML is wanted.
 */
final class AdminShell
{
    public const COMPONENT_ID = 'milpa-admin';
    public const BEFORE_RENDER = 'admin.shell.before_render';
    public const AFTER_RENDER = 'admin.shell.after_render';
    public const SECTION_BEFORE_RENDER = 'admin.section.before_render';
    public const SECTION_AFTER_RENDER = 'admin.section.after_render';

    /** `ComponentContext::$meta`: the gate in effect, as the topbar chip names it (`loopback`|`custom`|`passkey`|`open`|`fallback`). */
    public const META_GATE = 'gate';

    /** `ComponentContext::$meta`: the id of the section the panel is showing. */
    public const META_SECTION = 'section';

    /** `ComponentContext::$meta`: the request's query params — how a declared view reads what `props['query']` gives the narrow shape. */
    public const META_QUERY = 'query';

    /** The signal the panel seeds with the active section's id — the host's own contribution to the page seeds. */
    public const SIGNAL_SECTION = 'admin.section';

    /** The signal the panel seeds with the gate in effect. */
    public const SIGNAL_GATE = 'admin.gate';

    /** The signal the panel seeds with the locale the page answers in. */
    public const SIGNAL_LOCALE = 'admin.locale';

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
        return $this->compose($catalogue, $active, $query, $principal)->html;
    }

    /**
     * The whole composed page: the shell's HTML, the client files every rendered component declared, and
     * the seeds the panel and the active section's view agreed on — what {@see AdminPage} needs to emit
     * ONE runtime for the page (greenhouse decisions/0211).
     *
     * @param array<string, mixed> $query     the request's query params, handed to the active section as `props['query']` and to every node as `meta['query']`
     * @param string|null          $principal who the gate let in — the authenticated actor's id, never a session id — or null when nobody is signed in
     */
    public function compose(SectionCatalogue $catalogue, AdminSection $active, array $query = [], ?string $principal = null): ShellOutput
    {
        $book = ComponentBook::forSections($catalogue, $this->codec, $this->events);

        $context = $this->context($principal, $active->id, $query);
        $assets = ClientAssets::empty();
        $sectionHtml = $active->view instanceof DeclaredView
            ? $this->renderView($book, $active, $active->view, $context, $assets)
            : $this->renderSection($book, $active, $context, $query, $assets);
        $headerHtml = $this->renderHeader($book, $active, $catalogue->declaredBy($active->id), $context, $assets);

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
        $compiled = $book->compiler($defaults)->compile($shell->markup, $context);
        $assets = $assets->merge($compiled->clientAssets());
        $shell->html = $compiled->output;
        $this->events?->dispatch(self::AFTER_RENDER, ['shell' => $shell]);

        return new ShellOutput($shell->html, $assets, $this->seeds($active));
    }

    /**
     * The empty-state body when no plugin declared a section — still inside the shell, chips included.
     *
     * @param string|null $principal who the gate let in, or null when nobody is signed in
     */
    public function renderEmpty(SectionCatalogue $catalogue, ?string $principal = null): string
    {
        return $this->composeEmpty($catalogue, $principal)->html;
    }

    /**
     * The empty state, composed like any other page — its assets (the primitives declare none today) and
     * the panel's own seeds, so a panel with no section still emits exactly one runtime.
     *
     * @param string|null $principal who the gate let in, or null when nobody is signed in
     */
    public function composeEmpty(SectionCatalogue $catalogue, ?string $principal = null): ShellOutput
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

        $compiled = $book->compiler($defaults)->compile($markup, $this->context($principal, '', []));

        return new ShellOutput($compiled->output, $compiled->clientAssets(), $this->hostSeeds(''));
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
     * that node's own component id: the principal the gate authenticated, the locale, the panel's route,
     * and in `meta` the host facts a guest may need without widening any contract (greenhouse
     * decisions/0211) — the gate in effect, the active section's id, the request's query.
     *
     * @param array<string, mixed> $query
     */
    private function context(?string $principal, string $section, array $query): ComponentContext
    {
        return new ComponentContext(
            componentId: self::COMPONENT_ID,
            principal: $principal,
            locale: $this->catalog->locale(),
            route: $this->settings->route,
            meta: [
                self::META_GATE => $this->settings->gateLabel(),
                self::META_SECTION => $section,
                self::META_QUERY => $query,
            ],
        );
    }

    /**
     * The active section's HTML, its declared props plus the request's query under `query` — the one prop
     * every section gets from the shell, open to `admin.section.before_render` like the rest. Contained
     * like a view's node: a component that throws while mounting paints its failure and the panel stands.
     *
     * @param array<string, mixed> $query
     */
    private function renderSection(ComponentBook $book, AdminSection $active, ComponentContext $context, array $query, ClientAssets &$assets): string
    {
        $subject = new SectionRender($active, [...$active->props, 'query' => $query]);
        $this->events?->dispatch(self::SECTION_BEFORE_RENDER, ['section' => $subject]);

        $markup = \sprintf(
            '<milpa:%s id="%s-section-%s"/>',
            $active->component,
            self::COMPONENT_ID,
            self::attr($active->id),
        );
        $subject->html = $this->paint($book, $markup, $active->component, [$active->component => $subject->props], $context, $assets);
        $this->events?->dispatch(self::SECTION_AFTER_RENDER, ['section' => $subject]);

        return $subject->html;
    }

    /**
     * The tree a section declared, compiled here — one region of the panel, no frame, no second document
     * (greenhouse decisions/0211, retiring the iframe of 0210).
     *
     * Each ROOT of the view is compiled on its own ({@see ViewMarkup}) so a component that throws costs
     * its own region and nothing more; every declaring renderer's files are collected into `$assets` for
     * the document to emit once; and the view's props reach the compiler as defaults, still open to
     * `admin.section.before_render` — with a view, {@see SectionRender::$props} is the view's own
     * component-name → props map, not one component's props.
     */
    private function renderView(ComponentBook $book, AdminSection $active, DeclaredView $view, ComponentContext $context, ClientAssets &$assets): string
    {
        $subject = new SectionRender($active, $view->props);
        $this->events?->dispatch(self::SECTION_BEFORE_RENDER, ['section' => $subject]);

        /** @var array<string, array<string, mixed>> $defaults */
        $defaults = $subject->props;
        $html = [];
        try {
            $roots = ViewMarkup::roots($view->markup);
        } catch (\Throwable $broken) {
            return $this->failure($active->id, $broken);
        }
        foreach ($roots as $root) {
            $html[] = $this->paint($book, $root->markup, $root->name, $defaults, $context, $assets);
        }
        $subject->html = implode("\n", $html);
        $this->events?->dispatch(self::SECTION_AFTER_RENDER, ['section' => $subject]);

        return $subject->html;
    }

    /**
     * One node compiled, its failure contained: a component that throws while mounting or rendering paints
     * a small region naming it, and the page around it stands — never a 500 for the whole panel
     * (greenhouse decisions/0211). Whatever the node declared reaches `$assets` all the same.
     *
     * @param array<string, array<string, mixed>> $defaults
     */
    private function paint(ComponentBook $book, string $markup, string $component, array $defaults, ComponentContext $context, ClientAssets &$assets): string
    {
        try {
            $compiled = $book->compiler($defaults)->compileFragment($markup, $context);
        } catch (\Throwable $broken) {
            return $this->failure($component, $broken);
        }
        $assets = $assets->merge($compiled->clientAssets());

        return $compiled->output;
    }

    /**
     * The region a node that could not be rendered leaves behind: what failed, and why, in the panel's own
     * language and its own skin. The reason is the exception's message — the panel is behind a gate and a
     * developer reading it is the point; it is escaped like any other value, never markup.
     */
    private function failure(string $component, \Throwable $broken): string
    {
        return '<div class="mui-alert mui-alert--warning admin-section__failure" role="alert" data-failed-component="' . self::attr($component) . '">'
            . '<strong>' . self::attr($this->catalog->tr('view.failed', $component)) . '</strong> '
            . '<span class="admin-section__failure-why">' . self::attr($this->catalog->tr('view.failed.why', $broken->getMessage())) . '</span>'
            . '</div>';
    }

    /**
     * The page's seeds: the panel's own, merged with the active section's view — the only view the page
     * mounts. A key both declare with different values is a {@see \Milpa\Admin\Section\SeedConflictException}
     * naming both, never a silent last-one-wins.
     */
    private function seeds(AdminSection $active): LiveSeeds
    {
        $seeds = $this->hostSeeds($active->id);
        if ($active->view === null || $active->view->seedsNothing()) {
            return $seeds;
        }

        return $seeds->merge(LiveSeeds::of(
            'section «' . $active->id . '»',
            $active->view->signals,
            $active->view->persist,
            $active->view->computed,
        ));
    }

    /** What the panel itself seeds on every page: which section is open, which gate let the reader in, which locale answers. */
    private function hostSeeds(string $section): LiveSeeds
    {
        return LiveSeeds::of(ComponentBook::HOST_LAYER, [
            self::SIGNAL_SECTION => $section,
            self::SIGNAL_GATE => $this->settings->gateLabel(),
            self::SIGNAL_LOCALE => $this->catalog->locale(),
        ]);
    }

    /**
     * The host's header above the section: its title, and the plugin the catalogue says declared it — the
     * section never names itself. The declaring class travels as a prop, never through the markup, so a
     * name nobody can cite (an anonymous class) breaks nothing.
     */
    private function renderHeader(ComponentBook $book, AdminSection $active, ?string $declaredBy, ComponentContext $context, ClientAssets &$assets): string
    {
        $markup = \sprintf('<milpa:%s id="%s-header"/>', SectionHeaderComponent::NAME, self::COMPONENT_ID);
        $compiled = $book
            ->compiler([SectionHeaderComponent::NAME => ['title' => $this->title($active), 'declaredBy' => (string) $declaredBy]])
            ->compileFragment($markup, $context);
        $assets = $assets->merge($compiled->clientAssets());

        return $compiled->output;
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
