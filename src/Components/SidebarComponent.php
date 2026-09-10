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

namespace Milpa\Admin\Components;

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Section\AdminSection;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The panel's sidebar as a Milpa Component: the brand, and every discovered section listed under its group
 * (greenhouse decisions/0210, wireframe 1a of decisions/0203: `ADMIN / APP / AGENT`).
 *
 * The panel composes its own sidebar instead of `milpa/live`'s `dashboard-sidebar` because that primitive
 * has no group: its template lists every item under one literal heading, and its mount normalizes each item
 * to `{key, label, href}` — the glyph a section declares never reaches the renderer. This component takes
 * the same flat `items` (each with an optional `group`, and the `icon`) and groups them at mount: one group
 * per distinct value, in the house order — {@see AdminSection::GROUP_ADMIN}, {@see AdminSection::GROUP_APP},
 * {@see AdminSection::GROUP_AGENT}, then any other name alphabetically (case-insensitively and in its own
 * alphabet: `año` sorts among the a's, `Zeta` after `beta`, never by byte). Within a group the items keep
 * the order they arrived in, which is the catalogue's (`order`, then `id`); an item with no group is the app's.
 *
 * It has no actions: navigating is the anchor's job, and the active item is a prop the shell sets.
 */
final class SidebarComponent implements ComponentDefinitionInterface
{
    public const NAME = 'admin-sidebar';
    public const VERSION = '1';

    /** The groups with a fixed place, in that order; any other group follows them, alphabetically. */
    public const GROUP_ORDER = [AdminSection::GROUP_ADMIN, AdminSection::GROUP_APP, AdminSection::GROUP_AGENT];

    /** The contract: brand, the panel's home, the active key and the flat items to group. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: self::VERSION,
            summary: "The admin panel's sidebar: the brand, and every discovered section under its group.",
            designContract: '@milpa/design:components/milpa-sidebar.contract.json',
            propsSchema: [
                'brand' => ['type' => 'string', 'default' => AdminSettings::DEFAULT_TITLE],
                'home' => ['type' => 'string', 'default' => AdminSettings::DEFAULT_ROUTE],
                'wordmark' => ['type' => 'string', 'default' => ''],
                'active' => ['type' => 'string', 'default' => ''],
                'items' => ['type' => 'array', 'default' => []],
                'versions' => ['type' => 'array', 'default' => []],
                'versionsHref' => ['type' => 'string', 'default' => ''],
                'versionsRest' => ['type' => 'int', 'default' => 0],
            ],
            stateSchema: ['active' => ['type' => 'string']],
        );
    }

    /** Mount: the active key is the state; the brand, the home link and the grouped items ride in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            self::NAME,
            self::VERSION,
            ['active' => (string) ($props['active'] ?? '')],
            [
                'brand' => self::string($props, 'brand', AdminSettings::DEFAULT_TITLE),
                'home' => self::string($props, 'home', AdminSettings::DEFAULT_ROUTE),
                // Where the vector lives on THIS panel's route, so the brand can be the mark the
                // logo kit mandates instead of the letters a span approximates.
                'wordmark' => self::string($props, 'wordmark', ''),
                'groups' => self::groups($props['items'] ?? []),
                // WHAT THIS PANEL IS RUNNING, in the footer slot the primitive has always had and
                // nobody filled. A person looking at a panel cannot tell which version of it they are
                // looking at, and «which admin am I on» is the first question a bug report needs
                // answered (greenhouse decisions/0269).
                'versions' => self::versions($props['versions'] ?? []),
                'versionsHref' => self::string($props, 'versionsHref', ''),
                // How many more the app runs than the footer names — a count that links, not a
                // second list of what another section already lists.
                'versionsRest' => max(0, (int) ($props['versionsRest'] ?? 0)),
            ],
        );
    }

    /** No action is declared: the anchors navigate, the shell names the active item. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(
            state: $request->state,
            errors: ['action' => \sprintf('«%s» declares no actions: the sidebar lists, the anchors navigate.', self::NAME)],
        );
    }

    /**
     * The flat items grouped: one entry per distinct `group` value (an item without one is the app's), in
     * the house order — admin, app, agent, then the rest alphabetically, case-insensitively and in their own
     * alphabet (two names equal but for case keep byte order between them) — each carrying its items in the
     * order they arrived. A group's key is always a string, a numeric name included (PHP would have made
     * it an int key). A JSON-encoded list is accepted like an array (a markup attribute); anything that is
     * not a list of arrays is no item.
     *
     * @return list<array{key: string, items: list<array{key: string, label: string, href: string, icon: string, children: list<array{key: string, label: string, href: string, icon: string}>}>}>
     */
    public static function groups(mixed $items): array
    {
        if (\is_string($items)) {
            $decoded = json_decode($items, true);
            $items = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($items)) {
            return [];
        }

        $byGroup = [];
        foreach ($items as $index => $item) {
            if (!\is_array($item)) {
                continue;
            }
            $group = trim((string) ($item['group'] ?? ''));
            $key = (string) ($item['key'] ?? $index);
            $byGroup[$group === '' ? AdminSection::GROUP_APP : $group][] = [
                'key' => $key,
                'label' => (string) ($item['label'] ?? $key),
                'href' => (string) ($item['href'] ?? '#'),
                'icon' => (string) ($item['icon'] ?? ''),
                // 🚨 NORMALISED HERE OR LOST HERE. This method rebuilds every item from named keys, so
                // anything it does not name is dropped — which is what would have happened to the
                // sections declared under this one, silently, leaving a gear with nothing behind it.
                'children' => self::children($item['children'] ?? []),
            ];
        }

        $names = array_map(static fn (int|string $name): string => (string) $name, array_keys($byGroup));
        usort($names, static fn (string $a, string $b): int => [self::rank($a), mb_strtolower($a, 'UTF-8'), $a] <=> [self::rank($b), mb_strtolower($b, 'UTF-8'), $b]);

        $groups = [];
        foreach ($names as $name) {
            $groups[] = ['key' => $name, 'items' => $byGroup[$name]];
        }

        return $groups;
    }

    /**
     * The sections declared under an item, normalised the same way the item itself is.
     *
     * A child carries no `group`: it lists behind its parent's gear, not in a heading of its own. It
     * carries no children either — sections nest one level, which the catalogue refuses to exceed.
     *
     * @return list<array{key: string, label: string, href: string, icon: string}>
     */
    private static function children(mixed $children): array
    {
        if (!\is_array($children)) {
            return [];
        }
        $out = [];
        foreach ($children as $index => $child) {
            if (!\is_array($child)) {
                continue;
            }
            $key = (string) ($child['key'] ?? $index);
            $out[] = [
                'key' => $key,
                'label' => (string) ($child['label'] ?? $key),
                'href' => (string) ($child['href'] ?? '#'),
                'icon' => (string) ($child['icon'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The versions the footer shows, normalised.
     *
     * A row with no version is DROPPED rather than printed with a placeholder: «this app does not have
     * that package» and «it has it at a version nobody could read» are different facts, and a footer
     * that showed a dash for the first would say the app runs something it does not.
     *
     * @return list<array{name: string, version: string}>
     */
    private static function versions(mixed $versions): array
    {
        if (\is_string($versions)) {
            $decoded = json_decode($versions, true);
            $versions = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($versions)) {
            return [];
        }
        $out = [];
        foreach ($versions as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $version = trim((string) ($row['version'] ?? ''));
            if ($name === '' || $version === '') {
                continue;
            }
            $out[] = ['name' => $name, 'version' => $version];
        }

        return $out;
    }

    /** The place of a group: its index in {@see self::GROUP_ORDER}, or after every one of those. */
    private static function rank(string $group): int
    {
        $index = array_search($group, self::GROUP_ORDER, true);

        return $index === false ? \count(self::GROUP_ORDER) : $index;
    }

    /**
     * @param array<string, mixed> $props
     */
    private static function string(array $props, string $name, string $default): string
    {
        $value = $props[$name] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}
