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

namespace Milpa\Admin\Rendering;

use Milpa\Admin\Components\SectionHeaderComponent;
use Milpa\Admin\Components\SidebarComponent;
use Milpa\Admin\I18n\Catalog;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Support\Html;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Paints the shell's own components — `admin-sidebar` and `admin-section-header` — as HTML on the `mui-*`
 * design classes, each closed with its signed state envelope like every Milpa component.
 *
 * The sidebar keeps the exact item markup of `milpa/live`'s `dashboard-sidebar` (`.mui-sidebar__item` with
 * its icon and label spans, `aria-current="page"` on the active one), so the design bundle and any guest
 * that matched the primitive's items keep working — and adds what the primitive lacks: one
 * `.mui-sidebar__section` per group, headed by the catalog's label (`ADMIN / APP / AGENT`; an unknown
 * group is its own name uppercased in its own alphabet — `año` reads `AÑO`), and the glyph a section
 * declared. A heading's id is positional (`<sidebar id>-group-<n>`), never derived from the group's name,
 * so two names that would sanitize alike (`my lab`, `my-lab`) never share an id.
 *
 * Every human-facing string comes from the {@see Catalog} the request's `ComponentContext` names by
 * locale — the shell always fills it, so the sidebar and the header answer in the language the page does.
 */
final class ShellHtmlRenderer implements ComponentRendererInterface
{
    /**
     * The gear a nested item's toggle shows.
     *
     * A character and not an icon font or an inline SVG, for the reason the section glyphs already
     * gave: the panel paints with no build step and no asset the design bundle does not already
     * carry. It is decorative — the accessible name comes from the catalog, on the summary.
     */
    private const string GEAR = '⚙';

    public function __construct(private readonly StateTransferCodecInterface $codec)
    {
    }

    /** HTML only — the panel is a web surface. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /**
     * Mounts (unless the request carries state) and paints the shell component the contract names.
     *
     * @throws \InvalidArgumentException for a component that is not one of the shell's two
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $name = $component::contract()->name;
        $state = $request->state ?? $component->mount($request->props, $request->context);
        $catalog = new Catalog($request->context->locale ?? Catalog::DEFAULT_LOCALE);

        $html = match ($name) {
            SidebarComponent::NAME => $this->sidebar($state, $catalog),
            SectionHeaderComponent::NAME => $this->header($state, $catalog),
            default => throw new \InvalidArgumentException(\sprintf(
                '%s renders %s and %s, not «%s».',
                self::class,
                SidebarComponent::NAME,
                SectionHeaderComponent::NAME,
                $name,
            )),
        };

        return new RenderResult(output: $html . $this->envelope($state), state: $state, format: RenderTarget::HTML);
    }

    /**
     * The sidebar: the brand linking to the panel's home, then one `role="group"` per sidebar group with its
     * heading and its items — the primitive's item markup, glyph included.
     */
    private function sidebar(StateSnapshot $state, Catalog $catalog): string
    {
        $id = $state->componentId;
        $active = (string) ($state->data['active'] ?? '');
        $brand = (string) ($state->meta['brand'] ?? '');
        $home = (string) ($state->meta['home'] ?? '#');
        $wordmark = (string) ($state->meta['wordmark'] ?? '');

        $out = '<nav ' . Html::attrs(['class' => 'mui-sidebar', 'id' => $id, 'aria-label' => $catalog->tr('nav.label'), 'data-milpa-component-id' => $id]) . '>'
            // THE VECTOR, NOT TYPE. This span held the panel's name as escaped TEXT, which is the one
            // thing the logo kit forbids: assembled from type, the grain floats between letters and
            // the `i` keeps its own dot, so the mark reads with two. The name stays as the accessible
            // label, which is what it was always good for (greenhouse decisions/0249).
            . '<a class="mui-sidebar__brand" href="' . Html::escape($home) . '" aria-label="' . Html::escape($brand) . '">'
            // NO URL, NO BROKEN IMAGE. A consumer that composes this sidebar without saying where the
            // vector lives gets the name, which is what it got before. An `<img src="">` would be a
            // broken image — a worse answer than the one this replaces, offered in the name of a rule
            // it could not follow anyway.
            . ($wordmark === ''
                ? '<span class="mui-sidebar__wordmark">' . Html::escape($brand) . '</span>'
                : '<img class="mui-sidebar__wordmark" src="' . Html::escape($wordmark) . '" alt="' . Html::escape($brand) . '" width="2407" height="900">')
            . '</a>'
            . '<div class="mui-sidebar__nav">';

        $position = 0;
        foreach (\is_array($state->meta['groups'] ?? null) ? $state->meta['groups'] : [] as $group) {
            if (!\is_array($group)) {
                continue;
            }
            $key = (string) ($group['key'] ?? '');
            $labelId = $id . '-group-' . $position++;
            $out .= '<div ' . Html::attrs(['class' => 'mui-sidebar__section', 'role' => 'group', 'aria-labelledby' => $labelId, 'data-group' => $key]) . '>'
                . '<span class="mui-sidebar__section-label" id="' . Html::escape($labelId) . '">' . Html::escape($this->groupLabel($key, $catalog)) . '</span>';
            foreach (\is_array($group['items'] ?? null) ? $group['items'] : [] as $item) {
                if (!\is_array($item)) {
                    continue;
                }
                $out .= $this->navItem($item, $active, $catalog);
            }
            $out .= '</div>';
        }

        return $out . '</div>' . $this->footer($state, $catalog) . '</nav>';
    }

