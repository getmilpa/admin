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

namespace Milpa\Admin\Tests\Tui;

use Milpa\Admin\AdminPlugin;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Tests\Fixtures\GuestPlugin;
use Milpa\Admin\Tui\AdminSectionStates;
use Milpa\Admin\Tui\SectionState;
use Milpa\Console\State\InspectableSections;
use Milpa\Console\Tui\ConsoleScreen;
use Milpa\Container\DIContainer;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * One declaration, two surfaces — greenhouse `decisions/0220`, measured.
 *
 * A plugin declares an {@see AdminSection} for the panel and says nothing about a terminal. The dashboard
 * `milpa/console` already had — with no host in the published family — paints that same declaration.
 * Nothing is declared twice and no renderer is written: the state IS the picture.
 */
#[CoversClass(AdminSectionStates::class)]
#[CoversClass(SectionState::class)]
final class ThePanelHasATerminalTest extends TestCase
{
    /**
     * F1. The guest declared ONE section, for the panel. It reaches the terminal, painted, without ever
     * naming `milpa/console`, a terminal or a second contract.
     */
    public function testASectionDeclaredForThePanelPaintsInATerminal(): void
    {
        $screen = self::screen([AdminPlugin::class, GuestPlugin::class]);

        // The terminal opens where the panel opens, and both now open on the house rather than on a
        // table of class names (greenhouse decisions/0264). One fact, two surfaces.
        self::assertSame('house', $screen->currentSectionId(), 'the terminal opens where the panel opens');

        $screen->press('7');   // house, plugins, routes, settings, stack, devtools, agent
        self::assertSame('agent', $screen->currentSectionId());

        $painted = $screen->render();
        self::assertStringContainsString('agent', $painted, 'the guest section is on the screen');
        self::assertStringContainsString('Agent', $painted, 'and so is the state its component mounted');
    }

    /**
     * The positive control. Take the guest out of the app and the section leaves BOTH surfaces — proof the
     * terminal reads the catalogue every time, not a list written down once.
     */
    public function testWithoutTheGuestPluginThatSectionIsInNeitherSurface(): void
    {
        $withGuest = array_keys(self::states([AdminPlugin::class, GuestPlugin::class]));
        $alone = array_keys(self::states([AdminPlugin::class]));

        self::assertContains('agent', $withGuest);
        self::assertNotContains('agent', $alone);
        self::assertSame(['house', 'plugins', 'routes', 'settings', 'stack', 'devtools'], $alone, 'the panel keeps its own');
    }

    /** A section that only NAMES a component the panel registers is mounted from the panel's own registry. */
    public function testASectionThatOnlyNamesAComponentStillHasState(): void
    {
        $state = self::states([AdminPlugin::class, GuestPlugin::class])['agent']->state();

        self::assertSame('Agent', $state['title'] ?? null);
        self::assertSame('live', $state['value'] ?? null);
    }

    /**
     * A component that throws while mounting reports its failure IN ITS OWN state and the rest of the
     * panel stands — the HTML shell's rule, said for the terminal. Without this one section takes the
     * whole dashboard down and the person sees a stack trace instead of a panel.
     */
    public function testASectionThatCannotMountSaysSoAndTheOthersStand(): void
    {
        $section = new AdminSection(
            id: 'broken',
            title: 'Broken',
            component: 'broken',
            definition: new ThrowsOnMount(),
            renderer: new NeverPaints(),
        );

        $state = (new SectionState($section, null, '/milpa/admin'))->state();

        self::assertSame('mounting is exactly what fails here', $state[SectionState::ERROR_KEY] ?? null);
    }

    /** Before boot there is no codec to mount with and no catalogue to read: nothing, said as nothing. */
    public function testAPanelThatHasNotBootedOffersNoTerminalState(): void
    {
        self::assertSame([], (new AdminPlugin(new DIContainer()))->sectionStates());
    }

    /**
     * @param list<class-string> $plugins
     *
     * @return array<string, \Milpa\Console\State\SectionStateProvider>
     */
    private static function states(array $plugins): array
    {
        foreach (self::boot($plugins)->plugins() as $plugin) {
            if ($plugin instanceof AdminPlugin) {
                return $plugin->sectionStates();
            }
        }

        self::fail('the panel did not boot');
    }

    /** @param list<class-string> $plugins */
    private static function screen(array $plugins): ConsoleScreen
    {
        return new ConsoleScreen(
            new InspectableSections(self::boot($plugins)->plugins()),
            width: 90,
            height: 24,
            ansi: false,
        );
    }

    /** @param list<class-string> $plugins */
    private static function boot(array $plugins): Kernel
    {
        $container = new DIContainer();
        $kernel = Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => $plugins, 'config' => [], 'container' => $container]);
        $container->registerService(Kernel::class, $kernel);

        return $kernel;
    }
}

/** A component whose mount fails — the case the terminal must survive. */
final class ThrowsOnMount implements ComponentDefinitionInterface
{
    public static function contract(): ComponentContract
    {
        return new ComponentContract(name: 'broken', contractVersion: '1');
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        throw new \RuntimeException('mounting is exactly what fails here');
    }

    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult($request->state);
    }
}

/** A renderer only so the section is well-formed: a definition without one is refused at construction. */
final class NeverPaints implements \Milpa\Live\Contracts\Rendering\ComponentRendererInterface
{
    public function supportsTarget(\Milpa\Live\ValueObjects\RenderTarget $target): bool
    {
        return false;
    }

    public function render(ComponentDefinitionInterface $component, \Milpa\Live\ValueObjects\RenderRequest $request): \Milpa\Live\ValueObjects\RenderResult
    {
        throw new \LogicException('never');
    }
}
