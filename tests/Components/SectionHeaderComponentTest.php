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

use Milpa\Admin\Components\SectionHeaderComponent;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use PHPUnit\Framework\TestCase;

/**
 * The header the host puts above every section (greenhouse decisions/0210): the title, and the plugin the
 * catalogue says declared it.
 */
final class SectionHeaderComponentTest extends TestCase
{
    public function testTheContractDeclaresTitleAndDeclaredBy(): void
    {
        $contract = SectionHeaderComponent::contract();

        self::assertInstanceOf(ComponentDefinitionInterface::class, new SectionHeaderComponent());
        self::assertSame('admin-section-header', $contract->name);
        self::assertSame('1', $contract->contractVersion);
        self::assertSame(['title', 'declaredBy'], array_keys($contract->propsSchema));
        self::assertSame(['title', 'declaredBy'], array_keys($contract->stateSchema));
        self::assertSame([], $contract->actions, 'no action: the header shows');
    }

    public function testMountKeepsTheStringsAndNothingElse(): void
    {
        $state = (new SectionHeaderComponent())->mount(['title' => 'Agent', 'declaredBy' => 'Milpa\\DesktopApp\\DesktopAppPlugin'], new ComponentContext('milpa-admin-header'));

        self::assertSame('milpa-admin-header', $state->componentId);
        self::assertSame('admin-section-header', $state->componentName);
        self::assertSame('1', $state->version);
        self::assertSame(['title' => 'Agent', 'declaredBy' => 'Milpa\\DesktopApp\\DesktopAppPlugin'], $state->data);
        self::assertSame([], $state->meta);

        self::assertSame(['title' => '', 'declaredBy' => ''], (new SectionHeaderComponent())->mount([], new ComponentContext('h'))->data);
        self::assertSame(['title' => '', 'declaredBy' => ''], (new SectionHeaderComponent())->mount(['title' => 42, 'declaredBy' => null], new ComponentContext('h'))->data, 'a non-string is nothing, never invented');
    }

    public function testItDeclaresNoActionAndSaysSoInsteadOfThrowing(): void
    {
        $component = new SectionHeaderComponent();
        $state = $component->mount(['title' => 'x'], new ComponentContext('h'));

        $result = $component->handle(new InteractionRequest('h', 'admin-section-header', 'rename', $state, []));

        self::assertSame($state, $result->state);
        self::assertStringContainsString('declares no actions', $result->errors['action'] ?? '');
    }
}
