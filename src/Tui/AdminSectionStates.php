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

use Milpa\Admin\Components\ComponentBook;
use Milpa\Admin\Section\BootedPlugins;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Admin\Section\SectionConflictException;
use Milpa\Console\State\SectionStateProvider;
use Milpa\Console\State\SectionStateSource;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;

/**
 * Every section of the panel, offered to the terminal — the ONE implementation that puts the whole panel
 * in a second surface (greenhouse decisions/0220).
 *
 * The terminal's dashboard (`milpa/console`'s `InspectableSections` → `ConsoleScreen`) discovers state
 * from booted plugins implementing {@see SectionStateSource}. If each plugin had to implement it, every
 * plugin would declare its section TWICE — once for the panel, once for the terminal — which is the exact
 * thing 0220 refuses. So the panel implements it ONCE, for everyone: it already discovers every section
 * every request ({@see SectionCatalogue}), its own and every guest's, and that catalogue is what it hands
 * over. A plugin declares an `AdminSection` and gains a terminal it never mentioned.
 *
 * Discovery runs per call, like the panel's own, so a plugin that boots after the panel is still there.
 */
final readonly class AdminSectionStates implements SectionStateSource
{
    /**
     * @param object $self the admin plugin instance — the one provider the panel can count on without a kernel
     */
    public function __construct(
        private DIContainerInterface $container,
        private object $self,
        private StateTransferCodecInterface $codec,
        private string $route,
        private ?MilpaEventDispatcherInterface $events = null,
    ) {
    }

    /** @return array<string, SectionStateProvider> */
    public function sectionStates(): array
    {
        try {
            $catalogue = SectionCatalogue::discover(BootedPlugins::of($this->container, $this->self));
        } catch (SectionConflictException) {
            // Two plugins claiming one section id breaks the HTML panel with a 500 and it breaks this the
            // same way: there is no honest catalogue to show. Empty, so the dashboard says nobody exposes
            // state instead of showing half a panel as if it were whole.
            return [];
        }

        [$components, $unavailable] = $this->components($catalogue);

        $states = [];
        foreach ($catalogue->sections() as $section) {
            $states[$section->id] = new SectionState($section, $components, $this->route, $unavailable);
        }

        return $states;
    }

    /**
     * What the panel composes with, or the reason it could not be built.
     *
     * The book refuses a section that redefines one of the panel's own, that names a component nothing
     * registered, or that clashes with another's — the same refusals the HTML panel reports as a 500. Here
     * they must not take the whole dashboard down: every section that BRINGS its own definition can still
     * be mounted without a registry, so the reason travels to the ones that cannot and they report it.
     *
     * @return array{0: \Milpa\Live\Contracts\Component\ComponentRegistryInterface|null, 1: string|null}
     */
    private function components(SectionCatalogue $catalogue): array
    {
        try {
            return [ComponentBook::forSections($catalogue, $this->codec, $this->events)->registry(), null];
        } catch (\Throwable $refused) {
            return [null, $refused->getMessage()];
        }
    }
}
