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
use Milpa\Admin\Tests\Fixtures\EchoRenderer;
use Milpa\Admin\Tests\Fixtures\HolaPlugin;
use Milpa\Admin\Tests\Fixtures\RecordingDispatcher;
use Milpa\Admin\View\AdminShell;
use Milpa\Admin\View\ShellRender;
use Milpa\Container\DIContainer;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
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

    public function testEmptyStateStillWearsTheShell(): void
    {
        $html = self::shell()->renderEmpty(SectionCatalogue::discover([]));

        self::assertStringContainsString('class="mui-shell"', $html);
        self::assertStringContainsString('No plugin declared an admin section yet', $html);
    }

    private static function shell(?RecordingDispatcher $events = null): AdminShell
    {
        return new AdminShell(
            AdminSettings::fromConfig(null),
            new Catalog(),
            new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('test-secret-0123456789'), null),
            $events,
        );
    }
}
