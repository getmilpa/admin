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
use Milpa\Admin\Section\AdminSectionProvider;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Admin\Section\SectionRender;
use Milpa\Admin\Tests\Fixtures\AllowAllMiddleware;
use Milpa\Admin\Tests\Fixtures\EchoComponent;
use Milpa\Admin\Tests\Fixtures\EchoRenderer;
use Milpa\Admin\Tests\Fixtures\GuestPlugin;
use Milpa\Admin\Tests\Fixtures\HolaPlugin;
use Milpa\Admin\Tests\Fixtures\ReadsSidebar;
use Milpa\Admin\Tests\Fixtures\RecordingDispatcher;
use Milpa\Admin\View\AdminShell;
use Milpa\Admin\View\ShellRender;
use Milpa\Container\DIContainer;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Runtime\Config;
use PHPUnit\Framework\TestCase;

final class AdminShellTest extends TestCase
{
    use ReadsSidebar;

    public function testComposesTheShellAroundAForeignPrimitiveSection(): void
    {
        $catalogue = SectionCatalogue::discover([new HolaPlugin(new DIContainer())]);
        $active = $catalogue->find('hola');
        self::assertNotNull($active);

        $html = self::shell()->render($catalogue, $active);

        self::assertStringContainsString('class="mui-shell"', $html);
        self::assertStringContainsString('mui-sidebar', $html);
        self::assertStringContainsString('href="/milpa/admin/s/hola"', $html);
        self::assertStringContainsString('href="/milpa/admin/s/echo"', $html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringContainsString('42', $html, 'the metric-card mounted with the section props');
        self::assertStringContainsString('id="milpa-admin-section-hola"', $html);
        self::assertStringContainsString('security="signed"', $html);
    }

    public function testRendersAForeignCustomComponentAndTitlesFromCatalogOrLiteral(): void
    {
        $catalogue = SectionCatalogue::discover([new HolaPlugin(new DIContainer())]);
        $shell = self::shell();
        $echo = $catalogue->find('echo');
        self::assertNotNull($echo);

        $html = $shell->render($catalogue, $echo);

        self::assertStringContainsString(EchoRenderer::MARKER, $html);
        self::assertStringContainsString('hola desde un plugin ajeno', $html);
        self::assertSame('Echo', $shell->title($echo), 'a literal title stays literal');
        self::assertSame('Plugins', $shell->title(new AdminSection('p', 'nav.plugins', 'metric-card')), 'a catalog key is translated');
    }

    public function testLifecycleEventsFireAndCanMutatePropsAndHtml(): void
    {
        $events = new RecordingDispatcher();
        $events->subscribe(AdminShell::SECTION_BEFORE_RENDER, static function (string $name, array $payload): void {
            $subject = $payload['section'];
            \assert($subject instanceof SectionRender);
            $subject->props['value'] = '777';
        });
        $events->subscribe(AdminShell::SECTION_AFTER_RENDER, static function (string $name, array $payload): void {
            $subject = $payload['section'];
            \assert($subject instanceof SectionRender);
            $subject->html .= '<p data-injected="section">after</p>';
        });
        $events->subscribe(AdminShell::BEFORE_RENDER, static function (string $name, array $payload): void {
            $subject = $payload['shell'];
            \assert($subject instanceof ShellRender);
            $subject->items[] = ['key' => 'extra', 'label' => 'Extra', 'href' => '/extra', 'icon' => ''];
            $subject->items[] = ['key' => 'watch', 'label' => 'Watch', 'href' => '/watch', 'icon' => '◉', 'group' => AdminSection::GROUP_AGENT];
        });
        $events->subscribe(AdminShell::AFTER_RENDER, static function (string $name, array $payload): void {
            $subject = $payload['shell'];
            \assert($subject instanceof ShellRender);
            $subject->html .= '<!-- shell:after -->';
        });

        $catalogue = SectionCatalogue::discover([new HolaPlugin(new DIContainer())]);
        $active = $catalogue->find('hola');
        self::assertNotNull($active);
        $html = self::shell($events)->render($catalogue, $active);

        self::assertStringContainsString('777', $html, 'before_render changed the props');
        self::assertStringNotContainsString('>42<', $html);
        self::assertStringContainsString('data-injected="section">after</p></div>', $html, 'after_render changed the section html — and what it added lands inside the section body');
        self::assertStringContainsString('href="/extra"', $html, 'shell before_render added a nav item');
        $nav = self::sidebar($html);
        self::assertSame(['/milpa/admin/s/hola', '/milpa/admin/s/echo', '/extra'], self::itemsUnder($nav, 'app'), 'an added item without a group is the app\'s, after the sections');
        self::assertSame(['/watch'], self::itemsUnder($nav, 'agent'), 'an added item names its group and lists under it');
        self::assertStringContainsString('href="/watch"><span class="mui-sidebar__item-icon" aria-hidden="true">◉</span>', $nav);
        self::assertStringContainsString('<!-- shell:after -->', $html);
        self::assertSame(
            [AdminShell::SECTION_BEFORE_RENDER, AdminShell::SECTION_AFTER_RENDER, AdminShell::BEFORE_RENDER, AdminShell::AFTER_RENDER],
            array_values(array_filter($events->dispatched, static fn (string $e): bool => str_starts_with($e, 'admin.'))),
        );
    }

    public function testTheRequestQueryReachesTheActiveSectionAsItsQueryProp(): void
    {
        $seen = [];
        $events = new RecordingDispatcher();
        $events->subscribe(AdminShell::SECTION_BEFORE_RENDER, static function (string $name, array $payload) use (&$seen): void {
            $subject = $payload['section'];
            \assert($subject instanceof SectionRender);
            $seen[] = $subject->props;
        });
        $catalogue = SectionCatalogue::discover([new HolaPlugin(new DIContainer())]);
        $active = $catalogue->find('hola');
        self::assertNotNull($active);

        self::shell($events)->render($catalogue, $active, ['session' => 's-1', 'lang' => 'es']);
        self::shell($events)->render($catalogue, $active);

        self::assertCount(2, $seen);
        self::assertSame(['session' => 's-1', 'lang' => 'es'], $seen[0]['query'], 'the query travels whole: the section decides what it means');
        self::assertSame('42', $seen[0]['value'] ?? null, 'the declared props are still there');
        self::assertSame([], $seen[1]['query'], 'no query is an empty array, never a missing prop');
    }

    public function testEmptyStateStillWearsTheShell(): void
    {
        $html = self::shell()->renderEmpty(SectionCatalogue::discover([]));

        self::assertStringContainsString('class="mui-shell"', $html);
        self::assertStringContainsString('<div class="admin-section__body"><p class="mui-alert mui-alert--info admin-notice">No plugin declared an admin section yet', $html, 'the notice sits in the section body like a section would — a plain wrapper: no header names it, nothing scrolls');
        self::assertStringNotContainsString('role="region"', $html, 'no unnamed region');
        $nav = self::sidebar($html);
        self::assertStringContainsString('<a class="mui-sidebar__brand" href="/milpa/admin"><span class="mui-sidebar__wordmark">Milpa Admin</span></a>', $nav, 'the brand links home');
        self::assertSame([], self::headings($nav), 'no section: no group, not even an empty one');
        self::assertStringNotContainsString('admin-section__header', $html, 'no section: no header');
    }

    /**
     * greenhouse decisions/0210: the sidebar lists one group per distinct `group` value — admin, app, agent, then
     * any other alphabetically — each with its heading from the catalog (an unknown group: its name uppercased),
     * the items under it in the catalogue's order, the glyph a section declared painted.
     */
    public function testTheSidebarListsEverySectionUnderItsGroupInTheHouseOrder(): void
    {
        $catalogue = SectionCatalogue::discover([
            new GuestPlugin(new DIContainer()),
            new HolaPlugin(new DIContainer()),
            self::provider([
                new AdminSection('routes', 'nav.routes', 'metric-card', order: 20, group: AdminSection::GROUP_ADMIN),
                new AdminSection('plugins', 'nav.plugins', 'metric-card', order: 10, group: AdminSection::GROUP_ADMIN),
            ]),
            self::provider([
                new AdminSection('zeta', 'Zeta', 'metric-card', order: 1, group: 'zeta'),
                new AdminSection('beta', 'Beta', 'metric-card', order: 1, group: 'beta'),
            ]),
        ]);
        $active = $catalogue->find('agent');
        self::assertNotNull($active);

        $html = self::shell()->render($catalogue, $active);
        $nav = self::sidebar($html);

        self::assertSame(['ADMIN', 'APP', 'AGENT', 'BETA', 'LAB', 'ZETA'], self::headings($nav), 'the house order, then the alphabet');
        self::assertSame(['/milpa/admin/s/plugins', '/milpa/admin/s/routes'], self::itemsUnder($nav, 'admin'), 'by order within the group');
        self::assertSame(['/milpa/admin/s/hola', '/milpa/admin/s/echo'], self::itemsUnder($nav, 'app'));
        self::assertSame(['/milpa/admin/s/agent'], self::itemsUnder($nav, 'agent'));
        self::assertSame(['/milpa/admin/s/beta'], self::itemsUnder($nav, 'beta'));
        self::assertSame(['/milpa/admin/s/lab'], self::itemsUnder($nav, 'lab'));
        self::assertSame(['/milpa/admin/s/zeta'], self::itemsUnder($nav, 'zeta'));
        self::assertStringContainsString(
            '<div class="mui-sidebar__section" role="group" aria-labelledby="milpa-admin-sidebar-group-2" data-group="agent"><span class="mui-sidebar__section-label" id="milpa-admin-sidebar-group-2">AGENT</span>'
            . '<a class="mui-sidebar__item" href="/milpa/admin/s/agent" aria-current="page"><span class="mui-sidebar__item-icon" aria-hidden="true">◈</span><span class="mui-sidebar__item-label">Agent</span></a></div>',
            $nav,
            'the guest under AGENT, its glyph painted, the active item marked — the primitive\'s item markup kept',
        );
        self::assertStringContainsString('aria-hidden="true">✦</span><span class="mui-sidebar__item-label">Hola</span>', $nav, 'a glyph the primitive used to drop');
        self::assertStringContainsString('<span class="mui-sidebar__item-label">Routes</span>', $nav, 'a catalog key is translated');
        self::assertStringNotContainsString('cultivo', $html, 'the primitive\'s literal heading is gone');
        self::assertStringContainsString('<a class="mui-sidebar__brand" href="/milpa/admin"><span class="mui-sidebar__wordmark">Milpa Admin</span></a>', $nav);
        self::assertStringContainsString('aria-controls="milpa-admin-sidebar"', $html, 'the topbar toggle still points at the sidebar');

        $es = self::sidebar(self::shell(catalog: new Catalog('es'))->render($catalogue, $active));
        self::assertSame(['ADMIN', 'APP', 'AGENTE', 'BETA', 'LAB', 'ZETA'], self::headings($es));
        self::assertStringContainsString('aria-label="Secciones"', $es);
        self::assertStringContainsString('<span class="mui-sidebar__item-label">Rutas</span>', $es);
    }

    /**
     * greenhouse decisions/0210: the host paints the attribution — the header of EVERY section says «declared by
     * <Plugin>», read from the catalogue; a section never names itself.
     */
    public function testTheHeaderAboveEverySectionSaysWhoDeclaredIt(): void
    {
        $catalogue = SectionCatalogue::discover([new HolaPlugin(new DIContainer())]);
        $hola = $catalogue->find('hola');
        self::assertNotNull($hola);

        $html = self::shell()->render($catalogue, $hola);

        self::assertStringContainsString(
            '<header class="mui-page-header admin-section__header" id="milpa-admin-header" data-milpa-component-id="milpa-admin-header"><div class="mui-page-header__text">'
            . '<h1 class="mui-page-header__title">Hola</h1>'
            . '<span class="admin-section__declared" data-declared-by="Milpa\\Admin\\Tests\\Fixtures\\HolaPlugin">declared by HolaPlugin</span>'
            . '</div></header>',
            $html,
            'the short name shown, the class in the data attribute',
        );
        self::assertStringContainsString('data-milpa-state="milpa-admin-header"', $html, 'the header is a component with its signed envelope');
        $main = strpos($html, '<main ');
        $header = strpos($html, 'admin-section__header');
        $body = strpos($html, '<div class="admin-section__body" tabindex="0" role="region" aria-labelledby="milpa-admin-header">');
        $section = strpos($html, 'id="milpa-admin-section-hola"');
        $bodyEnd = strpos($html, '</div></main>');
        self::assertNotFalse($main);
        self::assertNotFalse($header);
        self::assertNotFalse($body, 'the body is a keyboard scroller — tabindex, a region — named by the header above it');
        self::assertNotFalse($section);
        self::assertNotFalse($bodyEnd);
        self::assertTrue($main < $header && $header < $body && $body < $section && $section < $bodyEnd, 'main opens, then the header, then the body that wraps the section and closes with main');
        self::assertSame(1, substr_count($html, 'admin-section__body'), 'one body: the shell\'s, never the section\'s');
        self::assertSame(1, substr_count($html, '<header class="mui-page-header admin-section__header" id="milpa-admin-header"'), 'the region\'s label resolves: exactly one element carries that id (the state envelope repeats it inside a script, never as an element)');

        self::assertStringContainsString('>declarada por HolaPlugin</span>', self::shell(catalog: new Catalog('es'))->render($catalogue, $hola));

        $orphan = new AdminSection('orphan', 'nav.routes', 'metric-card');
        $html = self::shell()->render($catalogue, $orphan);
        self::assertStringContainsString('<h1 class="mui-page-header__title">Routes</h1></div></header>', $html, 'a catalog key is translated');
        self::assertStringNotContainsString('admin-section__declared', $html, 'the panel attributes nothing it cannot read from the catalogue');
    }

    /**
     * greenhouse decisions/0210, sharpened by the first real guest: the principal the topbar shows reaches the
     * section's ComponentContext — so a guest that decides its state by the context agrees with the topbar. The
     * control: nobody signed in is null, never a placeholder.
     */
    public function testThePrincipalTheTopbarShowsReachesEverySectionsContext(): void
    {
        $catalogue = SectionCatalogue::discover([new HolaPlugin(new DIContainer())]);
        $echo = $catalogue->find('echo');
        self::assertNotNull($echo);
        EchoComponent::$lastContext = null;

        $html = self::shell()->render($catalogue, $echo, ['session' => 's-1'], 'passkey:rod');

        $context = EchoComponent::$lastContext;
        self::assertNotNull($context, 'the foreign component mounted');
        self::assertSame('passkey:rod', $context->principal, 'the same actor the topbar says');
        self::assertSame('milpa-admin-section-echo', $context->componentId);
        self::assertSame('en', $context->locale);
        self::assertSame('/milpa/admin', $context->route);
        self::assertStringContainsString('data-principal="passkey:rod">signed in as passkey:rod</span>', $html, 'and the topbar agrees');

        self::shell(settings: AdminSettings::fromConfig(new Config(['admin' => ['route' => '/panel']])), catalog: new Catalog('es'))->render($catalogue, $echo);
        $context = EchoComponent::$lastContext;
        self::assertNotNull($context);
        self::assertNull($context->principal, 'nobody signed in: null');
        self::assertSame('es', $context->locale, 'the locale the page answers in');
        self::assertSame('/panel', $context->route, 'the panel\'s mount point');
    }

    /** @param list<AdminSection> $sections */
    private static function provider(array $sections): AdminSectionProvider
    {
        return new class ($sections) implements AdminSectionProvider {
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

    public function testTopbarChipsSayTheGateInEffectAndTheLocale(): void
    {
        $catalogue = SectionCatalogue::discover([new HolaPlugin(new DIContainer())]);
        $active = $catalogue->find('hola');
        self::assertNotNull($active);

        $html = self::shell()->render($catalogue, $active);
        self::assertStringContainsString('<div class="mui-topbar__end"><span class="mui-badge admin-chip admin-chip--gate" data-gate="loopback">gate: loopback</span><span class="mui-badge admin-chip admin-chip--locale" data-locale="en">en</span></div>', $html);

        $fallback = self::shell(settings: AdminSettings::fromConfig(new Config(['admin' => ['middleware' => ['Acme\\Nope']]])), catalog: new Catalog('es'));
        $html = $fallback->render($catalogue, $active);
        self::assertStringContainsString('<span class="mui-badge admin-chip admin-chip--gate mui-badge--warning" data-gate="fallback">puerta: respaldo</span>', $html, 'a misdeclared gate is something to fix');
        self::assertStringContainsString('<span class="mui-badge admin-chip admin-chip--locale" data-locale="es">es</span>', $html);
        self::assertStringContainsString('data-gate="fallback"', $fallback->renderEmpty(SectionCatalogue::discover([])), 'the empty state wears the chips too');

        self::assertStringContainsString('data-gate="open">gate: open</span>', self::shell(settings: AdminSettings::fromConfig(new Config(['admin' => ['middleware' => []]])))->render($catalogue, $active));
        self::assertStringContainsString('data-gate="custom">gate: custom</span>', self::shell(settings: new AdminSettings(middleware: [AllowAllMiddleware::class]))->render($catalogue, $active));
        self::assertStringContainsString('mui-badge--warning" data-gate="fallback">gate: fallback</span>', self::shell(settings: new AdminSettings(middleware: [\stdClass::class]))->render($catalogue, $active), 'a class that exists but is not a middleware is not a gate');
    }

    public function testTheGateChipNamesThePasskeyGateWhenItIsTheWholeStack(): void
    {
        $catalogue = SectionCatalogue::discover([new HolaPlugin(new DIContainer())]);
        $active = $catalogue->find('hola');
        self::assertNotNull($active);

        $passkey = self::shell(settings: new AdminSettings(middleware: [AdminSettings::PASSKEY_GATE]));
        self::assertStringContainsString('<span class="mui-badge admin-chip admin-chip--gate" data-gate="passkey">gate: passkey</span>', $passkey->render($catalogue, $active), 'not «custom»: the panel knows this one by name');
        self::assertStringContainsString('data-gate="passkey">puerta: passkey</span>', $passkey->withCatalog(new Catalog('es'))->render($catalogue, $active));
        self::assertStringContainsString('data-gate="passkey">gate: passkey</span>', $passkey->renderEmpty(SectionCatalogue::discover([])), 'the empty state too');

        self::assertStringContainsString('data-gate="custom">gate: custom</span>', self::shell(settings: new AdminSettings(middleware: [AdminSettings::PASSKEY_GATE, AllowAllMiddleware::class]))->render($catalogue, $active), 'the control: inside a bigger stack it is a custom stack');
    }

    public function testTheTopbarSaysWhoSignedInWhenAGateAuthenticatedTheRequestAndNothingOtherwise(): void
    {
        $catalogue = SectionCatalogue::discover([new HolaPlugin(new DIContainer())]);
        $active = $catalogue->find('hola');
        self::assertNotNull($active);
        $shell = self::shell(settings: new AdminSettings(middleware: [AdminSettings::PASSKEY_GATE]));

        $html = $shell->render($catalogue, $active, [], 'passkey:rod');
        self::assertStringContainsString(
            '<div class="mui-topbar__end"><span class="mui-badge admin-chip admin-chip--principal" title="signed in as passkey:rod" data-principal="passkey:rod">signed in as passkey:rod</span><span class="mui-badge admin-chip admin-chip--gate" data-gate="passkey">gate: passkey</span><span class="mui-badge admin-chip admin-chip--locale" data-locale="en">en</span></div>',
            $html,
            'who, then the gate, then the locale — the whole «who» in title too, because the chip truncates a long id',
        );
        self::assertStringContainsString('title="sesión iniciada como passkey:rod" data-principal="passkey:rod">sesión iniciada como passkey:rod</span>', $shell->withCatalog(new Catalog('es'))->render($catalogue, $active, [], 'passkey:rod'), 'the title speaks the catalog\'s language too');
        self::assertStringContainsString('data-principal="passkey:rod">sesión iniciada como passkey:rod</span>', $shell->withCatalog(new Catalog('es'))->render($catalogue, $active, [], 'passkey:rod'));
        self::assertStringContainsString('data-principal="passkey:rod">signed in as passkey:rod</span>', $shell->renderEmpty(SectionCatalogue::discover([]), 'passkey:rod'), 'the empty state wears it too');
        self::assertStringContainsString('title="signed in as &lt;b&gt;x&quot;" data-principal="&lt;b&gt;x&quot;">signed in as &lt;b&gt;x&quot;</span>', $shell->render($catalogue, $active, [], '<b>x"'), 'a principal is a value, never markup — in the title too');

        $nobody = $shell->render($catalogue, $active);
        self::assertStringContainsString('<div class="mui-topbar__end"><span class="mui-badge admin-chip admin-chip--gate"', $nobody, 'no principal: the gate chip comes first');
        self::assertStringNotContainsString('admin-chip--principal', $nobody);
        self::assertStringNotContainsString('signed in as', $nobody, 'the panel invents no identity');
        self::assertStringNotContainsString('admin-chip--principal', $shell->renderEmpty(SectionCatalogue::discover([])));
    }

    public function testWithCatalogAnswersInAnotherLanguageAndLeavesTheOriginalAlone(): void
    {
        $shell = self::shell();
        $spanish = $shell->withCatalog(new Catalog('es'));
        $routes = new AdminSection('r', 'nav.routes', 'metric-card');

        self::assertSame('Rutas', $spanish->title($routes));
        self::assertSame('Routes', $shell->title($routes));

        $catalogue = SectionCatalogue::discover([new HolaPlugin(new DIContainer())]);
        $active = $catalogue->find('hola');
        self::assertNotNull($active);
        self::assertStringContainsString('data-locale="es">es</span>', $spanish->render($catalogue, $active));
        self::assertStringContainsString('Ningún plugin declaró todavía', $spanish->renderEmpty(SectionCatalogue::discover([])));
    }

    private static function shell(?RecordingDispatcher $events = null, ?AdminSettings $settings = null, ?Catalog $catalog = null): AdminShell
    {
        return new AdminShell(
            $settings ?? AdminSettings::fromConfig(null),
            $catalog ?? new Catalog(),
            new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('test-secret-0123456789'), null),
            $events,
        );
    }
}
