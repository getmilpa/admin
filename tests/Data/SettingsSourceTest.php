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
        self::assertFalse($snapshot['malformed']);
        self::assertSame(
            "'admin' => ['route' => '/milpa/admin', 'locale' => 'en', 'middleware' => [\\Milpa\\Admin\\Http\\LoopbackOnlyMiddleware::class]],",
            $snapshot['snippet'],
            'fully qualified, so it works pasted as-is',
        );
        self::assertSame(['route', 'locale', 'middleware', 'secret', 'title'], array_column($snapshot['rows'], 'key'));
        self::assertSame(['/milpa/admin', 'en', 'LoopbackOnlyMiddleware', 'derived', 'Milpa Admin'], array_column($snapshot['rows'], 'value'));
        self::assertSame(['default', 'default', 'default', 'default', 'default'], array_column($snapshot['rows'], 'source'));
        self::assertSame([null, null, null, null, null], array_column($snapshot['rows'], 'declared'), 'nothing was refused');
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
        self::assertFalse($snapshot['malformed']);
        self::assertSame(['/panel', 'es', '[]', 'declared:admin.secret', 'Casa'], array_column($snapshot['rows'], 'value'));
        self::assertSame(['config', 'config', 'config', 'config', 'config'], array_column($snapshot['rows'], 'source'));
        self::assertStringNotContainsString('hunter2', (string) json_encode($snapshot), 'the value is nowhere in the state');

        $live = (new SettingsSource(AdminSettings::fromConfig(new Config(['live' => ['secret' => 'live-hunter2']]))))->snapshot();
        self::assertSame('declared:live.secret', $live['rows'][3]['value']);
        self::assertSame('config', $live['rows'][3]['source']);
        self::assertFalse($live['declared']);
        self::assertStringNotContainsString('hunter2', (string) json_encode($live));
    }

    public function testMiddlewareIsShortNamesExceptTheDefectiveEntriesAndEveryDefectIsListed(): void
    {
        $settings = AdminSettings::fromConfig(new Config([
            'admin' => ['middleware' => [LoopbackOnlyMiddleware::class, AllowAllMiddleware::class, 'Acme\\Nope', 42, '']],
        ]));

        $snapshot = (new SettingsSource($settings))->snapshot();

        self::assertSame('fallback', $snapshot['gate']);
        self::assertFalse($snapshot['malformed'], 'a list was declared — its entries are the problem');
        self::assertSame(['Acme\\Nope (class does not exist)', 'int (not a class name)', '(empty)'], $snapshot['unresolved']);
        self::assertSame(
            'LoopbackOnlyMiddleware, AllowAllMiddleware, Acme\\Nope, int, (empty)',
            $snapshot['rows'][2]['value'],
            'the typo stays whole — that is the one to fix — a non-name shows its type, an empty string is never nothing',
        );
        self::assertSame('config', $snapshot['rows'][2]['source']);
        self::assertNull($snapshot['rows'][2]['declared']);

        $notMiddleware = (new SettingsSource(AdminSettings::fromConfig(new Config(['admin' => ['middleware' => [\stdClass::class]]]))))->snapshot();
        self::assertSame('stdClass', $notMiddleware['rows'][2]['value'], 'a class that exists but is not a gate is kept whole too');
        self::assertSame(['stdClass (not a PSR-15 middleware)'], $notMiddleware['unresolved']);

        $custom = (new SettingsSource(AdminSettings::fromConfig(new Config(['admin' => ['middleware' => [AllowAllMiddleware::class]]]))))->snapshot();
        self::assertSame('custom', $custom['gate']);
        self::assertSame('AllowAllMiddleware', $custom['rows'][2]['value']);
        self::assertSame([], $custom['unresolved']);

        $passkey = (new SettingsSource(AdminSettings::fromConfig(new Config(['admin' => ['middleware' => [AdminSettings::PASSKEY_GATE]]]))))->snapshot();
        self::assertSame('passkey', $passkey['gate'], 'the gate is what the panel names it: app-runtime\'s gate, alone, is «passkey»');
        self::assertSame('PasskeyGateMiddleware', $passkey['rows'][2]['value']);
        self::assertSame('config', $passkey['rows'][2]['source']);
        self::assertSame([], $passkey['unresolved']);
    }

    public function testTheSnippetOffersThePasskeyGateAsTheAlternativeLine(): void
    {
        $snapshot = (new SettingsSource(AdminSettings::fromConfig(null)))->snapshot();

        self::assertSame(
            "'admin' => ['route' => '/milpa/admin', 'locale' => 'en', 'middleware' => [\\Milpa\\AppRuntime\\Web\\PasskeyGateMiddleware::class]],",
            $snapshot['passkeySnippet'],
            'the whole admin key, fully qualified, so it replaces the default line pasted as-is — the instruction around it is the renderer\'s, in the catalog\'s language',
        );
        self::assertSame(SettingsSource::passkeySnippet(), $snapshot['passkeySnippet']);
        self::assertSame(
            str_replace('\\Milpa\\Admin\\Http\\LoopbackOnlyMiddleware', '\\Milpa\\AppRuntime\\Web\\PasskeyGateMiddleware', $snapshot['snippet']),
            $snapshot['passkeySnippet'],
            'the same key as the default snippet, one class apart — never a fragment',
        );
    }

    public function testARejectedKeyShowsTheEffectiveValueWhatWasDeclaredAndTheRejectedSource(): void
    {
        $settings = AdminSettings::fromConfig(new Config([
            'admin' => ['route' => 42, 'locale' => 'fr', 'middleware' => 'Acme\\Nope', 'title' => ''],
        ]));

        $snapshot = (new SettingsSource($settings))->snapshot();

        self::assertTrue($snapshot['declared']);
        self::assertSame('fallback', $snapshot['gate']);
        self::assertTrue($snapshot['malformed']);
        self::assertSame(['string (not a list)'], $snapshot['unresolved']);
        self::assertSame('en', $snapshot['locale'], 'the locale the panel answers in, not the one it refused');
        self::assertSame(
            [
                ['key' => 'route', 'value' => '/milpa/admin', 'source' => 'rejected', 'declared' => 'int'],
                ['key' => 'locale', 'value' => 'en', 'source' => 'rejected', 'declared' => 'fr'],
                ['key' => 'middleware', 'value' => 'LoopbackOnlyMiddleware', 'source' => 'rejected', 'declared' => 'string'],
                ['key' => 'secret', 'value' => 'derived', 'source' => 'default', 'declared' => null],
                ['key' => 'title', 'value' => 'Milpa Admin', 'source' => 'rejected', 'declared' => '(empty)'],
            ],
            $snapshot['rows'],
            'the EFFECTIVE value in the row, what was declared next to it — never default for what the app wrote',
        );

        $map = (new SettingsSource(AdminSettings::fromConfig(new Config(['admin' => ['middleware' => [LoopbackOnlyMiddleware::class => true]]]))))->snapshot();
        self::assertSame(['key' => 'middleware', 'value' => 'LoopbackOnlyMiddleware', 'source' => 'rejected', 'declared' => 'array'], $map['rows'][2]);
        self::assertSame(['array (not a list)'], $map['unresolved']);
        self::assertTrue($map['malformed']);
    }
}
