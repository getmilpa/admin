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

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Http\LoopbackOnlyMiddleware;
use Milpa\Admin\Tests\Fixtures\AllowAllMiddleware;
use Milpa\Runtime\Config;
use PHPUnit\Framework\TestCase;

final class AdminSettingsTest extends TestCase
{
    public function testDefaultsWithoutConfig(): void
    {
        $settings = AdminSettings::fromConfig(null);

        self::assertSame('/milpa/admin', $settings->route);
        self::assertSame('en', $settings->locale);
        self::assertSame([LoopbackOnlyMiddleware::class], $settings->middleware);
        self::assertSame('Milpa Admin', $settings->title);
        self::assertNotSame('', $settings->signingSecret());
        self::assertSame($settings->signingSecret(), AdminSettings::fromConfig(null)->signingSecret(), 'derived secret is stable');
        self::assertFalse($settings->declared());
        self::assertSame('loopback', $settings->gateKind());
    }

    public function testDeclaredValuesWin(): void
    {
        $settings = AdminSettings::fromConfig(new Config([
            'admin' => ['route' => 'panel/', 'locale' => 'es', 'middleware' => [], 'secret' => 'declared-secret', 'title' => 'Casa'],
        ]));

        self::assertSame('/panel', $settings->route);
        self::assertSame('es', $settings->locale);
        self::assertSame([], $settings->middleware, 'an empty list opens the panel on purpose');
        self::assertSame('declared-secret', $settings->signingSecret());
        self::assertSame('Casa', $settings->title);
        self::assertSame('/panel/s/plugins', $settings->sectionUrl('plugins'));
        self::assertSame('/panel/assets/tokens.css', $settings->assetUrl('tokens.css'));
        self::assertSame('/panel/stack/compose.yml', $settings->composeUrl());
        self::assertTrue($settings->declared());
    }

    public function testFallsBackToTheLiveSecretAndToDefaultsOnBadTypes(): void
    {
        $settings = AdminSettings::fromConfig(new Config([
            'admin' => ['route' => '/', 'locale' => '', 'middleware' => 'not-a-list', 'title' => ''],
            'live' => ['secret' => 'live-secret-0123456789abcdef'],
        ]));

        self::assertSame('/milpa/admin', $settings->route, 'a bare slash is not a mount point');
        self::assertSame('en', $settings->locale);
        self::assertSame([LoopbackOnlyMiddleware::class], $settings->middleware);
        self::assertSame('live-secret-0123456789abcdef', $settings->signingSecret());
        self::assertSame('Milpa Admin', $settings->title);
    }

    public function testRecordsPerKeyWhetherTheAppDeclaredItOrTheDefaultIsRunning(): void
    {
        $defaults = AdminSettings::fromConfig(null);
        self::assertSame(
            ['route' => 'default', 'locale' => 'default', 'middleware' => 'default', 'secret' => 'default', 'title' => 'default'],
            $defaults->sources(),
        );

        $declared = AdminSettings::fromConfig(new Config([
            'admin' => ['route' => '/milpa/admin', 'middleware' => [LoopbackOnlyMiddleware::class]],
        ]));
        self::assertTrue($declared->declared());
        self::assertSame('config', $declared->sources()['route'], 'declaring the default value is still declaring');
        self::assertSame('config', $declared->sources()['middleware']);
        self::assertSame('default', $declared->sources()['locale']);
        self::assertSame('default', $declared->sources()['title']);
        self::assertSame('default', $declared->sources()['secret']);
        self::assertSame('loopback', $declared->gateKind(), 'the strict gate, declared, is still the strict gate');

        $rejected = AdminSettings::fromConfig(new Config([
            'admin' => ['route' => '/', 'locale' => '', 'middleware' => 'not-a-list', 'title' => 42, 'secret' => ''],
        ]));
        self::assertTrue($rejected->declared(), 'the key exists even when nothing in it is usable');
        self::assertSame(
            ['route' => 'default', 'locale' => 'default', 'middleware' => 'default', 'secret' => 'default', 'title' => 'default'],
            $rejected->sources(),
            'a rejected value is not a declaration: the value shown and the source shown agree',
        );

        self::assertTrue(AdminSettings::fromConfig(new Config(['admin' => []]))->declared(), 'an empty admin key exists');
        self::assertFalse(AdminSettings::fromConfig(new Config(['admin' => 'yes']))->declared(), 'a key the panel cannot read is not a declaration');

        $direct = new AdminSettings(route: '/panel', sources: ['route' => 'config', 'locale' => 'weird']);
        self::assertSame('config', $direct->sources()['route']);
        self::assertSame('default', $direct->sources()['locale'], 'anything but config is default');
        self::assertSame('default', $direct->sources()['title'], 'a key left out is a default');
    }

