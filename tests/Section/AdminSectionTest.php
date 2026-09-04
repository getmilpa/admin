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

namespace Milpa\Admin\Tests\Section;

use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Tests\Fixtures\EchoComponent;
use Milpa\Admin\Tests\Fixtures\EchoRenderer;
use PHPUnit\Framework\TestCase;

final class AdminSectionTest extends TestCase
{
    public function testANamedComponentSectionIsNotCustom(): void
    {
        $section = new AdminSection(id: 'hola', title: 'Hola', component: 'metric-card', props: ['value' => '1']);

        self::assertFalse($section->isCustom());
        self::assertSame('app', $section->group);
        self::assertSame(0, $section->order);
    }

    public function testACustomSectionCarriesDefinitionAndRenderer(): void
    {
        $section = new AdminSection(id: 'echo', title: 'Echo', component: 'echo-panel', definition: new EchoComponent(), renderer: new EchoRenderer());

        self::assertTrue($section->isCustom());
    }

    public function testRejectsAnInvalidId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must match');

        new AdminSection(id: 'Hola Mundo', title: 'x', component: 'metric-card');
    }

    public function testRejectsAnEmptyComponent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('names no component');

        new AdminSection(id: 'x', title: 'x', component: ' ');
    }

    public function testRejectsADefinitionWithoutItsRenderer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('BOTH a definition and a renderer');

        new AdminSection(id: 'x', title: 'x', component: 'echo-panel', definition: new EchoComponent());
    }

    public function testRejectsTheReservedQueryPropTheShellFills(): void
    {
        self::assertSame(['query'], AdminSection::RESERVED_PROPS);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('declares the prop «query», which is reserved');

        new AdminSection(id: 'x', title: 'x', component: 'metric-card', props: ['query' => ['session' => 'mine']]);
    }
}
