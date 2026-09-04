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
