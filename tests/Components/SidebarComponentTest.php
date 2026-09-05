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

use Milpa\Admin\Components\SidebarComponent;
use Milpa\Admin\Section\AdminSection;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use PHPUnit\Framework\TestCase;

/**
 * The panel's sidebar component (greenhouse decisions/0210): flat items in, one group per distinct value out,
 * in the house order — admin, app, agent, then the rest alphabetically.
 */
final class SidebarComponentTest extends TestCase
{
    public function testTheContractDeclaresBrandHomeActiveAndItems(): void
    {
        $contract = SidebarComponent::contract();

        self::assertInstanceOf(ComponentDefinitionInterface::class, new SidebarComponent());
        self::assertSame('admin-sidebar', $contract->name);
        self::assertSame('1', $contract->contractVersion);
        self::assertSame(['brand', 'home', 'active', 'items'], array_keys($contract->propsSchema));
        self::assertSame(['type' => 'array', 'default' => []], $contract->propsSchema['items']);
        self::assertSame('/milpa/admin', $contract->propsSchema['home']['default']);
        self::assertSame(['active' => ['type' => 'string']], $contract->stateSchema);
        self::assertSame([], $contract->actions, 'no action: the anchors navigate');
        self::assertSame(['admin', 'app', 'agent'], SidebarComponent::GROUP_ORDER);
    }

    public function testMountGroupsTheItemsAndKeepsBrandHomeAndActive(): void
    {
        $state = (new SidebarComponent())->mount([
            'brand' => 'My Panel',
            'home' => '/panel',
            'active' => 'echo',
            'items' => [
                ['key' => 'hola', 'label' => 'Hola', 'href' => '/panel/s/hola', 'icon' => '✦'],
                ['key' => 'plugins', 'label' => 'Plugins', 'href' => '/panel/s/plugins', 'icon' => '', 'group' => AdminSection::GROUP_ADMIN],
                ['key' => 'echo', 'label' => 'Echo', 'href' => '/panel/s/echo', 'icon' => '', 'group' => AdminSection::GROUP_APP],
            ],
        ], new ComponentContext('milpa-admin-sidebar'));

        self::assertSame('milpa-admin-sidebar', $state->componentId);
        self::assertSame('admin-sidebar', $state->componentName);
        self::assertSame('1', $state->version);
        self::assertSame(['active' => 'echo'], $state->data);
        self::assertSame('My Panel', $state->meta['brand']);
        self::assertSame('/panel', $state->meta['home']);
        self::assertSame([
            ['key' => 'admin', 'items' => [['key' => 'plugins', 'label' => 'Plugins', 'href' => '/panel/s/plugins', 'icon' => '']]],
            ['key' => 'app', 'items' => [
                ['key' => 'hola', 'label' => 'Hola', 'href' => '/panel/s/hola', 'icon' => '✦'],
                ['key' => 'echo', 'label' => 'Echo', 'href' => '/panel/s/echo', 'icon' => ''],
            ]],
        ], $state->meta['groups'], 'admin before app, whatever order the items arrived in; an item without a group is the app\'s');

        $bare = (new SidebarComponent())->mount([], new ComponentContext('s'));
        self::assertSame(['active' => ''], $bare->data);
        self::assertSame(['brand' => 'Milpa Admin', 'home' => '/milpa/admin', 'groups' => []], $bare->meta, 'the defaults');
        self::assertSame('Milpa Admin', (new SidebarComponent())->mount(['brand' => '', 'home' => 42], new ComponentContext('s'))->meta['brand'], 'an empty or malformed prop is the default');
    }

    public function testGroupsFollowTheHouseOrderThenTheAlphabet(): void
    {
        $groups = SidebarComponent::groups([
            ['key' => 'z', 'group' => 'zeta'],
            ['key' => 'a1', 'group' => 'agent'],
            ['key' => 'b', 'group' => 'beta'],
            ['key' => 'p', 'group' => 'app'],
            ['key' => 'x', 'group' => 'admin'],
            ['key' => 'a2', 'group' => 'agent'],
            ['key' => 'n'],
            ['key' => 'w', 'group' => '  '],
        ]);

        self::assertSame(['admin', 'app', 'agent', 'beta', 'zeta'], array_column($groups, 'key'));
        self::assertSame(['x'], array_column($groups[0]['items'], 'key'));
        self::assertSame(['p', 'n', 'w'], array_column($groups[1]['items'], 'key'), 'no group, or a blank one, is the app\'s — in arrival order');
        self::assertSame(['a1', 'a2'], array_column($groups[2]['items'], 'key'), 'within a group, arrival order (the catalogue\'s: order, then id)');
        self::assertSame(['b'], array_column($groups[3]['items'], 'key'));
    }

    /** The rest sort by the alphabet, not by byte: a capital is not before every lowercase, ñ is a letter, a number stays a string. */
    public function testTheOtherGroupsSortCaseInsensitivelyInTheirOwnAlphabetAndANumericNameStaysAString(): void
    {
        $groups = SidebarComponent::groups([
            ['key' => 'z', 'group' => 'Zeta'],
            ['key' => 'a', 'group' => 'alpha'],
            ['key' => 'n', 'group' => 'año'],
            ['key' => 'b', 'group' => 'beta'],
            ['key' => 'seven', 'group' => '7'],
            ['key' => 'B', 'group' => 'Beta'],
        ]);

        self::assertSame(['7', 'alpha', 'año', 'Beta', 'beta', 'Zeta'], array_column($groups, 'key'), 'byte order would have been 7, Beta, Zeta, alpha, año, beta; two names equal but for case keep byte order between them');
        self::assertSame('7', $groups[0]['key'], 'a numeric group name is a string key, as the contract says — PHP would have made it an int');
        self::assertSame(['seven'], array_column($groups[0]['items'], 'key'));
    }

    public function testItemsAreNormalizedAndGarbageIsDropped(): void
    {
        $groups = SidebarComponent::groups([
            'seven' => ['label' => 'Seven'],
            ['key' => 'k'],
            'not an item',
            42,
            null,
        ]);

        self::assertCount(1, $groups);
        self::assertSame([
            ['key' => 'seven', 'label' => 'Seven', 'href' => '#', 'icon' => ''],
            ['key' => 'k', 'label' => 'k', 'href' => '#', 'icon' => ''],
        ], $groups[0]['items'], 'the key falls to the index, the label to the key, the href to #, the icon to nothing');

        self::assertSame([['key' => 'app', 'items' => [['key' => 'j', 'label' => 'J', 'href' => '/j', 'icon' => '']]]], SidebarComponent::groups('[{"key":"j","label":"J","href":"/j"}]'), 'a JSON list is accepted — a markup attribute');
        self::assertSame([], SidebarComponent::groups('{not json'));
        self::assertSame([], SidebarComponent::groups('"a string"'), 'JSON that is not a list is no item');
        self::assertSame([], SidebarComponent::groups(42));
        self::assertSame([], SidebarComponent::groups(null));
        self::assertSame([], SidebarComponent::groups([]));
    }

    public function testItDeclaresNoActionAndSaysSoInsteadOfThrowing(): void
    {
        $component = new SidebarComponent();
        $state = $component->mount(['active' => 'x'], new ComponentContext('s'));

        $result = $component->handle(new InteractionRequest('s', 'admin-sidebar', 'select', $state, ['key' => 'y']));

        self::assertSame($state, $result->state, 'the state is returned unchanged');
        self::assertStringContainsString('declares no actions', $result->errors['action'] ?? '');
    }
}
