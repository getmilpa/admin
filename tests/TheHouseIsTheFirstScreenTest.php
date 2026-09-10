<?php

/**
 * This file is part of milpa/admin.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/admin
 */

declare(strict_types=1);

namespace Milpa\Admin\Tests;

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Components\HouseComponent;
use Milpa\Admin\Components\PluginsComponent;
use Milpa\Admin\Data\HouseSource;
use Milpa\Admin\Data\PluginsSource;
use Milpa\Admin\Data\RoutesSource;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Rendering\AdminHtmlRenderer;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Admin\AdminPlugin;
use Milpa\Container\DIContainer;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\StateSnapshot;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * THE PANEL OPENS ON THE HOUSE, and it says what is missing.
 *
 * Plugins was the landing because `order: 10` won a flat `(order, id)` sort — nobody chose it — so a
 * human who had just installed the framework met a table of PHP class names (greenhouse
 * decisions/0264). These falsify the replacement: that the landing is a decision, that each absence
 * is named with its cost, and — the one that can say no — that an equipped house gets NO invented
 * next step.
 */
final class TheHouseIsTheFirstScreenTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->dirs) as $dir) {
            foreach (glob($dir . '/{,.}*', \GLOB_BRACE) ?: [] as $entry) {
                if (is_file($entry)) {
                    @unlink($entry);
                }
            }
            @rmdir($dir . '/.milpa');
            @rmdir($dir);
        }
    }

    /**
     * F1 — the panel opens on the house, and the CONTROL is that Plugins is still there.
     *
     * The class table was never wrong; it was only never the first thing anybody needed. A slice
     * that DELETED it would have answered a different complaint than the one made.
     */
    public function testThePanelOpensOnTheHouseAndPluginsIsStillThere(): void
    {
        $plugin = new AdminPlugin(new DIContainer());
        $plugin->boot();

        $catalogue = SectionCatalogue::discover([$plugin]);

        self::assertSame('house', $catalogue->first()?->id, 'the landing is a decision, not the lowest number that happened to win');
        self::assertSame(HouseComponent::NAME, $catalogue->first()?->component);
        $ids = array_map(static fn ($s): string => $s->id, $catalogue->sections());
        self::assertContains('plugins', $ids, 'the class table keeps its own section');
        self::assertSame(['house', 'plugins'], \array_slice($ids, 0, 2), 'and the house comes before it');
    }

    /**
     * F2 — the two absences are DIFFERENT facts and get different sentences.
     *
     * A missing `foundation.json` says nobody founded this app; a file of nulls says nobody told it
     * what it is for. Rendering «—» for both teaches nothing about which one you are looking at.
     */
    public function testAnEmptyFoundationAndAMissingOneAreNotTheSameSentence(): void
    {
        $blank = $this->paint($this->house($this->app(['schema' => 'milpa.foundation/v1', 'domain' => null])));
        self::assertStringContainsString('The foundation file is here and its domain is empty', $blank);
        self::assertStringContainsString('no subject to judge a request against', $blank, 'and it says what the absence costs');

        $absent = $this->paint($this->house($this->app(null)));
        self::assertStringContainsString('This app was never founded', $absent);
        self::assertStringNotContainsString('The foundation file is here', $absent, 'the other absence is not claimed');
    }

    /** A founded house says what it was founded to do, and never renders the blank sentence. */
    public function testAFoundedHouseSaysWhatItWasFoundedToDo(): void
    {
        $html = $this->paint($this->house($this->app([
            'domain' => 'Selling seed to smallholders',
            'objective' => 'One catalogue, honest stock',
            'founded_at' => '2026-09-09',
            'boundaries' => ['no third-party egress'],
            'authorities' => ['destructive_changes' => 'human'],
        ])));

        self::assertStringContainsString('Selling seed to smallholders', $html);
        self::assertStringContainsString('One catalogue, honest stock', $html);
        self::assertStringContainsString('1 boundaries declared', $html);
        self::assertStringContainsString('destructive changes is the human', $html, 'the authority reads as a sentence');
        self::assertStringNotContainsString('its domain is empty', $html);
    }

    /** A house with boundaries declared and one without say different things about being held. */
    public function testNoBoundariesIsSaidAsWhatItMeans(): void
    {
        $html = $this->paint($this->house($this->app(['domain' => 'Anything', 'boundaries' => []])));

        self::assertStringContainsString('nothing here is out of bounds because nothing was put in bounds', $html);
    }

    /**
     * F3 — who is in the house. Nobody is a WARNING that names why the panel answered anyway.
     *
     * «Nobody is signed in» alone would read as a bug. What a person needs is the reason a page they
     * did not authenticate for is on their screen: the gate let them in.
     */
    public function testNobodySignedInIsAWarningThatNamesTheGate(): void
    {
        $nobody = $this->paint($this->house($this->app(['domain' => 'x'])), principal: null);
        self::assertStringContainsString('Nobody is signed in', $nobody);
        self::assertStringContainsString('because its gate is', $nobody);
        self::assertStringContainsString('mui-alert--warning', $nobody);

        $someone = $this->paint($this->house($this->app(['domain' => 'x'])), principal: 'passkey:fTor');
        self::assertStringContainsString('You are passkey:fTor.', $someone);
        self::assertStringNotContainsString('Nobody is signed in', $someone);
    }

    /**
     * F4 — the next move is DERIVED, in the order the absences bite.
     *
     * Founding first, because everything that judges a request reads the domain. Then the index,
     * because an offline floor makes the offer list a guess. Then the agent, which is what the panel
     * exists to prepare for.
     */
    public function testTheNextMoveIsDerivedInTheOrderTheAbsencesBite(): void
    {
        $unfounded = $this->paint($this->house($this->app(['domain' => null])));
        // 🚨 IT NAMES THE RITE, NOT THE FILE. The first version said «fill in the domain in
        // .milpa/foundation.json» — hand-editing what a governed operation owns, on the one screen
        // whose point is that every act it names is an operation elsewhere (greenhouse
        // decisions/0266). The house's own `foundation` read had been teaching it correctly all along.
        self::assertStringContainsString('Nobody has declared what this house is for', $unfounded);
        self::assertStringContainsString('coa foundation:found', $unfounded);
        self::assertStringNotContainsString('foundation.json', $unfounded, 'the file is the rite\'s business, not the reader\'s');
        self::assertStringNotContainsString('capabilities:refresh', $unfounded, 'one move at a time');
    }

    /**
     * F5, THE ONE THAT CAN SAY NO — an equipped house gets no invented next step.
     *
     * If this screen always had advice, its advice would be worth nothing. So when the domain is
     * declared, the index is live and the agent is installed, it says it has nothing to tell you and
     * gets out of the way.
     */
    public function testAnEquippedHouseIsToldThatTheNextMoveIsNotThePanels(): void
    {
        $renderer = new AdminHtmlRenderer(self::codec(), new Catalog(), AdminSettings::fromConfig(null));
        $method = new \ReflectionMethod($renderer, 'houseNextMove');
        $method->setAccessible(true);

        [$says, $command] = $method->invoke(
            $renderer,
            ['declared' => true, 'domain' => 'Anything at all'],
            [['id' => 'agent'], ['id' => 'admin']],
            'registry index derived 2026-09-09T20:38:30+00:00',
        );

        self::assertStringContainsString('the next move is not the panel', $says);
        // AND NO COMMAND. A screen that always has one to run is a screen whose commands are noise.
        self::assertSame('', $command, 'nothing is invented to fill the space');
    }

    /** And each earlier absence wins over the later ones, one at a time. */
    public function testAnOfflineIndexIsNamedBeforeAMissingAgent(): void
    {
        $renderer = new AdminHtmlRenderer(self::codec(), new Catalog(), AdminSettings::fromConfig(null));
        $method = new \ReflectionMethod($renderer, 'houseNextMove');
        $method->setAccessible(true);

        self::assertSame('coa capabilities:refresh', $method->invoke($renderer, ['declared' => true, 'domain' => 'x'], [], 'registry index derived …, offline floor beneath')[1]);
        self::assertSame('coa capabilities:enable milpa/agent --sign', $method->invoke($renderer, ['declared' => true, 'domain' => 'x'], [['id' => 'admin']], 'registry index derived …')[1]);
    }

    /** A house that boots and answers nothing says so, rather than showing an empty list. */
    public function testAHouseWithNoCapabilitiesSaysItAnswersNothing(): void
    {
        $renderer = new AdminHtmlRenderer(self::codec(), new Catalog(), AdminSettings::fromConfig(null));
        $method = new \ReflectionMethod($renderer, 'house');
        $method->setAccessible(true);

        $component = new HouseComponent(new HouseSource(new DIContainer(), AdminSettings::fromConfig(null), new PluginsSource(new DIContainer()), new RoutesSource(new DIContainer(), null)));
        $state = $component->mount([], new ComponentContext('milpa-admin-section-house', route: '/milpa/admin'));

        $html = (string) $method->invoke($renderer, $state);

        self::assertStringContainsString('This house boots and answers nothing.', $html);
        self::assertStringContainsString('mui-alert--danger', $html);
        // No kernel to ask, and it SAYS that rather than printing an empty root.
        self::assertStringContainsString('cannot say where the app lives', $html);
    }

    /** The doctrine line is the house's own sentence about this panel, and it survives the screen. */
    public function testTheDoctrineLineIsOnTheScreenInBothLocales(): void
    {
        self::assertStringContainsString(
            'This is where a human leaves the house ready for the agent.',
            $this->paint($this->house($this->app(['domain' => 'x']))),
        );
        self::assertStringContainsString(
            'Aquí es donde un humano deja la casa lista para el agente.',
            $this->paint($this->house($this->app(['domain' => 'x'])), locale: 'es'),
        );
    }

    /** An app root with a lock reports which of the framework's packages it runs. */
    public function testItReportsHowManyOfTheFrameworksPackagesItRuns(): void
    {
        $root = $this->app(['domain' => 'x']);
        file_put_contents($root . '/composer.lock', (string) json_encode(['packages' => [
            ['name' => 'milpa/core', 'version' => 'v0.12.0'],
            ['name' => 'milpa/admin', 'version' => 'v0.22.0'],
            ['name' => 'psr/log', 'version' => '3.0.0'],
        ]]));

        self::assertStringContainsString('Runs 2 of the framework', $this->paint($this->house($root)), 'milpa packages only');
    }

    /**
     * 🚨 NOTHING ON THIS SCREEN RUNS INTO WHAT COMES AFTER IT.
     *
     * The first version shipped with two adjacent `<span>`s per capability row and nothing between
     * them, so a browser rendered them as one word: «The admin panel — where a human leaves the
     * house ready for the agentno commands of its own», on every row. Twelve falsifiers were green,
     * because every one of them asserted a SENTENCE and the defect was between sentences.
     *
     * Grepping prose cannot fail for the right reason (greenhouse decisions/0261) — so this asks the
     * question the others could not: does any label begin where a title ended? A browser puts no
     * space between inline elements, so neither does this check.
     */
    public function testNoLabelBeginsWhereATitleEnded(): void
    {
        // PAINTED FROM A STATE THAT HAS THE ROWS. The first version of this check rendered a house
        // with NO capabilities installed, so the branch the defect lives in was never on the page:
        // it asked its question of a page that could not contain the answer. Measured, not reasoned —
        // the run-together was still in the browser while this stayed green.
        $html = $this->paintState([
            'route' => '/milpa/admin',
            'gate' => 'loopback',
            'root' => '/tmp/x',
            'foundation' => ['declared' => true, 'domain' => 'Anything'],
            'packages' => ['count' => 2, 'rows' => []],
            'capabilities' => [
                'installed' => [
                    ['id' => 'admin', 'title' => 'The admin panel — where a human leaves the house ready for the agent', 'unlocks' => []],
                    ['id' => 'agent', 'title' => 'Sessions that outlive the process: plan, todos, permissions and decisions', 'unlocks' => ['agent:sessions']],
                ],
                'available' => [['id' => 'devtools', 'title' => 'Scaffolding and diagnosis: generate artifacts and explain the app without booting it', 'command' => 'coa capabilities:enable milpa/devtools --sign']],
                'source' => 'registry index derived 2026-09-09',
            ],
            'plugins' => 6,
            'routes' => 14,
        ], principal: 'passkey:fTor');
        $rendered = self::asLaidOut($html);

        foreach (['unlocks', 'no commands of its own', 'Gate:', 'coa capabilities:enable'] as $label) {
            self::assertDoesNotMatchRegularExpression(
                '#[\p{L}\p{N}.)]' . preg_quote($label, '#') . '#u',
                $rendered,
                // 🚨 `{$label}`, braced. «$label» is ONE variable name in a double-quoted string —
                // the guillemet's bytes are valid identifier characters — so the message read
                // «Undefined variable $label»» and said nothing. This house had that exact defect
                // recorded already, from a `«$how»` in another package.
                'the label «' . $label . '» begins where something else ended — the page reads as one run of text',
            );
        }
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────────

    /**
     * The page's text the way a browser LAYS IT OUT — which is the only reading that can tell this
     * defect from its fix.
     *
     * A block element breaks the line; an inline one does not. Stripping every tag with no separator
     * treats them alike, and this check spent two rounds calling the FIXED markup broken for exactly
     * that reason: `<div>a</div><div>b</div>` became «ab» under its own eraser while a browser shows
     * two lines. Modelling the distinction is the whole instrument (greenhouse decisions/0264).
     */
    private static function asLaidOut(string $html): string
    {
        $blocks = 'div|p|li|ul|ol|h1|h2|h3|h4|h5|h6|section|table|tr|pre';
        $withBreaks = (string) preg_replace('#</(?:' . $blocks . ')\s*>|<(?:' . $blocks . '|br)\b[^>]*>#i', "\n", $html);

        return (string) preg_replace('#<[^>]+>#', '', $withBreaks);
    }

    /** The painter, driven from a state written here — so a case can carry rows a real app might not. */
    private function paintState(array $data, ?string $principal = null, string $locale = 'en'): string
    {
        $renderer = new AdminHtmlRenderer(self::codec(), new Catalog($locale), AdminSettings::fromConfig(null));
        $method = new \ReflectionMethod($renderer, 'house');
        $method->setAccessible(true);

        return (string) $method->invoke($renderer, new StateSnapshot(
            'milpa-admin-section-house',
            HouseComponent::NAME,
            '1',
            $data,
            ['principal' => $principal ?? ''],
        ));
    }

    /** An app root, with the foundation the case wants — or none at all when given null. */
    private function app(?array $foundation): string
    {
        $root = sys_get_temp_dir() . '/milpa-house-' . bin2hex(random_bytes(5));
        mkdir($root . '/.milpa', 0700, true);
        $this->dirs[] = $root;
        if ($foundation !== null) {
            file_put_contents($root . '/.milpa/foundation.json', (string) json_encode($foundation));
        }

        return $root;
    }

    private function house(string $root): HouseComponent
    {
        $container = new DIContainer();
        $container->registerService(Kernel::class, Kernel::boot(['root' => $root, 'plugins' => []]));

        return new HouseComponent(new HouseSource(
            $container,
            AdminSettings::fromConfig(null),
            new PluginsSource($container),
            new RoutesSource($container, null),
        ));
    }

    private function paint(HouseComponent $component, ?string $principal = null, string $locale = 'en'): string
    {
        $renderer = new AdminHtmlRenderer(self::codec(), new Catalog($locale), AdminSettings::fromConfig(null));
        $context = new ComponentContext('milpa-admin-section-house', route: '/milpa/admin', principal: $principal, locale: $locale);

        return $renderer->render($component, new RenderRequest(context: $context, state: $component->mount([], $context)))->output;
    }

    private static function codec(): SignedXhtmlStateTransferCodec
    {
        return new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('house-test-secret'), null);
    }

    /** The class table still declares what its name promises — the CONTROL of F1. */
    public function testPluginsStillPaintsTheClassTable(): void
    {
        self::assertSame('admin-plugins', PluginsComponent::NAME);
    }
}
