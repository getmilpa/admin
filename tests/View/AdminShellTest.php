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
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Admin\Section\SectionRender;
use Milpa\Admin\Tests\Fixtures\AllowAllMiddleware;
use Milpa\Admin\Tests\Fixtures\EchoRenderer;
use Milpa\Admin\Tests\Fixtures\HolaPlugin;
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
        self::assertStringContainsString('data-injected="section"', $html, 'after_render changed the section html');
        self::assertStringContainsString('href="/extra"', $html, 'shell before_render added a nav item');
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
        self::assertStringContainsString('No plugin declared an admin section yet', $html);
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
