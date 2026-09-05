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

namespace Milpa\Admin\Tests\Rendering;

use Milpa\Admin\Components\SectionHeaderComponent;
use Milpa\Admin\Components\SidebarComponent;
use Milpa\Admin\Rendering\ShellHtmlRenderer;
use Milpa\Admin\Tests\Fixtures\EchoComponent;
use Milpa\Admin\Tests\Fixtures\ReadsSidebar;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * The shell's own two components painted: the grouped sidebar with its glyphs, and the section header with
 * its attribution — every word from the catalog the context's locale names.
 */
final class ShellHtmlRendererTest extends TestCase
{
    use ReadsSidebar;

    private const ITEMS = [
        ['key' => 'plugins', 'label' => 'Plugins', 'href' => '/milpa/admin/s/plugins', 'icon' => '', 'group' => 'admin'],
        ['key' => 'hola', 'label' => 'Hola', 'href' => '/milpa/admin/s/hola', 'icon' => '✦'],
        ['key' => 'agent', 'label' => 'Agent', 'href' => '/milpa/admin/s/agent', 'icon' => '◈', 'group' => 'agent'],
        ['key' => 'lab', 'label' => 'Lab <b>', 'href' => '/x?a=1&b=2', 'icon' => '"', 'group' => 'my lab'],
    ];

    public function testTheSidebarPaintsOneGroupPerHeadingWithTheGlyphsAndTheActiveItem(): void
    {
        $result = self::renderer()->render(new SidebarComponent(), new RenderRequest(
            new ComponentContext('milpa-admin-sidebar', locale: 'en'),
            ['brand' => 'Milpa Admin', 'home' => '/milpa/admin', 'active' => 'hola', 'items' => self::ITEMS],
        ));
        $html = $result->output;

        self::assertStringStartsWith(
            '<nav class="mui-sidebar" id="milpa-admin-sidebar" aria-label="Sections" data-milpa-component-id="milpa-admin-sidebar">'
            . '<a class="mui-sidebar__brand" href="/milpa/admin"><span class="mui-sidebar__wordmark">Milpa Admin</span></a><div class="mui-sidebar__nav">',
            $html,
        );
        self::assertSame(['ADMIN', 'APP', 'AGENT', 'MY LAB'], self::headings($html), 'the house order, then the rest — an unknown group is its own name uppercased');
        self::assertStringContainsString(
            '<div class="mui-sidebar__section" role="group" aria-labelledby="milpa-admin-sidebar-group-0" data-group="admin"><span class="mui-sidebar__section-label" id="milpa-admin-sidebar-group-0">ADMIN</span>'
            . '<a class="mui-sidebar__item" href="/milpa/admin/s/plugins"><span class="mui-sidebar__item-icon" aria-hidden="true"></span><span class="mui-sidebar__item-label">Plugins</span></a></div>',
            $html,
        );
        self::assertStringContainsString(
            '<a class="mui-sidebar__item" href="/milpa/admin/s/hola" aria-current="page"><span class="mui-sidebar__item-icon" aria-hidden="true">✦</span><span class="mui-sidebar__item-label">Hola</span></a>',
            $html,
            'the primitive\'s item markup, the glyph painted, the active one marked',
        );
        self::assertSame(['/milpa/admin/s/agent'], self::itemsUnder($html, 'agent'));
        self::assertStringContainsString('aria-hidden="true">◈</span><span class="mui-sidebar__item-label">Agent</span>', $html);
        self::assertStringContainsString(
            '<div class="mui-sidebar__section" role="group" aria-labelledby="milpa-admin-sidebar-group-3" data-group="my lab"><span class="mui-sidebar__section-label" id="milpa-admin-sidebar-group-3">MY LAB</span>'
            . '<a class="mui-sidebar__item" href="/x?a=1&amp;b=2"><span class="mui-sidebar__item-icon" aria-hidden="true">&quot;</span><span class="mui-sidebar__item-label">Lab &lt;b&gt;</span></a></div></div></nav>'
            . '<script type="application/milpa+xhtml" data-milpa-state="milpa-admin-sidebar">',
            $html,
            'the heading id is positional, not the name; every value is escaped; the envelope closes the component',
        );
        self::assertStringEndsWith('</script>', $html);
        self::assertStringNotContainsString('cultivo', $html, 'the primitive\'s literal heading is not here');
        self::assertNotNull($result->state);
        self::assertSame('hola', $result->state->data['active']);
        self::assertSame(RenderTarget::HTML, $result->format);

        $es = self::renderer()->render(new SidebarComponent(), new RenderRequest(new ComponentContext('s', locale: 'es'), ['items' => self::ITEMS]))->output;
        self::assertStringContainsString('aria-label="Secciones"', $es);
        self::assertSame(['ADMIN', 'APP', 'AGENTE', 'MY LAB'], self::headings($es));
        self::assertStringContainsString('<a class="mui-sidebar__brand" href="/milpa/admin"><span class="mui-sidebar__wordmark">Milpa Admin</span></a>', $es, 'the defaults');

        $none = self::renderer()->render(new SidebarComponent(), new RenderRequest(new ComponentContext('s'), []))->output;
        self::assertStringNotContainsString('mui-sidebar__section', $none, 'no items: the brand alone, no empty group');
        self::assertStringContainsString('aria-label="Sections"', $none, 'no locale in the context: English');
    }

