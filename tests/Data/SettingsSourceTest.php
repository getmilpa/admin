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

namespace Milpa\Admin\Tests\Data;

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Data\SettingsSource;
use Milpa\Admin\Http\LoopbackOnlyMiddleware;
use Milpa\Admin\Tests\Fixtures\AllowAllMiddleware;
use Milpa\Runtime\Config;
use PHPUnit\Framework\TestCase;

final class SettingsSourceTest extends TestCase
{
    public function testAFreshAppShowsTheFiveDefaultsAndTheSnippetToPaste(): void
    {
        $snapshot = (new SettingsSource(AdminSettings::fromConfig(null)))->snapshot();

        self::assertFalse($snapshot['declared']);
        self::assertSame('loopback', $snapshot['gate']);
        self::assertSame('en', $snapshot['locale']);
        self::assertSame([], $snapshot['unresolved']);
        self::assertSame(
            "'admin' => ['route' => '/milpa/admin', 'locale' => 'en', 'middleware' => [\\Milpa\\Admin\\Http\\LoopbackOnlyMiddleware::class]],",
            $snapshot['snippet'],
            'fully qualified, so it works pasted as-is',
        );
        self::assertSame(['route', 'locale', 'middleware', 'secret', 'title'], array_column($snapshot['rows'], 'key'));
        self::assertSame(['/milpa/admin', 'en', 'LoopbackOnlyMiddleware', 'derived', 'Milpa Admin'], array_column($snapshot['rows'], 'value'));
        self::assertSame(['default', 'default', 'default', 'default', 'default'], array_column($snapshot['rows'], 'source'));
    }

    public function testDeclaredValuesCarryTheirSourceAndTheSecretIsOnlyItsProvenance(): void
    {
        $settings = AdminSettings::fromConfig(new Config([
            'admin' => ['route' => '/panel', 'locale' => 'es', 'middleware' => [], 'secret' => 'hunter2-never-shown', 'title' => 'Casa'],
        ]));

        $snapshot = (new SettingsSource($settings))->snapshot();

        self::assertTrue($snapshot['declared']);
        self::assertSame('open', $snapshot['gate']);
        self::assertSame('es', $snapshot['locale']);
        self::assertSame(['/panel', 'es', '[]', 'declared:admin.secret', 'Casa'], array_column($snapshot['rows'], 'value'));
        self::assertSame(['config', 'config', 'config', 'config', 'config'], array_column($snapshot['rows'], 'source'));
        self::assertStringNotContainsString('hunter2', (string) json_encode($snapshot), 'the value is nowhere in the state');

        $live = (new SettingsSource(AdminSettings::fromConfig(new Config(['live' => ['secret' => 'live-hunter2']]))))->snapshot();
        self::assertSame('declared:live.secret', $live['rows'][3]['value']);
        self::assertSame('config', $live['rows'][3]['source']);
        self::assertFalse($live['declared']);
        self::assertStringNotContainsString('hunter2', (string) json_encode($live));
    }

    public function testMiddlewareIsShortNamesExceptTheUnresolvedOnesKeptWholeAndListed(): void
    {
        $settings = AdminSettings::fromConfig(new Config([
            'admin' => ['middleware' => [LoopbackOnlyMiddleware::class, AllowAllMiddleware::class, 'Acme\\Nope']],
        ]));

        $snapshot = (new SettingsSource($settings))->snapshot();

        self::assertSame('fallback', $snapshot['gate']);
        self::assertSame(['Acme\\Nope'], $snapshot['unresolved']);
        self::assertSame('LoopbackOnlyMiddleware, AllowAllMiddleware, Acme\\Nope', $snapshot['rows'][2]['value'], 'the typo stays whole — that is the one to fix');
        self::assertSame('config', $snapshot['rows'][2]['source']);

        $custom = (new SettingsSource(AdminSettings::fromConfig(new Config(['admin' => ['middleware' => [AllowAllMiddleware::class]]]))))->snapshot();
        self::assertSame('custom', $custom['gate']);
        self::assertSame('AllowAllMiddleware', $custom['rows'][2]['value']);
        self::assertSame([], $custom['unresolved']);
    }
}
