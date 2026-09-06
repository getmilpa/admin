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

namespace Milpa\Admin\Tests\View;

use Milpa\Admin\AdminSettings;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\DeclaredView;
use Milpa\Admin\Section\SeedConflictException;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Admin\Section\SectionRender;
use Milpa\Admin\Tests\Fixtures\BrokenComponent;
use Milpa\Admin\Tests\Fixtures\BrokenRenderer;
use Milpa\Admin\Tests\Fixtures\CounterComponent;
use Milpa\Admin\Tests\Fixtures\CounterRenderer;
use Milpa\Admin\Tests\Fixtures\EchoComponent;
use Milpa\Admin\Tests\Fixtures\EchoRenderer;
use Milpa\Admin\Tests\Fixtures\HolaPlugin;
use Milpa\Admin\Tests\Fixtures\PaintableComponent;
use Milpa\Admin\Tests\Fixtures\RecordingDispatcher;
use Milpa\Admin\Tests\Fixtures\UnpaintableRenderer;
use Milpa\Admin\Tests\Fixtures\ViewPlugin;
use Milpa\Admin\View\AdminShell;
use Milpa\Container\DIContainer;
use Milpa\Live\Contracts\Transport\StateTransferCodecInterface;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use PHPUnit\Framework\TestCase;

/**
 * The host mounts a guest's VIEW (greenhouse decisions/0211, slice 3): the whole tree composed inline in
 * the panel's own main — no frame, no second document — with its assets collected for the one runtime, its
 * seeds merged into the page's, and a component that throws contained inside its own node.
 */
final class DeclaredViewShellTest extends TestCase
{
    public function testTheWholeTreeIsComposedInlineAndItsAssetsCollectedOnce(): void
    {
        $catalogue = SectionCatalogue::discover([new ViewPlugin(new DIContainer())]);
        $active = $catalogue->find(ViewPlugin::SECTION);
        self::assertNotNull($active);

        $composed = self::shell()->compose($catalogue, $active);

        self::assertStringNotContainsString('<iframe', $composed->html, 'a view is composed, never framed');
        self::assertStringContainsString('<div class="lab-counter" id="lab-a" data-count="1">1</div>', $composed->html, 'the first root, mounted with the view\'s props');
        self::assertStringContainsString('<div class="lab-counter" id="lab-b" data-count="7">7</div>', $composed->html, 'the second, whose own attribute wins over the default');
        self::assertSame(2, substr_count($composed->html, 'class="lab-counter"'), 'both roots, in document order');
        self::assertStringContainsString('<div class="admin-section__body" tabindex="0" role="region" aria-labelledby="milpa-admin-header"><div class="lab-counter" id="lab-a"', $composed->html, 'the tree sits in the shell\'s own body, under the host\'s header');
        self::assertSame(1, substr_count($composed->html, '<script type="application/milpa+xhtml" data-milpa-state="lab-a">'), 'each node closed with its own signed envelope');
        self::assertSame(1, substr_count($composed->html, '<script type="application/milpa+xhtml" data-milpa-state="lab-b">'));

        self::assertSame([CounterRenderer::SCRIPT], $composed->assets->scripts, 'one module although two nodes declared it');
        self::assertSame([CounterRenderer::STYLE], $composed->assets->styles);
    }

    /**
     * greenhouse decisions/0211: host facts a guest may need travel in `ComponentContext::meta` — the gate
     * label and the active section id — without widening any contract. The principal, locale and route
     * already travelled and still do.
     */
    public function testTheContextOfEveryNodeCarriesTheGateAndTheActiveSection(): void
    {
        $catalogue = SectionCatalogue::discover([new ViewPlugin(new DIContainer())]);
        $active = $catalogue->find(ViewPlugin::SECTION);
        self::assertNotNull($active);
        CounterComponent::$lastContext = null;

        self::shell(settings: new AdminSettings(middleware: [AdminSettings::PASSKEY_GATE]))
            ->compose($catalogue, $active, ['session' => 's-1'], 'passkey:rod');

        $context = CounterComponent::$lastContext;
        self::assertNotNull($context);
        self::assertSame('passkey', $context->meta[AdminShell::META_GATE] ?? null, 'the gate the topbar chip names');
        self::assertSame(ViewPlugin::SECTION, $context->meta[AdminShell::META_SECTION] ?? null);
        self::assertSame(['session' => 's-1'], $context->meta[AdminShell::META_QUERY] ?? null, 'a view reads the query here — `props[query]` is the narrow shape\'s channel');
        self::assertSame('passkey:rod', $context->principal);
        self::assertSame('/milpa/admin', $context->route);
        self::assertSame('en', $context->locale);
        self::assertSame('lab-b', $context->componentId, 'each node mounts under its own id');
    }