    /**
     * Two group names that sanitize alike (`my lab`, `my-lab`) used to share one heading id — a duplicate `id` and an
     * ambiguous `aria-labelledby` on the page; and `strtoupper` left `año` as `AñO`. The id is positional now, the
     * heading is uppercased in its own alphabet.
     */
    public function testGroupHeadingIdsArePositionalSoAlikeNamesNeverCollideAndAHeadingUppercasesInItsOwnAlphabet(): void
    {
        $html = self::renderer()->render(new SidebarComponent(), new RenderRequest(new ComponentContext('s'), ['items' => [
            ['key' => 'a', 'group' => 'my lab'],
            ['key' => 'b', 'group' => 'my-lab'],
            ['key' => 'c', 'group' => 'año'],
        ]]))->output;

        self::assertSame(['AÑO', 'MY LAB', 'MY-LAB'], self::headings($html), 'ñ uppercases; the alphabet puts año before the m\'s');
        self::assertSame(3, preg_match_all('~<div class="mui-sidebar__section" role="group" aria-labelledby="([^"]*)" data-group="([^"]*)"><span class="mui-sidebar__section-label" id="([^"]*)">~', $html, $groups, PREG_SET_ORDER));
        self::assertSame([['s-group-0', 'año'], ['s-group-1', 'my lab'], ['s-group-2', 'my-lab']], array_map(static fn (array $g): array => [$g[1], $g[2]], $groups), 'one id per position; the raw name rides in data-group');
        foreach ($groups as $group) {
            self::assertSame($group[1], $group[3], 'the heading owns the id its group points at');
        }
        self::assertCount(3, array_unique(array_column($groups, 3)), 'no two headings share an id');
        self::assertSame(['#'], self::itemsUnder($html, 'my lab'));
        self::assertSame(['#'], self::itemsUnder($html, 'my-lab'), 'each group keeps its own item');
    }

