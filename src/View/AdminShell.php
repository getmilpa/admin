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
 * `<milpa:dashboard-shell>` holds a sidebar (one item per discovered section), a topbar (the active
 * section's title, and the chips: who signed in when a gate authenticated the request, the gate in
 * effect, the locale) and a main region carrying the active section's component, all compiled by
 * `XhtmlComponentCompiler` over the {@see ComponentBook}.
 * Two lifecycle pairs make it extensible without touching it: `admin.section.before_render`/`after_render`
 * (the section's props, then its HTML) and `admin.shell.before_render`/`after_render` (the composition
 * and items, then the HTML). The locale travels in the {@see ComponentContext}, so a section's renderer
 * answers in the same language the shell does.
 *
 * One small rule for every section: the request's query params reach the active section as
 * `props['query']` — a section can read its own query (a drill-down, a filter) without the shell
 * knowing what it means (greenhouse decisions/0205).
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
     * Renders the shell with every discovered section in the sidebar and the active one in main.
     *
     * @param array<string, mixed> $query     the request's query params, handed to the active section as `props['query']`
     * @param string|null          $principal who the gate let in — the authenticated actor's id, never a session id — or null when nobody is signed in
     */
    public function render(SectionCatalogue $catalogue, AdminSection $active, array $query = [], ?string $principal = null): string
    {
        $book = new ComponentBook($this->codec, $this->events);
        foreach ($catalogue->sections() as $section) {
            $book->adopt($section);
        }

        $context = new ComponentContext(
            componentId: self::COMPONENT_ID,
            locale: $this->catalog->locale(),
            route: $this->settings->route,
        );

        $sectionHtml = $this->renderSection($book, $active, $context, $query);

        $shell = new ShellRender(
            markup: $this->composition($active),
            items: $this->navItems($catalogue, $active),
        );
        $this->events?->dispatch(self::BEFORE_RENDER, ['shell' => $shell]);

        $defaults = [
            'dashboard-sidebar' => ['items' => $shell->items],
            'dashboard-topbar' => ['childrenHtml' => $this->chips($principal)],
            'dashboard-main' => ['childrenHtml' => $sectionHtml],
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
        $context = new ComponentContext(componentId: self::COMPONENT_ID, locale: $this->catalog->locale(), route: $this->settings->route);
        $markup = \sprintf(
            '<milpa:dashboard-shell id="%1$s" main-id="%1$s-main">'
            . '<milpa:dashboard-sidebar id="%1$s-sidebar" brand="%2$s"/>'
            . '<milpa:dashboard-topbar id="%1$s-topbar" title="%2$s" controls="%1$s-sidebar"/>'
            . '<milpa:dashboard-main id="%1$s-main"/>'
            . '</milpa:dashboard-shell>',
            self::COMPONENT_ID,
            self::attr($this->settings->title),
        );
        $notice = '<p class="mui-alert mui-alert--info admin-notice">' . htmlspecialchars($this->catalog->tr('section.none'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        $defaults = [
            'dashboard-sidebar' => ['items' => []],
            'dashboard-topbar' => ['childrenHtml' => $this->chips($principal)],
            'dashboard-main' => ['childrenHtml' => $notice],
        ];

        return $book->compiler($defaults)->compile($markup, $context)->output;
    }

    /** The title the panel shows for a section: a catalog key when it knows it, the literal otherwise. */
    public function title(AdminSection $section): string
    {
        return $this->catalog->has($section->title) ? $this->catalog->tr($section->title) : $section->title;
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

    private function composition(AdminSection $active): string
    {
        return \sprintf(
            '<milpa:dashboard-shell id="%1$s" title="%2$s" main-id="%1$s-main">'
            . '<milpa:dashboard-sidebar id="%1$s-sidebar" brand="%3$s" active="%4$s"/>'
            . '<milpa:dashboard-topbar id="%1$s-topbar" title="%2$s" controls="%1$s-sidebar"/>'
            . '<milpa:dashboard-main id="%1$s-main"/>'
            . '</milpa:dashboard-shell>',
            self::COMPONENT_ID,
            self::attr($this->title($active)),
            self::attr($this->settings->title),
            self::attr($active->id),
        );
    }

    /**
     * The topbar's end slot: who signed in (`signed in as <actor id>` — only when a gate authenticated
     * the request; the panel invents no identity), the gate in effect (`gate: loopback | custom | passkey
     * | open | fallback` — a fallback wears the warning badge, because a misdeclared gate is something to
     * fix) and the locale answering.
     */
    private function chips(?string $principal): string
    {
        $label = $this->settings->gateLabel();
        $gateClass = 'mui-badge admin-chip admin-chip--gate' . ($this->settings->gateKind() === AdminSettings::GATE_FALLBACK ? ' mui-badge--warning' : '');
        $locale = $this->catalog->locale();
        $signedIn = $principal === null
            ? ''
            : '<span class="mui-badge admin-chip admin-chip--principal" data-principal="' . self::attr($principal) . '">'
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
     * @return list<array{key: string, label: string, href: string, icon: string}>
     */
    private function navItems(SectionCatalogue $catalogue, AdminSection $active): array
    {
        $items = [];
        foreach ($catalogue->sections() as $section) {
            $items[] = [
                'key' => $section->id,
                'label' => $this->title($section),
                'href' => $this->settings->sectionUrl($section->id),
                'icon' => $section->icon,
            ];
        }

        return $items;
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
