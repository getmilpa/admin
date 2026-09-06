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
use Milpa\Admin\Section\DeclaredView;
use Milpa\Admin\Tests\Fixtures\EchoComponent;
use Milpa\Admin\Tests\Fixtures\EchoRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The contract a guest declares its own UI with (greenhouse decisions/0211): a tree, the definitions and
 * renderers it needs, the props per component and the seeds the page must carry.
 */
final class DeclaredViewTest extends TestCase
{
    public function testAViewCarriesATreeItsDefinitionsItsRenderersAndItsSeeds(): void
    {
        $view = new DeclaredView(
            markup: '<milpa:echo-panel id="a"/><milpa:echo-panel id="b"/>',
            definitions: [EchoComponent::NAME => new EchoComponent()],
            renderers: [EchoComponent::NAME => new EchoRenderer()],
            props: [EchoComponent::NAME => ['text' => 'hi']],
            signals: ['guest.open' => true],
            persist: ['guest.open', 'guest.open'],
            computed: ['guest.label' => ['template' => '{guest.open}']],
        );

        self::assertSame([EchoComponent::NAME], $view->names());
        self::assertFalse($view->seedsNothing());
        self::assertTrue((new DeclaredView('<milpa:echo-panel id="a"/>'))->seedsNothing());
        self::assertSame(['guest.open', 'guest.open'], $view->persist, 'the value object keeps what was declared; the merge is what deduplicates');
    }

    public function testASectionDeclaresAViewOrAComponentNeverBoth(): void
    {
        $view = new DeclaredView('<milpa:echo-panel id="a"/>');

        $section = AdminSection::ofView('lab', 'Lab', $view, order: 60, group: AdminSection::GROUP_AGENT, icon: '◈');
        self::assertTrue($section->hasView());
        self::assertFalse($section->isCustom());
        self::assertSame('', $section->component, 'a view names no single component');
        self::assertSame(60, $section->order);
        self::assertSame('◈', $section->icon);

        $narrow = new AdminSection('card', 'Card', 'metric-card', ['value' => '1']);
        self::assertFalse($narrow->hasView(), 'the narrow shape is untouched');

        try {
            new AdminSection('both', 'Both', 'metric-card', view: $view);
            self::fail('a section declares one shape or the other');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString('declares a view AND a component', $refused->getMessage());
        }

        try {
            new AdminSection('props', 'Props', props: ['value' => '1'], view: $view);
            self::fail('a view\'s props are per component');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString('section props would reach nothing', $refused->getMessage());
        }

        try {
            new AdminSection('nothing', 'Nothing');
            self::fail('neither shape is not a section');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString('names no component', $refused->getMessage());
        }
    }

    public function testAViewRefusesAnEmptyTreeAndDefinitionsAndRenderersThatDoNotPair(): void
    {
        try {
            new DeclaredView('   ');
            self::fail('a view with nothing to compile is not a view');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString('carries markup', $refused->getMessage());
        }

        try {
            new DeclaredView('<milpa:echo-panel id="a"/>', definitions: [EchoComponent::NAME => new EchoComponent()]);
            self::fail('a definition nothing paints cannot be mounted');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString('brings no renderer for it', $refused->getMessage());
        }

        try {
            new DeclaredView('<milpa:echo-panel id="a"/>', renderers: [EchoComponent::NAME => new EchoRenderer()]);
            self::fail('a renderer for a name nobody defines would never resolve');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString('defines no such component', $refused->getMessage());
        }

        try {
            new DeclaredView('<milpa:echo-panel id="a"/>', signals: [' ' => 1]);
            self::fail('a blank signal name is not a name');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString('names every signal', $refused->getMessage());
        }

        try {
            new DeclaredView('<milpa:echo-panel id="a"/>', persist: ['']);
            self::fail('a blank persisted name is not a name');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringContainsString('names every persisted signal', $refused->getMessage());
        }
    }
}