    /**
     * One sidebar item, and its gear when sections were declared under it.
     *
     * The children are a DISCLOSURE and not a popover, because the nav they live in scrolls: an
     * absolutely positioned menu would be clipped by that `overflow-y: auto`, measured on the
     * primitive's own stylesheet. So only the small toggle is positioned into the row and the list
     * stays in flow (greenhouse decisions/0268).
     *
     * It is `<details>`, so there is NO JavaScript: the disclosure state is the browser's. That is
     * also why the toggle is not an Alpine component — a dynamic `x-data` per instance double-initialises
     * (greenhouse decisions/0191), and a disclosure does not need a framework to open.
     *
     * The item's own link stays a SIBLING of the summary rather than a child of it: nesting a link
     * inside the toggle makes one click ambiguous, and the person who wanted the section would be
     * opening its settings instead.
     *
     * @param array<string, mixed> $item
     */
    private function navItem(array $item, string $active, Catalog $catalog): string
    {
        $children = \is_array($item['children'] ?? null) ? $item['children'] : [];
        $link = \sprintf(
            '<a %s><span class="mui-sidebar__item-icon" aria-hidden="true">%s</span><span class="mui-sidebar__item-label">%s</span></a>',
            Html::attrs([
                'class' => 'mui-sidebar__item',
                'href' => (string) ($item['href'] ?? '#'),
                'aria-current' => (string) ($item['key'] ?? '') === $active ? 'page' : null,
            ]),
            Html::escape((string) ($item['icon'] ?? '')),
            Html::escape((string) ($item['label'] ?? $item['key'] ?? '')),
        );
        if ($children === []) {
            return $link;
        }

        $menu = '';
        $holdsActive = false;
        foreach ($children as $child) {
            if (!\is_array($child)) {
                continue;
            }
            $current = (string) ($child['key'] ?? '') === $active;
            $holdsActive = $holdsActive || $current;
            $menu .= \sprintf(
                '<a %s><span class="mui-sidebar__item-icon" aria-hidden="true">%s</span><span class="mui-sidebar__item-label">%s</span></a>',
                Html::attrs([
                    'class' => 'mui-sidebar__subitem',
                    'href' => (string) ($child['href'] ?? '#'),
                    'aria-current' => $current ? 'page' : null,
                ]),
                Html::escape((string) ($child['icon'] ?? '')),
                Html::escape((string) ($child['label'] ?? $child['key'] ?? '')),
            );
        }
        // OPEN WHEN THE PERSON IS ALREADY INSIDE IT. A collapsed gear hiding the page you are looking
        // at answers «where am I» with nothing.
        $label = $catalog->tr('nav.gear');

        return '<div class="mui-sidebar__nest">'
            . $link
            . '<details ' . Html::attrs(['class' => 'mui-sidebar__more', 'open' => $holdsActive ? 'open' : null]) . '>'
            . '<summary ' . Html::attrs(['title' => $label, 'aria-label' => $label]) . '>' . self::GEAR . '</summary>'
            . '<div class="mui-sidebar__nest-menu">' . $menu . '</div>'
            . '</details>'
            . '</div>';
    }

