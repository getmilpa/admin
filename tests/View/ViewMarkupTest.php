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

use Milpa\Admin\View\ViewMarkup;
use PHPUnit\Framework\TestCase;

/**
 * Splitting a declared view into its roots is what makes per-node containment possible (greenhouse
 * decisions/0211): each root compiles on its own, so one that throws costs its own region.
 */
final class ViewMarkupTest extends TestCase
{
    public function testEveryRootIsNamedAndSerialisedBackToMarkupTheCompilerParses(): void
    {
        $roots = ViewMarkup::roots(
            '<milpa:desktop-tabs id="a"/>' . "\n"
            . '  text between roots is not a root  '
            . '<milpa-desktop-conversation id="b"><p>ordinary HTML rides inside</p></milpa-desktop-conversation>',
        );

        self::assertCount(2, $roots);
        self::assertSame('desktop-tabs', $roots[0]->name, 'the prefixed form');
        self::assertSame('desktop-conversation', $roots[1]->name, 'the dashed form');
        self::assertStringContainsString('id="a"', $roots[0]->markup);
        self::assertStringContainsString('<p>ordinary HTML rides inside</p>', $roots[1]->markup, 'a root keeps its children');
        self::assertSame('<milpa:desktop-tabs id="a"/>', $roots[0]->markup, 'the root as written: the compiler binds the `milpa` prefix itself when it wraps the fragment');
    }

    public function testARootThatIsNotAMilpaElementKeepsItsTagSoTheRefusalCanNameIt(): void
    {
        $roots = ViewMarkup::roots('<section id="not-a-component"/>');

        self::assertCount(1, $roots);
        self::assertSame('section', $roots[0]->name, 'the compiler refuses it — the panel needs a name for the failure region');
    }

    public function testMarkupThatIsNotWellFormedOrCarriesNoElementIsRefused(): void
    {
        try {
            ViewMarkup::roots('<milpa:a>');
            self::fail('unbalanced markup is not a view');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString('not well-formed', $refused->getMessage());
        }

        try {
            ViewMarkup::roots('just text, no element');
            self::fail('a view is components, not prose');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString('carries no component', $refused->getMessage());
        }
    }
}
