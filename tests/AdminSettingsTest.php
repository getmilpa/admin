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
}
