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

        $out = '<nav ' . Html::attrs(['class' => 'mui-sidebar', 'id' => $id, 'aria-label' => $catalog->tr('nav.label'), 'data-milpa-component-id' => $id]) . '>'
            . '<a class="mui-sidebar__brand" href="' . Html::escape($home) . '"><span class="mui-sidebar__wordmark">' . Html::escape($brand) . '</span></a>'
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
                $out .= \sprintf(
                    '<a %s><span class="mui-sidebar__item-icon" aria-hidden="true">%s</span><span class="mui-sidebar__item-label">%s</span></a>',
                    Html::attrs([
                        'class' => 'mui-sidebar__item',
                        'href' => (string) ($item['href'] ?? '#'),
                        'aria-current' => (string) ($item['key'] ?? '') === $active ? 'page' : null,
                    ]),
                    Html::escape((string) ($item['icon'] ?? '')),
                    Html::escape((string) ($item['label'] ?? $item['key'] ?? '')),
                );
            }
            $out .= '</div>';
        }

        return $out . '</div></nav>';
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
