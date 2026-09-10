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

namespace Milpa\Admin\Tests\Section;

use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\AdminSectionProvider;
use Milpa\Admin\Section\DeclaredView;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Admin\Section\SectionConflictException;
use PHPUnit\Framework\TestCase;

/**
 * A SECTION CAN BELONG UNDER ANOTHER, and that is the whole mechanism behind the gear.
 *
 * The Desktop's deep screens — settings, skills, subagents — lived only inside the `/desktop` page,
 * so the panel could show the conversation and nothing else about the agent. The cheap answer would
 * have been a new `settings` slot on the contract; the true one is that those screens ARE sections
 * and only needed a way to say whose they are. Everything else they already had: `{route}/s/{id}`
 * routes any declared id, one middleware stack covers every panel route, and the catalogue orders
 * them (greenhouse decisions/0268).
 */
final class ASectionCanHaveChildrenTest extends TestCase
{
    /**
     * 🚨 A CHILD IS OUT OF THE MAIN NAVIGATION AND STILL REACHABLE, which is the point.
     *
     * Out of the nav is what Rod asked for — settings should not crowd the menu. Still reachable is
     * what makes it a section rather than a decoration: the same URL shape, the same gate.
     */
    public function testAChildLeavesTheMainNavigationAndKeepsItsUrl(): void
    {
        $catalogue = self::tree();

        self::assertSame(['plugins', 'agent'], self::ids($catalogue->roots()), 'the nav shows the parents, in (order, id)');
        self::assertSame(['agent-settings', 'agent-skills'], self::ids($catalogue->children('agent')));
        self::assertSame('agent-skills', $catalogue->find('agent-skills')?->id, 'and the child still resolves by id');
    }

    /**
     * 🚨 `sections()` STILL MEANS EVERY SECTION, and narrowing it would have broken three callers in
     * silence.
     *
     * The panel's 404 lists the ids that ARE present, and a child missing from the error whose whole
     * job is naming what exists would be invisible exactly when somebody is looking for it. The
     * component book must cover a child's component like any other. The TUI needs state for every
     * section it can open. So the roots are a SECOND reader, not a new meaning for the old one.
     */
    public function testEverySectionIsStillListedForTheCallersThatNeedThemAll(): void
    {
        $all = self::ids(self::tree()->sections());

        self::assertContains('agent-skills', $all, 'the 404 that names what exists must name this too');
        self::assertSame(['agent-settings', 'plugins', 'agent-skills', 'agent'], $all, 'in (order, id), children among them');
    }

    /**
     * A child can never be the panel's front page.
     *
     * The panel opens on the house, and if a child could win that race the answer to «what is this
     * house» would be somebody's settings screen (greenhouse decisions/0264).
     */
    public function testAChildCanNeverBeTheFrontPageEvenWithTheLowestOrder(): void
    {
        $catalogue = SectionCatalogue::discover([self::provider([
            new AdminSection('agent-settings', 'Settings', 'metric-card', order: -100, parent: 'agent'),
            new AdminSection('agent', 'Agent', 'metric-card', order: 50),
        ])]);

        self::assertSame('agent', $catalogue->first()?->id);
    }

    /**
     * 🚨 A PARENT NOBODY DECLARED LEAVES THE SECTION A ROOT — it does not hide it.
     *
     * The alternative makes uninstalling one plugin silently swallow another's section: the child
     * would be absent from the navigation, present in the catalogue, and reachable only by somebody
     * who already knew the URL. A section whose home is missing goes back to the top level, where it
     * can at least be found.
     */
    public function testAnOrphanIsARootAndNotAGhost(): void
    {
        $catalogue = SectionCatalogue::discover([self::provider([
            new AdminSection('lonely', 'Lonely', 'metric-card', parent: 'a-plugin-nobody-installed'),
        ])]);

        self::assertSame(['lonely'], self::ids($catalogue->roots()));
        self::assertSame([], $catalogue->children('a-plugin-nobody-installed'), 'and it is nobody\'s child');
    }

    /**
     * One level only, refused rather than flattened.
     *
     * A gear that opens a gear is a menu nobody asked for. Refusing teaches the author; flattening
     * would leave them believing they had a third level that silently became a second. The check
     * lives in the catalogue because only there is the whole set known — a section cannot see, at
     * construction, whether the parent it names is itself somebody's child.
     */
    public function testAGrandchildIsRefusedAndSaysWhy(): void
    {
        $this->expectException(SectionConflictException::class);
        $this->expectExceptionMessage('sections nest one level');

        SectionCatalogue::discover([self::provider([
            new AdminSection('agent', 'Agent', 'metric-card'),
            new AdminSection('agent-settings', 'Settings', 'metric-card', parent: 'agent'),
            new AdminSection('agent-settings-deep', 'Deeper', 'metric-card', parent: 'agent-settings'),
        ])]);
    }