    /**
     * The falsifier of «contained errors» (greenhouse decisions/0211, reinterpreting 0210 F6): a component
     * that throws while mounting paints its failure INSIDE its own node; the rest of the view, the header,
     * the sidebar and the chips are all still there — never a 500 for the whole panel.
     */
    public function testAComponentThatThrowsPaintsItsOwnRegionAndThePanelStands(): void
    {
        $catalogue = SectionCatalogue::discover([new ViewPlugin(new DIContainer(), broken: true)]);
        $active = $catalogue->find(ViewPlugin::SECTION);
        self::assertNotNull($active);

        $composed = self::shell()->compose($catalogue, $active);

        self::assertStringContainsString('data-failed-component="' . BrokenComponent::NAME . '"', $composed->html);
        self::assertStringContainsString('The component «lab-broken» could not be rendered.', $composed->html);
        self::assertStringContainsString('Reason: ' . BrokenComponent::BOOM . '. The rest of the panel is unaffected.', $composed->html);
        self::assertStringContainsString('<div class="lab-counter" id="lab-a"', $composed->html, 'the sibling roots painted');
        self::assertStringContainsString('<div class="lab-counter" id="lab-b"', $composed->html);
        self::assertStringContainsString('class="mui-shell"', $composed->html, 'the shell stands');
        self::assertStringContainsString('admin-chip--gate', $composed->html, 'the chips too');
        self::assertStringContainsString('declared by ViewPlugin', $composed->html, 'and the attribution the host paints');
        self::assertSame([CounterRenderer::SCRIPT], $composed->assets->scripts, 'what the healthy nodes declared still reaches the page');

        $es = self::shell(catalog: new Catalog('es'))->compose($catalogue, $active);
        self::assertStringContainsString('No se pudo renderizar el componente «lab-broken».', $es->html);
    }

    /**
     * The OTHER half of containment: a component that MOUNTS and throws while being PAINTED
     * (greenhouse decisions/0211, H6). The test above breaks in `mount()`; this one breaks in `render()`,
     * which is where a guest's renderer actually fails in the field — and the panel must not tell them
     * apart.
     *
     * The positive control is in the same run: the healthy sibling roots are painted, the shell and the
     * attribution stand, and only the broken node's own files are missing from the page.
     */
    public function testAComponentThatThrowsWhilePaintingIsContainedTheSameWay(): void
    {
        $catalogue = SectionCatalogue::discover([self::provider([
            AdminSection::ofView('unpaintable', 'Unpaintable', new DeclaredView(
                markup: '<milpa:' . CounterComponent::NAME . ' id="lab-a"/><milpa:' . PaintableComponent::NAME . ' id="lab-dead"/>',
                definitions: [CounterComponent::NAME => new CounterComponent(), PaintableComponent::NAME => new PaintableComponent()],
                renderers: [CounterComponent::NAME => new CounterRenderer(self::codec()), PaintableComponent::NAME => new UnpaintableRenderer()],
            )),
        ])]);
        $active = $catalogue->find('unpaintable');
        self::assertNotNull($active);

        $composed = self::shell()->compose($catalogue, $active);

        self::assertStringContainsString('data-failed-component="' . PaintableComponent::NAME . '"', $composed->html, 'the failure names the component');
        self::assertStringContainsString('The component «lab-unpaintable» could not be rendered.', $composed->html);
        self::assertStringContainsString('Reason: ' . UnpaintableRenderer::BOOM . '. The rest of the panel is unaffected.', $composed->html, 'and says why — the panel is behind a gate');

        // The positive control: the page around it is whole.
        self::assertStringContainsString('<div class="lab-counter" id="lab-a"', $composed->html, 'the healthy sibling painted');
        self::assertStringContainsString('class="mui-shell"', $composed->html, 'the shell stands');
        self::assertStringContainsString('admin-chip--gate', $composed->html, 'the chips too');

        // What a surface DECLARED is collected only when it rendered: nothing of the dead node is asked for.
        self::assertSame([CounterRenderer::SCRIPT], $composed->assets->scripts);
        self::assertNotContains(UnpaintableRenderer::SCRIPT, $composed->assets->scripts, 'a node that never painted declares nothing');
    }

    /** The same containment for the narrow one-component shape: a broken section is a region, not a 500. */
    public function testAThrowingSingleComponentSectionIsContainedToo(): void
    {
        $catalogue = SectionCatalogue::discover([self::provider([
            new AdminSection('broken', 'Broken', BrokenComponent::NAME, definition: new BrokenComponent(), renderer: new BrokenRenderer()),
        ])]);
        $active = $catalogue->find('broken');
        self::assertNotNull($active);

        $html = self::shell()->render($catalogue, $active);

        self::assertStringContainsString('data-failed-component="' . BrokenComponent::NAME . '"', $html);
        self::assertStringContainsString('class="mui-shell"', $html);
    }

    /** Markup that is not well-formed is contained the same way, named by the section that declared it. */
    public function testMarkupThatDoesNotParseIsContainedAndNamesTheSection(): void
    {
        $catalogue = SectionCatalogue::discover([self::provider([
            AdminSection::ofView('bad', 'Bad', new DeclaredView(
                markup: '<milpa:echo-panel id="a">',
                definitions: [EchoComponent::NAME => new EchoComponent()],
                renderers: [EchoComponent::NAME => new EchoRenderer()],
            )),
        ])]);
        $active = $catalogue->find('bad');
        self::assertNotNull($active);

        $html = self::shell()->render($catalogue, $active);

        self::assertStringContainsString('data-failed-component="bad"', $html);
        self::assertStringContainsString('not well-formed', $html);
        self::assertStringContainsString('class="mui-shell"', $html);
    }