    public function testTheHeaderNamesTheSectionAndWhoDeclaredIt(): void
    {
        $html = self::renderer()->render(new SectionHeaderComponent(), new RenderRequest(
            new ComponentContext('milpa-admin-header', locale: 'en'),
            ['title' => 'Agent', 'declaredBy' => 'Milpa\\DesktopApp\\DesktopAppPlugin'],
        ))->output;

        self::assertStringStartsWith(
            '<header class="mui-page-header admin-section__header" id="milpa-admin-header" data-milpa-component-id="milpa-admin-header"><div class="mui-page-header__text">'
            . '<h1 class="mui-page-header__title">Agent</h1>'
            . '<span class="admin-section__declared" data-declared-by="Milpa\\DesktopApp\\DesktopAppPlugin">declared by DesktopAppPlugin</span>'
            . '</div></header><script type="application/milpa+xhtml" data-milpa-state="milpa-admin-header">',
            $html,
            'the short name shown, the full class in the data attribute',
        );

        $es = self::renderer()->render(new SectionHeaderComponent(), new RenderRequest(new ComponentContext('h', locale: 'es'), ['title' => 'Agente', 'declaredBy' => 'Milpa\\DesktopApp\\DesktopAppPlugin']))->output;
        self::assertStringContainsString('<h1 class="mui-page-header__title">Agente</h1><span class="admin-section__declared" data-declared-by="Milpa\\DesktopApp\\DesktopAppPlugin">declarada por DesktopAppPlugin</span>', $es);

        $nobody = self::renderer()->render(new SectionHeaderComponent(), new RenderRequest(new ComponentContext('h'), ['title' => 'Hola <b>']))->output;
        self::assertStringContainsString('<h1 class="mui-page-header__title">Hola &lt;b&gt;</h1></div></header>', $nobody, 'the title is a value, never markup');
        self::assertStringNotContainsString('admin-section__declared', $nobody, 'no declaring class: no attribution, nothing invented');

        $global = self::renderer()->render(new SectionHeaderComponent(), new RenderRequest(new ComponentContext('h'), ['title' => 'X', 'declaredBy' => 'AppPlugin']))->output;
        self::assertStringContainsString('data-declared-by="AppPlugin">declared by AppPlugin</span>', $global, 'a class without a namespace is its own short name');

        $anonymous = self::renderer()->render(new SectionHeaderComponent(), new RenderRequest(new ComponentContext('h'), ['title' => 'X', 'declaredBy' => "Milpa\\Admin\\Section\\AdminSectionProvider@anonymous\0/srv/app/tests/Thing.php:82$0"]))->output;
        self::assertStringContainsString('data-declared-by="Milpa\\Admin\\Section\\AdminSectionProvider@anonymous">declared by AdminSectionProvider@anonymous</span>', $anonymous, 'an anonymous class reads as one — no NUL, no path');
        self::assertStringNotContainsString('/srv/app', $anonymous);

        $fr = self::renderer()->render(new SectionHeaderComponent(), new RenderRequest(new ComponentContext('h', locale: 'fr'), ['title' => 'X', 'declaredBy' => 'A\\B']))->output;
        self::assertStringContainsString('>declared by B</span>', $fr, 'a locale the catalog lacks answers in English');
    }

    public function testAGivenStateIsRenderedAsIsWithoutRemounting(): void
    {
        $state = new StateSnapshot('h', 'admin-section-header', '1', ['title' => 'From the state', 'declaredBy' => 'A\\Plug']);
        $result = self::renderer()->render(new SectionHeaderComponent(), new RenderRequest(new ComponentContext('h'), ['title' => 'From the props'], $state));

        self::assertSame($state, $result->state);
        self::assertStringContainsString('<h1 class="mui-page-header__title">From the state</h1>', $result->output);
        self::assertStringNotContainsString('From the props', $result->output);

        $sidebar = new StateSnapshot('s', 'admin-sidebar', '1', ['active' => 'k'], ['brand' => 'B', 'home' => '/b', 'groups' => [['key' => 'app', 'items' => [['key' => 'k', 'label' => 'K', 'href' => '/k', 'icon' => '']]], 'garbage', ['key' => 'x', 'items' => ['garbage']]]]);
        $html = self::renderer()->render(new SidebarComponent(), new RenderRequest(new ComponentContext('s'), ['items' => self::ITEMS], $sidebar))->output;
        self::assertSame(['APP', 'X'], self::headings($html));
        self::assertSame(['/k'], self::itemsUnder($html, 'app'));
        self::assertSame([], self::itemsUnder($html, 'x'), 'a group whose items are garbage paints its heading and nothing under it');
        self::assertStringContainsString('aria-current="page"><span class="mui-sidebar__item-icon" aria-hidden="true"></span><span class="mui-sidebar__item-label">K</span>', $html);
        self::assertStringNotContainsString('/milpa/admin/s/hola', $html, 'the props were not consulted');
    }

    public function testItRendersHtmlOnlyAndOnlyTheShellsOwnComponents(): void
    {
        $renderer = self::renderer();

        self::assertTrue($renderer->supportsTarget(RenderTarget::HTML));
        self::assertFalse($renderer->supportsTarget(RenderTarget::TUI));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('renders admin-sidebar and admin-section-header, not «echo-panel»');
        $renderer->render(new EchoComponent(), new RenderRequest(new ComponentContext('e'), ['text' => 'x']));
    }

    private static function renderer(): ShellHtmlRenderer
    {
        return new ShellHtmlRenderer(new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('test-secret-0123456789'), null));
    }
}