    /** A section cannot be its own parent, and a parent that is not an id says so at construction. */
    public function testASectionRefusesToBeItsOwnParentAndRefusesANonId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('its own parent');
        new AdminSection('agent', 'Agent', 'metric-card', parent: 'agent');
    }

    public function testAParentThatIsNotASectionIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('which is not a section id');
        new AdminSection('agent', 'Agent', 'metric-card', parent: 'Not An Id');
    }

    /** Children keep the catalogue's order, so a plugin decides what its gear lists first. */
    public function testChildrenComeBackInTheOrderTheirAuthorGaveThem(): void
    {
        $catalogue = SectionCatalogue::discover([self::provider([
            new AdminSection('agent', 'Agent', 'metric-card'),
            new AdminSection('b-second', 'Second', 'metric-card', order: 20, parent: 'agent'),
            new AdminSection('a-third', 'Third', 'metric-card', order: 30, parent: 'agent'),
            new AdminSection('c-first', 'First', 'metric-card', order: 10, parent: 'agent'),
        ])]);

        self::assertSame(['c-first', 'b-second', 'a-third'], self::ids($catalogue->children('agent')));
    }

    /**
     * 🚨 EVERY SECTION APPEARS EXACTLY ONCE between the roots and somebody's children.
     *
     * This is what caught a real defect: `children()` answered for an id nobody declared, so a
     * section whose parent is missing came back as a root AND as that phantom's child. Two answers
     * about one section is how a navigation ends up listing it twice, or not at all.
     */
    public function testTheRootsAndEveryChildAreThePartitionOfTheCatalogue(): void
    {
        $catalogue = SectionCatalogue::discover([self::provider([
            new AdminSection('agent', 'Agent', 'metric-card'),
            new AdminSection('agent-skills', 'Skills', 'metric-card', parent: 'agent'),
            new AdminSection('plugins', 'Plugins', 'metric-card'),
            new AdminSection('lonely', 'Lonely', 'metric-card', parent: 'nobody-declared-me'),
        ])]);

        $seen = self::ids($catalogue->roots());
        foreach ($catalogue->sections() as $section) {
            $seen = [...$seen, ...self::ids($catalogue->children($section->id))];
        }
        sort($seen);

        self::assertSame(['agent', 'agent-skills', 'lonely', 'plugins'], $seen, 'each one once');
        self::assertSame(\count($catalogue->sections()), \count($seen), 'no section counted twice');
    }

    /**
     * The factory guests use can nest too.
     *
     * Every plugin that brings its own view declares through `ofView()`, so a factory that could not
     * say `parent` would make nesting a privilege of the constructor — and the sections that most need
     * a gear are exactly the ones a guest brings.
     */
    public function testTheViewFactoryCarriesTheParentThroughToTheSection(): void
    {
        $section = AdminSection::ofView(
            id: 'agent-settings',
            title: 'Settings',
            view: new DeclaredView('<milpa:metric-card id="agent-settings-card" title="x" value="1"/>'),
            parent: 'agent',
        );

        self::assertSame('agent', $section->parent);
        self::assertTrue($section->hasView(), 'and it is still a view section');
    }

    private static function tree(): SectionCatalogue
    {
        return SectionCatalogue::discover([self::provider([
            new AdminSection('agent', 'Agent', 'metric-card', order: 50, group: AdminSection::GROUP_AGENT),
            new AdminSection('agent-skills', 'Skills', 'metric-card', order: 20, parent: 'agent'),
            new AdminSection('agent-settings', 'Settings', 'metric-card', order: 10, parent: 'agent'),
            new AdminSection('plugins', 'Plugins', 'metric-card', order: 10, group: AdminSection::GROUP_ADMIN),
        ])]);
    }

    /**
     * @param list<AdminSection> $sections
     *
     * @return list<string>
     */
    private static function ids(array $sections): array
    {
        return array_map(static fn (AdminSection $s): string => $s->id, $sections);
    }

    /** @param list<AdminSection> $sections */
    private static function provider(array $sections): AdminSectionProvider
    {
        return new class ($sections) implements AdminSectionProvider {
            /** @param list<AdminSection> $sections */
            public function __construct(private readonly array $sections)
            {
            }

            /** @return list<AdminSection> */
            public function adminSections(): array
            {
                return $this->sections;
            }
        };
    }
}
