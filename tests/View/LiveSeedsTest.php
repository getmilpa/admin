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

use Milpa\Admin\Section\SeedConflictException;
use Milpa\Admin\View\LiveSeeds;
use PHPUnit\Framework\TestCase;

/**
 * The page seeds each signal ONCE (greenhouse decisions/0211): the host's own and the active view's are
 * merged here, and a disagreement is named, never resolved by declaration order.
 */
final class LiveSeedsTest extends TestCase
{
    public function testTheHostsSeedsAndAViewsMergeIntoOneOfEachTag(): void
    {
        $merged = LiveSeeds::of('the panel', ['admin.section' => 'agent', 'admin.gate' => 'passkey'])
            ->merge(LiveSeeds::of('section «agent»', ['desktop.tab' => 'chat'], ['desktop.tab'], ['x' => ['template' => '{a}']]));

        self::assertSame(['admin.section' => 'agent', 'admin.gate' => 'passkey', 'desktop.tab' => 'chat'], $merged->signals);
        self::assertSame(['desktop.tab'], $merged->persist);
        self::assertSame(['x' => ['template' => '{a}']], $merged->computed);
        self::assertFalse($merged->isEmpty());
        self::assertTrue(LiveSeeds::empty()->isEmpty());
    }

    public function testTheSameKeyWithTheSameValueIsAgreementAndPersistDeduplicates(): void
    {
        $merged = LiveSeeds::of('the panel', ['theme' => 'dark'], ['theme'])
            ->merge(LiveSeeds::of('section «agent»', ['theme' => 'dark'], ['theme', 'tab']));

        self::assertSame(['theme' => 'dark'], $merged->signals, 'two declarers agreeing is agreement, not a clash');
        self::assertSame(['theme', 'tab'], $merged->persist, 'a name persisted twice is persisted once');
    }

    public function testTheSameKeyWithDifferentValuesNamesBothDeclarers(): void
    {
        $host = LiveSeeds::of('the panel', ['theme' => 'dark']);

        try {
            $host->merge(LiveSeeds::of('section «agent»', ['theme' => 'light']));
            self::fail('one page seeds each signal once');
        } catch (SeedConflictException $conflict) {
            self::assertSame(
                'The signal «theme» is seeded twice with different values: the panel says "dark", section «agent» says "light". One page seeds each signal once — agree on a value or name them apart.',
                $conflict->getMessage(),
            );
        }

        try {
            LiveSeeds::of('the panel', [], [], ['sum' => ['template' => '{a}']])
                ->merge(LiveSeeds::of('section «agent»', [], [], ['sum' => ['template' => '{b}']]));
            self::fail('a computed signal is a seed too');
        } catch (SeedConflictException $conflict) {
            self::assertStringContainsString('The computed signal «sum» is seeded twice', $conflict->getMessage());
            self::assertStringContainsString('section «agent» says {"template":"{b}"}', $conflict->getMessage());
        }
    }
}
