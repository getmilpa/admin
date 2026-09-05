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

namespace Milpa\Admin\Tests\Components;

use Milpa\Admin\Components\ComponentBook;
use Milpa\Admin\Components\ReservedComponentException;
use Milpa\Admin\Components\SectionHeaderComponent;
use Milpa\Admin\Components\SidebarComponent;
use Milpa\Admin\Components\UnknownComponentException;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Tests\Fixtures\EchoComponent;
use Milpa\Admin\Tests\Fixtures\EchoRenderer;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use PHPUnit\Framework\TestCase;

final class ComponentBookTest extends TestCase
{
    public function testThePrimitivesAreThereAndCompile(): void
    {
        $book = self::book();

        self::assertContains('dashboard-shell', $book->names());
        self::assertContains('metric-card', $book->names());
        self::assertTrue($book->registry()->has('data-table'));
        self::assertSame(['admin-sidebar', 'admin-section-header'], \array_slice($book->names(), 11, 2), 'the shell\'s own two follow the eleven primitives');

        $header = $book->compiler(['admin-section-header' => ['title' => 'Echo', 'declaredBy' => 'Acme\\EchoPlugin']])
            ->compile('<milpa:admin-section-header id="h1"/>', new ComponentContext(componentId: 'test', locale: 'es'))
            ->output;
        self::assertStringContainsString('<h1 class="mui-page-header__title">Echo</h1><span class="admin-section__declared" data-declared-by="Acme\\EchoPlugin">declarada por EchoPlugin</span>', $header);
        self::assertStringContainsString('data-milpa-state="h1"', $header);

        $html = $book->compiler(['metric-card' => ['title' => 'Uptime', 'value' => '99.9%']])
            ->compile('<milpa:metric-card id="m1"/>', new ComponentContext(componentId: 'test'))
            ->output;

        self::assertStringContainsString('99.9%', $html);
        self::assertStringContainsString('security="signed"', $html);
    }

    public function testAdoptRegistersACustomComponent(): void
    {
        $book = self::book();
        $book->adopt(new AdminSection('echo', 'Echo', EchoComponent::NAME, definition: new EchoComponent(), renderer: new EchoRenderer()));

        self::assertTrue($book->registry()->has(EchoComponent::NAME));
        $html = $book->compiler([EchoComponent::NAME => ['text' => 'hi']])
            ->compile('<milpa:echo-panel id="e1"/>', new ComponentContext(componentId: 'test'))
            ->output;
        self::assertStringContainsString(EchoRenderer::MARKER, $html);
        self::assertStringContainsString('>hi<', $html);

        $book->adopt(new AdminSection('echo-too', 'Echo too', EchoComponent::NAME, definition: new EchoComponent(), renderer: new EchoRenderer()));
        self::assertTrue($book->registry()->has(EchoComponent::NAME), 'two sections sharing one custom name of their own is not a reservation: the name is theirs');
    }

    /**
     * The registry overwrites silently, so a section bringing its own definition under `admin-section-header` used
     * to repaint the host's header — attribution gone — on every page (greenhouse decisions/0210 review). The names
     * the book registers itself are the host's: a section may name one, never redefine one.
     */
    public function testAdoptRefusesASectionThatRedefinesAComponentThePanelRegistersItself(): void
    {
        $book = self::book();
        $book->adopt(new AdminSection('named', 'Named', SectionHeaderComponent::NAME));
        $book->adopt(new AdminSection('card', 'Card', 'metric-card'));

        try {
            $book->adopt(new AdminSection('hijack', 'Hijack', SectionHeaderComponent::NAME, definition: new EchoComponent(), renderer: new EchoRenderer()));
            self::fail('the header is the host\'s');
        } catch (ReservedComponentException $refused) {
            self::assertSame(
                'Admin section «hijack» brings its own definition under «admin-section-header», a component the panel registers itself. A section may name a registered component; it may not redefine one — pick a name of your own. The panel\'s are: dashboard-shell, dashboard-sidebar, dashboard-main, dashboard-topbar, dashboard-grid, dashboard-panel, dashboard-page-header, dashboard-action-button, dashboard-alert-list, metric-card, data-table, admin-sidebar, admin-section-header.',
                $refused->getMessage(),
            );
        }

        $header = $book->compiler([SectionHeaderComponent::NAME => ['title' => 'Plugins', 'declaredBy' => 'Milpa\\Admin\\AdminPlugin']])
            ->compile('<milpa:admin-section-header id="h"/>', new ComponentContext(componentId: 'test'))
            ->output;
        self::assertStringContainsString('declared by AdminPlugin</span>', $header, 'the attribution still paints: nothing was overwritten');
        self::assertStringNotContainsString(EchoRenderer::MARKER, $header);

        foreach ([SidebarComponent::NAME, 'metric-card', 'dashboard-shell'] as $host) {
            try {
                $book->adopt(new AdminSection('h', 'H', $host, definition: new EchoComponent(), renderer: new EchoRenderer()));
                self::fail($host . ' is the host\'s too');
            } catch (ReservedComponentException $refused) {
                self::assertStringContainsString('under «' . $host . '», a component the panel registers itself', $refused->getMessage());
            }
        }
        self::assertCount(13, $book->names(), 'the refusals registered nothing');
    }

    public function testRegisterIsTheOneWayIn(): void
    {
        $book = self::book();
        $book->register('mine', new EchoComponent(), new EchoRenderer());

        self::assertTrue($book->registry()->has('mine'));
        $names = $book->names();
        self::assertSame('mine', end($names), 'after the primitives and the shell\'s own');
        $book->adopt(new AdminSection('s', 'S', 'mine'));
        self::assertStringContainsString(EchoRenderer::MARKER, $book->compiler(['mine' => ['text' => 'x']])->compile('<milpa:mine id="m"/>', new ComponentContext(componentId: 'test'))->output);
    }

    public function testAdoptAcceptsARegisteredNameAndRefusesAnUnknownOne(): void
    {
        $book = self::book();
        $book->adopt(new AdminSection('ok', 'Ok', 'metric-card'));

        $this->expectException(UnknownComponentException::class);
        $this->expectExceptionMessage('name one of: dashboard-shell');
        $book->adopt(new AdminSection('bad', 'Bad', 'no-such-component'));
    }

    private static function book(): ComponentBook
    {
        return new ComponentBook(new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('test-secret-0123456789'), null));
    }
}
