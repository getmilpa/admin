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
 * A VIEW a section declares: a tree of Milpa components, the definitions and renderers it needs, and the
 * signals the page must seed for it — the plugin declares, the host reconciles (greenhouse decisions/0211).
 *
 * The narrow shape an {@see AdminSection} already had — one `component` plus its `definition` and
 * `renderer` — is this same thing said for one node. A view says it for a TREE: several components, in the
 * order the plugin wants them, each with its own props, each painted by the renderer the plugin brings.
 * Nothing else changes: the host still registers the names ({@see \Milpa\Admin\Components\ComponentBook}),
 * still refuses the ones it registers itself, still paints the section header above the tree, and still
 * emits ONE runtime for the whole page — every declaring renderer's `.css` and `.js`
 * ({@see \Milpa\Live\Contracts\Rendering\DeclaresClientAssets}) reach `LiveBoot::html()` merged and
 * deduplicated, so a module the guest shares with the host is loaded once.
 *
 * ```php
 * new AdminSection(
 *     id: 'agent',
 *     title: 'Agent',
 *     order: 60,
 *     group: AdminSection::GROUP_AGENT,
 *     view: new DeclaredView(
 *         markup: '<milpa:desktop-tabs id="agent-tabs"/><milpa:desktop-conversation id="agent-conversation"/>',
 *         definitions: ['desktop-tabs' => $tabs, 'desktop-conversation' => $conversation],
 *         renderers: ['desktop-tabs' => $renderer, 'desktop-conversation' => $renderer],
 *         props: ['desktop-conversation' => ['session' => $id]],
 *         signals: ['desktop.theme' => 'dark'],
 *     ),
 * );
 * ```
 *
 * **The markup.** Every ROOT of the tree is a Milpa element (`<milpa:name/>` or `<milpa-name/>`); ordinary
 * HTML is allowed INSIDE a component's node, as the compiler has always allowed it. Each root is compiled
 * on its own, so one that throws paints its failure inside its own region and the rest of the view — and
 * the whole panel around it — still stands (greenhouse decisions/0211, «contained errors»).
 *
 * **The names.** `definitions` and `renderers` are keyed by component name and must name exactly the same
 * set: a definition with no renderer cannot be painted, and a renderer for a name nobody defines is a
 * registration that would never resolve. A view may still NAME, in its markup, a component the panel
 * registers itself (`metric-card`, `dashboard-panel`…) without declaring it — naming is free, redefining
 * is refused.
 *
 * **The seeds.** `signals`, `persist` and `computed` are what this view needs in the page's three seed
 * tags. The host merges them with its own and emits each tag ONCE
 * ({@see \Milpa\Admin\View\LiveSeeds}); a key two declarers give DIFFERENT values is a conflict that names
 * both, never a silent last-one-wins.
 */
final readonly class DeclaredView
{
    /**
     * @param string                                      $markup      the component tree to compile — one or more `<milpa:…>` roots
     * @param array<string, ComponentDefinitionInterface> $definitions component name → the definition the plugin brings
     * @param array<string, ComponentRendererInterface>   $renderers   component name → the renderer that paints it; the same keys as `$definitions`
     * @param array<string, array<string, mixed>>         $props       component name → props merged UNDER the markup's own attributes
     * @param array<string, mixed>                        $signals     signal name → seed value for `#milpa-live-signals`
     * @param list<string>                                $persist     signal names the runtime must persist (`#milpa-live-persist`)
     * @param array<string, mixed>                        $computed    signal name → derivation for `#milpa-live-computed`
     *
     * @throws \InvalidArgumentException when the markup is empty, or the definitions and renderers do not
     *                                   name the same components, or a name or a persisted signal is blank
     */
    public function __construct(
        public string $markup,
        public array $definitions = [],
        public array $renderers = [],
        public array $props = [],
        public array $signals = [],
        public array $persist = [],
        public array $computed = [],
    ) {
        if (trim($markup) === '') {
            throw new \InvalidArgumentException('A declared view carries markup: a view with nothing to compile is not a view.');
        }
        foreach (array_keys($definitions) as $name) {
            self::assertName($name, 'component');
            if (!isset($renderers[$name])) {
                throw new \InvalidArgumentException(\sprintf(
                    'The declared view defines «%s» but brings no renderer for it: a definition nothing paints cannot be mounted.',
                    $name,
                ));
            }
        }
        foreach (array_keys($renderers) as $name) {
            if (!isset($definitions[$name])) {
                throw new \InvalidArgumentException(\sprintf(
                    'The declared view brings a renderer for «%s» but defines no such component: name the component the panel already registers, or bring its definition too.',
                    $name,
                ));
            }
        }
        foreach (array_keys($signals) as $name) {
            self::assertName($name, 'signal');
        }
        foreach (array_keys($computed) as $name) {
            self::assertName($name, 'computed signal');
        }
        foreach ($persist as $name) {
            self::assertName($name, 'persisted signal');
        }
    }

    /**
     * The component names this view brings — what the host registers under the section's own layer.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->definitions));
    }

    /** True when the view seeds nothing — the page's three tags carry only the host's own. */
    public function seedsNothing(): bool
    {
        return $this->signals === [] && $this->persist === [] && $this->computed === [];
    }

    private static function assertName(int|string $name, string $what): void
    {
        if (!\is_string($name) || trim($name) === '') {
            throw new \InvalidArgumentException(\sprintf('A declared view names every %s it brings; «%s» is not a name.', $what, (string) $name));
        }
    }
}