    /** The page's seeds: the panel's own, plus the active view's — and only the active one's. */
    public function testTheSeedsAreThePanelsOwnMergedWithTheActiveViews(): void
    {
        $catalogue = SectionCatalogue::discover([new ViewPlugin(new DIContainer()), new HolaPlugin(new DIContainer())]);
        $active = $catalogue->find(ViewPlugin::SECTION);
        $hola = $catalogue->find('hola');
        self::assertNotNull($active);
        self::assertNotNull($hola);

        $seeds = self::shell(settings: new AdminSettings(middleware: [AdminSettings::PASSKEY_GATE]))->compose($catalogue, $active)->seeds;
        self::assertSame([
            AdminShell::SIGNAL_SECTION => ViewPlugin::SECTION,
            AdminShell::SIGNAL_GATE => 'passkey',
            AdminShell::SIGNAL_LOCALE => 'en',
            ViewPlugin::SIGNAL => 0,
        ], $seeds->signals);
        self::assertSame([ViewPlugin::SIGNAL], $seeds->persist);
        self::assertSame(['lab.label' => ['template' => '{lab.counter}']], $seeds->computed);

        $other = self::shell()->compose($catalogue, $hola)->seeds;
        self::assertArrayNotHasKey(ViewPlugin::SIGNAL, $other->signals, 'a view that is not on the page seeds nothing on it');
        self::assertSame('hola', $other->signals[AdminShell::SIGNAL_SECTION]);

        $empty = self::shell()->composeEmpty(SectionCatalogue::discover([]))->seeds;
        self::assertSame('', $empty->signals[AdminShell::SIGNAL_SECTION], 'no section open: the panel still says which gate and which locale');
    }

    /** A view that seeds a signal the panel owns, differently, is refused by name — never resolved in silence. */
    public function testAViewThatSeedsWhatThePanelSeedsDifferentlyIsRefusedByName(): void
    {
        $catalogue = SectionCatalogue::discover([self::provider([
            AdminSection::ofView('greedy', 'Greedy', new DeclaredView(
                markup: '<milpa:echo-panel id="a"/>',
                definitions: [EchoComponent::NAME => new EchoComponent()],
                renderers: [EchoComponent::NAME => new EchoRenderer()],
                signals: [AdminShell::SIGNAL_GATE => 'open'],
            )),
        ])]);
        $active = $catalogue->find('greedy');
        self::assertNotNull($active);

        $this->expectException(SeedConflictException::class);
        $this->expectExceptionMessage('the panel says "loopback", section «greedy» says "open"');
        self::shell()->compose($catalogue, $active);
    }

    /** With a view, the section lifecycle still fires — and its subject carries the view's props, per component. */
    public function testTheSectionLifecycleStillFiresAroundAView(): void
    {
        $events = new RecordingDispatcher();
        $seen = null;
        $events->subscribe(AdminShell::SECTION_BEFORE_RENDER, static function (string $name, array $payload) use (&$seen): void {
            $subject = $payload['section'];
            \assert($subject instanceof SectionRender);
            $seen = $subject->props;
            $subject->props[CounterComponent::NAME] = ['count' => 42];
        });
        $events->subscribe(AdminShell::SECTION_AFTER_RENDER, static function (string $name, array $payload): void {
            $subject = $payload['section'];
            \assert($subject instanceof SectionRender);
            $subject->html .= '<p data-injected="view">after</p>';
        });

        $catalogue = SectionCatalogue::discover([new ViewPlugin(new DIContainer())]);
        $active = $catalogue->find(ViewPlugin::SECTION);
        self::assertNotNull($active);

        $html = self::shell($events)->render($catalogue, $active);

        self::assertSame([CounterComponent::NAME => ['count' => 1]], $seen, 'the view\'s own component-name → props map');
        self::assertStringContainsString('data-count="42"', $html, 'a subscriber changed what the tree mounts with');
        self::assertStringContainsString('data-injected="view">after</p></div>', $html, 'and what it added lands inside the section body');
    }

    /** @param list<AdminSection> $sections */
    private static function provider(array $sections): \Milpa\Admin\Section\AdminSectionProvider
    {
        return new class ($sections) implements \Milpa\Admin\Section\AdminSectionProvider {
            /** @param list<AdminSection> $sections */
            public function __construct(private readonly array $sections)
            {
            }

            public function adminSections(): array
            {
                return $this->sections;
            }
        };
    }

    private static function shell(?RecordingDispatcher $events = null, ?AdminSettings $settings = null, ?Catalog $catalog = null): AdminShell
    {
        return new AdminShell($settings ?? AdminSettings::fromConfig(null), $catalog ?? new Catalog(), self::codec(), $events);
    }

    private static function codec(): StateTransferCodecInterface
    {
        return new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('test-secret-0123456789'), null);
    }
}
