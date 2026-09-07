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

namespace Milpa\Admin\Tui;

use Milpa\Admin\Section\AdminSection;
use Milpa\Console\State\SectionStateProvider;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Component\ComponentRegistryInterface;
use Milpa\Live\ValueObjects\ComponentContext;

/**
 * One admin section's state, read the way any surface reads it — by MOUNTING what the section declared.
 *
 * `decisions/0220` says the panel is one and has surfaces. This is the whole cost of the second one: a
 * section declares its component once, for the panel, and the terminal mounts that same declaration and
 * reads the state it returns. Nothing is declared twice, no section knows a terminal exists, and no
 * renderer is involved — the state IS the picture, and `milpa/live-tui`'s `StateToNode` turns any state
 * array into a node tree.
 *
 * ── TOTAL OVER THE THREE SHAPES A SECTION CAN HAVE ──────────────────────────────────────────────────
 *
 * A section brings a `$definition`; or a whole `$view` of them (greenhouse decisions/0211), whose state
 * is each component's under its own name; or it merely NAMES a component the panel registers, which is
 * resolved from the registry the panel composes with. A shape nothing can mount says so in its own
 * state rather than disappearing: a section missing from the dashboard reads as a section that does not
 * exist, which is the one thing worse than a section that says what is wrong with it.
 *
 * Mounting is LAZY and repeated: `state()` is called once per frame, so what the terminal shows is what
 * the app is now — a dashboard that froze what it read when it opened is a screenshot.
 */
final readonly class SectionState implements SectionStateProvider
{
    /** The key a section reports under when the terminal could not mount what it declared. */
    public const ERROR_KEY = 'error';

    /**
     * The `meta` keys the HOST put there, not the component — dropped, because they are plumbing.
     *
     * A `StateSnapshot` splits `data` (what changes) from `meta` (what names it), and reading only `data`
     * drops exactly the human half: a `metric-card` reports `value: live` and never says what it measures,
     * because its title lives in `meta`. So both are read — minus the four keys every component receives
     * from its {@see ComponentContext} whatever it declares, which say nothing about the section.
     */
    private const HOST_META = ['id', 'route', 'principal', 'locale'];

    /**
     * @param ComponentRegistryInterface|null $components what the panel composes with, or null when the book
     *                                                    could not be built — a section that names one of those
     *                                                    components then reports why instead of vanishing
     */
    public function __construct(
        private AdminSection $section,
        private ?ComponentRegistryInterface $components,
        private string $route,
        private ?string $unavailable = null,
    ) {
    }

    /**
     * The section's state right now — mounted on the spot, never remembered.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        try {
            return $this->read();
        } catch (\Throwable $failed) {
            // A component that throws while mounting paints its failure inside its own region and leaves
            // the panel standing — the HTML shell's rule (AdminShell), said for the terminal.
            return [self::ERROR_KEY => $failed->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $view = $this->section->view;
        if ($view !== null) {
            $state = [];
            foreach ($view->definitions as $name => $definition) {
                $state[(string) $name] = $this->mount($definition, (string) $name, $view->props[$name] ?? []);
            }

            return $state;
        }

        $definition = $this->section->definition ?? $this->registered();

        return $this->mount($definition, $this->section->component, $this->section->props);
    }

    /** The definition of a component the section only NAMED, resolved from what the panel composes with. */
    private function registered(): ComponentDefinitionInterface
    {
        if ($this->components === null) {
            throw new \RuntimeException($this->unavailable ?? \sprintf(
                'Section «%s» names component «%s», and the panel\'s components could not be resolved.',
                $this->section->id,
                $this->section->component,
            ));
        }

        return $this->components->get($this->section->component);
    }

    /**
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    private function mount(ComponentDefinitionInterface $definition, string $name, array $props): array
    {
        $state = $definition->mount($props, new ComponentContext(
            componentId: 'milpa-admin-section-' . $this->section->id . ($name === '' ? '' : '-' . $name),
            route: $this->route,
        ));

        $named = $state->data;
        foreach ($state->meta as $key => $value) {
            // An empty LABEL is not information — it is a row that costs a line and says nothing. The
            // panel's own sections mount with no title (their header component paints it, not them), so
            // without this every section opens with a blank `title` line above its data.
            if ($value === '' || \in_array($key, self::HOST_META, true) || \array_key_exists($key, $named)) {
                continue;
            }
            $named[(string) $key] = $value;
        }

        return $named;
    }
}
