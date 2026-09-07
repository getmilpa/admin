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

namespace Milpa\Admin\Tests;

use Milpa\Admin\Components\PluginsComponent;
use Milpa\Admin\Data\PluginsSource;
use Milpa\Container\DIContainer;
use Milpa\Live\ValueObjects\ActionContract;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The panel stops being only a viewer — and does it without stepping around the gate.
 *
 * Every component in this package declared zero actions and refused every one: the panel showed the capability
 * catalogue and could not enable anything, so an app that declines the agent could see what it might grow and
 * had no way to grow it (greenhouse decisions/0220, evidence/0549).
 */
#[CoversClass(PluginsComponent::class)]
final class ThePanelCanEnableACapabilityTest extends TestCase
{
    public function testTheSectionDeclaresAnActionThatNamesItsOperation(): void
    {
        $action = PluginsComponent::contract()->action('enable');

        self::assertInstanceOf(ActionContract::class, $action);
        self::assertTrue($action->mutating);
        self::assertSame('capabilities:enable', $action->invokes);
        self::assertSame('capability', $action->namedTarget);
    }

    public function testTheActionDoesNotRESTATETheOperationsEffectProfile(): void
    {
        // The operation declares Persistent, ThirdParty, ManualRecovery and Privileged authority. A copy here
        // would be a second source of truth about one act, and the gate reads the operation's — so the copy
        // could only ever become the wrong one.
        $action = PluginsComponent::contract()->action('enable');

        self::assertNotNull($action);
        self::assertFalse($action->declaresEffects(), 'the profile belongs to the operation');
        self::assertNull($action->effects);
    }

    public function testTheComponentDOESNOTRunTheOperation(): void
    {
        // Running it here would step around the confirmation the operation demands over HTTP. The component
        // declares the button; the act travels the operation's own governed surface.
        $component = new PluginsComponent(new PluginsSource(new DIContainer()));
        $state = $component->mount([], new ComponentContext('p', '/admin'));

        $result = $component->handle(new InteractionRequest('p', PluginsComponent::NAME, 'enable', $state, [
            'capability' => 'milpa/agent',
        ]));

        self::assertArrayHasKey('action', $result->errors);
        self::assertStringContainsString('capabilities:enable', $result->errors['action']);
        self::assertStringContainsString('confirmation', $result->errors['action']);
    }

    public function testAnActionNobodyDeclaredIsStillRefusedByName(): void
    {
        $component = new PluginsComponent(new PluginsSource(new DIContainer()));
        $state = $component->mount([], new ComponentContext('p', '/admin'));

        $result = $component->handle(new InteractionRequest('p', PluginsComponent::NAME, 'nope', $state, []));

        self::assertStringContainsString('declares no action named «nope»', $result->errors['action'] ?? '');
    }
}
