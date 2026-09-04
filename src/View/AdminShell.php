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
 * section's title) and a main region carrying the active section's component, all compiled by
 * `XhtmlComponentCompiler` over the {@see ComponentBook}. Two lifecycle pairs make it extensible without
 * touching it: `admin.section.before_render`/`after_render` (the section's props, then its HTML) and
 * `admin.shell.before_render`/`after_render` (the composition and items, then the HTML).
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
        private readonly Catalog $catalog,
        private readonly StateTransferCodecInterface $codec,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
    }

    /** Renders the shell with every discovered section in the sidebar and the active one in main. */
    public function render(SectionCatalogue $catalogue, AdminSection $active): string
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

        $sectionHtml = $this->renderSection($book, $active, $context);

        $shell = new ShellRender(
            markup: $this->composition($active),
            items: $this->navItems($catalogue, $active),
        );
        $this->events?->dispatch(self::BEFORE_RENDER, ['shell' => $shell]);

        $defaults = [
            'dashboard-sidebar' => ['items' => $shell->items],
            'dashboard-main' => ['childrenHtml' => $sectionHtml],
        ];
        $shell->html = $book->compiler($defaults)->compile($shell->markup, $context)->output;
        $this->events?->dispatch(self::AFTER_RENDER, ['shell' => $shell]);

        return $shell->html;
    }

    /** The empty-state body when no plugin declared a section — still inside the shell. */
    public function renderEmpty(SectionCatalogue $catalogue): string
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
            'dashboard-main' => ['childrenHtml' => $notice],
        ];

        return $book->compiler($defaults)->compile($markup, $context)->output;
    }

    /** The title the panel shows for a section: a catalog key when it knows it, the literal otherwise. */
    public function title(AdminSection $section): string
    {
        return $this->catalog->has($section->title) ? $this->catalog->tr($section->title) : $section->title;
    }

    private function renderSection(ComponentBook $book, AdminSection $active, ComponentContext $context): string
    {
        $subject = new SectionRender($active, $active->props);
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