    public function testSecretSourceNamesWhereTheSecretCameFromAndNeverTheValue(): void
    {
        self::assertSame('derived', AdminSettings::fromConfig(null)->secretSource());

        $admin = AdminSettings::fromConfig(new Config(['admin' => ['secret' => 'admin-s3cret'], 'live' => ['secret' => 'live-s3cret']]));
        self::assertSame('declared:admin.secret', $admin->secretSource(), 'admin.secret wins over live.secret');
        self::assertSame('config', $admin->sources()['secret']);
        self::assertStringNotContainsString('s3cret', $admin->secretSource());

        $live = AdminSettings::fromConfig(new Config(['live' => ['secret' => 'live-s3cret']]));
        self::assertSame('declared:live.secret', $live->secretSource());
        self::assertSame('config', $live->sources()['secret'], 'live.secret is declared in config too');
        self::assertFalse($live->declared(), 'but the admin key itself is absent');
        self::assertSame('live-s3cret', $live->signingSecret());

        self::assertSame('declared:admin.secret', (new AdminSettings(secret: 'x'))->secretSource(), 'a secret given directly is the admin key\'s');
        self::assertSame('declared:live.secret', (new AdminSettings(secret: 'x', secretSource: AdminSettings::SECRET_LIVE))->secretSource());
        self::assertSame('derived', (new AdminSettings(secretSource: AdminSettings::SECRET_LIVE))->secretSource(), 'no secret is derived whatever the hint says');
    }

    public function testAMisdeclaredGateFallsBackToLoopbackOnlyNeverToOpenNorToHalfOfIt(): void
    {
        $settings = AdminSettings::fromConfig(new Config([
            'admin' => ['middleware' => [AllowAllMiddleware::class, 'Acme\\Nope', 'Acme\\Missing']],
        ]));

        self::assertSame(['Acme\\Nope', 'Acme\\Missing'], $settings->unresolvedMiddleware());
        self::assertSame([AllowAllMiddleware::class, 'Acme\\Nope', 'Acme\\Missing'], $settings->middleware, 'the declaration is kept as written');
        self::assertSame([LoopbackOnlyMiddleware::class], $settings->effectiveMiddleware(), 'the strict gate — not the half that loads');
        self::assertSame('fallback', $settings->gateKind());
        self::assertSame('config', $settings->sources()['middleware']);

        $direct = new AdminSettings(middleware: ['Acme\\Nope']);
        self::assertSame([LoopbackOnlyMiddleware::class], $direct->effectiveMiddleware(), 'the rule holds however the settings were built');
        self::assertSame('fallback', $direct->gateKind());
    }

    public function testGateKindsOpenCustomAndLoopback(): void
    {
        $open = AdminSettings::fromConfig(new Config(['admin' => ['middleware' => []]]));
        self::assertSame('open', $open->gateKind());
        self::assertSame([], $open->effectiveMiddleware(), 'an empty list stays empty: the app opened the panel on purpose');
        self::assertSame([], $open->unresolvedMiddleware());

        $custom = AdminSettings::fromConfig(new Config(['admin' => ['middleware' => [AllowAllMiddleware::class]]]));
        self::assertSame('custom', $custom->gateKind());
        self::assertSame([AllowAllMiddleware::class], $custom->effectiveMiddleware(), 'a class that exists is carried as declared');

        $stacked = AdminSettings::fromConfig(new Config(['admin' => ['middleware' => [LoopbackOnlyMiddleware::class, AllowAllMiddleware::class]]]));
        self::assertSame('custom', $stacked->gateKind(), 'loopback plus something else is the app\'s own stack');
        self::assertSame([LoopbackOnlyMiddleware::class, AllowAllMiddleware::class], $stacked->effectiveMiddleware());

        self::assertSame('loopback', AdminSettings::fromConfig(null)->gateKind());
        self::assertSame([LoopbackOnlyMiddleware::class], AdminSettings::fromConfig(null)->effectiveMiddleware());
    }
}