    /**
     * THE FOOTER: WHAT THIS PANEL IS RUNNING.
     *
     * A person looking at a panel could not tell which version of it they were looking at, and «which
     * admin am I on» is the first question a bug report needs answered. The primitive has always had
     * the slot; nobody filled it (greenhouse decisions/0269).
     *
     * Two rows, not the whole lock: the app's foundation and the panel you are in. The rest is a count
     * that LINKS to the section that lists every one — the House section already reads every row and
     * painted only the total, so the «and the rest» has somewhere to go instead of a second list here.
     * Nothing at all when the lock could not be read, which is the honest empty rather than a footer
     * that says «unknown».
     */
    private function footer(StateSnapshot $state, Catalog $catalog): string
    {
        $rows = \is_array($state->meta['versions'] ?? null) ? $state->meta['versions'] : [];
        if ($rows === []) {
            return '';
        }
        $href = (string) ($state->meta['versionsHref'] ?? '');

        $out = '<div class="mui-sidebar__footer">';
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $out .= '<p class="mui-sidebar__version">'
                . '<span class="mui-sidebar__version-name">' . Html::escape((string) ($row['name'] ?? '')) . '</span>'
                . '<span class="mui-sidebar__version-value">' . Html::escape((string) ($row['version'] ?? '')) . '</span>'
                . '</p>';
        }
        $rest = (int) ($state->meta['versionsRest'] ?? 0);
        if ($rest > 0) {
            $label = $catalog->tr('nav.versions.rest', (string) $rest);
            $out .= $href === ''
                ? '<p class="mui-sidebar__version-more">' . Html::escape($label) . '</p>'
                : '<a class="mui-sidebar__version-more" href="' . Html::escape($href) . '">' . Html::escape($label) . '</a>';
        }

        return $out . '</div>';
    }

    /** A group's heading: the catalog's when it knows the group, the raw name uppercased in its own alphabet when it does not. */
    private function groupLabel(string $group, Catalog $catalog): string
    {
        $key = 'nav.group.' . $group;

        return $catalog->has($key) ? $catalog->tr($key) : mb_strtoupper($group, 'UTF-8');
    }

    /**
     * The section header: the title, and — when the catalogue named a declaring plugin — the attribution
     * line, the short class name shown and the full one in `data-declared-by`.
     */
    private function header(StateSnapshot $state, Catalog $catalog): string
    {
        $id = $state->componentId;
        $title = (string) ($state->data['title'] ?? '');
        $declaredBy = self::className((string) ($state->data['declaredBy'] ?? ''));

        $attribution = $declaredBy === ''
            ? ''
            : '<span class="admin-section__declared" data-declared-by="' . Html::escape($declaredBy) . '">'
                . Html::escape($catalog->tr('section.declared_by', self::shortName($declaredBy)))
                . '</span>';

        return '<header ' . Html::attrs(['class' => 'mui-page-header admin-section__header', 'id' => $id, 'data-milpa-component-id' => $id]) . '>'
            . '<div class="mui-page-header__text">'
            . '<h1 class="mui-page-header__title">' . Html::escape($title) . '</h1>'
            . $attribution
            . '</div>'
            . '</header>';
    }

    /** A class name as something a reader can cite: an anonymous class's name is cut before the NUL and the path PHP appends. */
    private static function className(string $class): string
    {
        $cut = strstr($class, "\0", true);

        return $cut === false ? $class : $cut;
    }

    /** The short name of a class — what comes after the last namespace separator. */
    private static function shortName(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . Html::escape($state->componentId) . '">'
            . $this->codec->encodeState($state)
            . '</script>';
    }
}
