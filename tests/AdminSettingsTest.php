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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\MiddlewareInterface;

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
        self::assertFalse($settings->malformed());
        self::assertSame([], $settings->rejected());
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
        self::assertSame([], $settings->rejected());
    }

    public function testFallsBackToTheLiveSecretAndRejectsWhatItCannotUseWithoutPaintingItDefault(): void
    {
        $settings = AdminSettings::fromConfig(new Config([
            'admin' => ['route' => '/', 'locale' => '', 'middleware' => 'not-a-list', 'title' => ''],
            'live' => ['secret' => 'live-secret-0123456789abcdef'],
        ]));

        self::assertSame('/milpa/admin', $settings->route, 'a bare slash is not a mount point');
        self::assertSame('en', $settings->locale);
        self::assertSame([], $settings->middleware, 'nothing readable entry by entry was declared');
        self::assertTrue($settings->malformed());
        self::assertSame([LoopbackOnlyMiddleware::class], $settings->effectiveMiddleware());
        self::assertSame('fallback', $settings->gateKind());
        self::assertSame('live-secret-0123456789abcdef', $settings->signingSecret());
        self::assertSame('Milpa Admin', $settings->title);
        self::assertSame(
            ['route' => 'rejected', 'locale' => 'rejected', 'middleware' => 'rejected', 'secret' => 'config', 'title' => 'rejected'],
            $settings->sources(),
            'what the app wrote and the panel refused is a third state, never default',
        );
        self::assertSame(
            ['route' => '/', 'locale' => '(empty)', 'middleware' => 'string', 'title' => '(empty)'],
            $settings->rejected(),
            'the value for a string — an empty one named as such — and the type for a non-list gate',
        );
    }

    public function testRecordsPerKeyWhetherTheAppDeclaredItTheDefaultIsRunningOrThePanelRefusedIt(): void
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
            'admin' => ['route' => 42, 'locale' => 'fr', 'middleware' => true, 'title' => ['x'], 'secret' => ''],
        ]));
        self::assertTrue($rejected->declared(), 'the key exists even when nothing in it is usable');
        self::assertSame('/milpa/admin', $rejected->route);
        self::assertSame('en', $rejected->locale, 'a locale the catalog lacks is not the panel\'s locale');
        self::assertSame('Milpa Admin', $rejected->title);
        self::assertSame(
            ['route' => 'rejected', 'locale' => 'rejected', 'middleware' => 'rejected', 'secret' => 'default', 'title' => 'rejected'],
            $rejected->sources(),
            'the default runs, and the source says the app wrote something the panel refused',
        );
        self::assertSame(['route' => 'int', 'locale' => 'fr', 'middleware' => 'bool', 'title' => 'array'], $rejected->rejected());

        $nulls = AdminSettings::fromConfig(new Config(['admin' => ['route' => null, 'locale' => null, 'middleware' => null, 'title' => null, 'secret' => null]]));
        self::assertSame(
            ['route' => 'default', 'locale' => 'default', 'middleware' => 'default', 'secret' => 'default', 'title' => 'default'],
            $nulls->sources(),
            'null is not a declaration',
        );
        self::assertSame([], $nulls->rejected());

        self::assertTrue(AdminSettings::fromConfig(new Config(['admin' => []]))->declared(), 'an empty admin key exists');
        self::assertFalse(AdminSettings::fromConfig(new Config(['admin' => 'yes']))->declared(), 'a key the panel cannot read is not a declaration');

        $direct = new AdminSettings(route: '/panel', sources: ['route' => 'config', 'locale' => 'weird', 'title' => 'rejected']);
        self::assertSame('config', $direct->sources()['route']);
        self::assertSame('default', $direct->sources()['locale'], 'anything but config is default');
        self::assertSame('default', $direct->sources()['title'], 'rejected without a description of what was declared is not rejected');
        self::assertSame('default', $direct->sources()['middleware'], 'a key left out is a default');

        $described = new AdminSettings(sources: ['locale' => 'config'], rejected: ['locale' => 'fr']);
        self::assertSame('rejected', $described->sources()['locale'], 'a description wins over whatever the source says');
        self::assertSame(['locale' => 'fr'], $described->rejected());
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

    /**
     * Every shape of `admin.middleware` that is not a list of PSR-15 middleware class names — each one
     * a way the first form of the rule let the panel open from the LAN, or die with a 500.
     *
     * @return iterable<string, array{0: mixed, 1: list<string>, 2: bool}> the declaration, what `unresolvedMiddleware()` names, whether it was not a list at all
     */
    public static function misdeclaredGates(): iterable
    {
        yield 'a non-string entry: an int' => [[42], ['int (not a class name)'], false];
        yield 'a non-string entry: null' => [[null], ['null (not a class name)'], false];
        yield 'a non-string entry: an instance' => [[new \stdClass()], ['stdClass (not a class name)'], false];
        yield 'a non-string entry: an instance of a real middleware' => [[new AllowAllMiddleware()], [AllowAllMiddleware::class . ' (not a class name)'], false];
        yield 'a non-string entry: a nested list' => [[[LoopbackOnlyMiddleware::class]], ['array (not a class name)'], false];
        yield 'an associative map' => [[LoopbackOnlyMiddleware::class => true], ['array (not a list)'], true];
        yield 'a string, not a list' => ['Acme\\Nope', ['string (not a list)'], true];
        yield 'a string naming a real middleware, still not a list' => [LoopbackOnlyMiddleware::class, ['string (not a list)'], true];
        yield 'true' => [true, ['bool (not a list)'], true];
        yield 'an int' => [42, ['int (not a list)'], true];
        yield 'an empty string entry' => [[''], ['(empty)'], false];
        yield 'a class that does not exist' => [['Acme\\Nope'], ['Acme\\Nope (class does not exist)'], false];
        yield 'two classes that do not exist' => [['Acme\\Nope', 'Acme\\Missing'], ['Acme\\Nope (class does not exist)', 'Acme\\Missing (class does not exist)'], false];
        yield 'the interface itself (is_a alone would accept it)' => [[MiddlewareInterface::class], [MiddlewareInterface::class . ' (class does not exist)'], false];
        yield 'a class that exists but is not a middleware: stdClass' => [[\stdClass::class], ['stdClass (not a PSR-15 middleware)'], false];
        yield 'a class that exists but is not a middleware: DateTimeImmutable' => [[\DateTimeImmutable::class], ['DateTimeImmutable (not a PSR-15 middleware)'], false];
        yield 'half a gate: a real middleware next to an int' => [[AllowAllMiddleware::class, 42], ['int (not a class name)'], false];
        yield 'half a gate: a real middleware next to a typo' => [[AllowAllMiddleware::class, 'Acme\\Nope'], ['Acme\\Nope (class does not exist)'], false];
        yield 'half a gate: the strict gate next to a typo' => [[LoopbackOnlyMiddleware::class, 'Acme\\Nope'], ['Acme\\Nope (class does not exist)'], false];
    }

    /**
     * @param list<string> $unresolved
     */
    #[DataProvider('misdeclaredGates')]
    public function testEveryMisdeclarationFallsBackToLoopbackOnlyAndIsNamed(mixed $declared, array $unresolved, bool $malformed): void
    {
        $settings = AdminSettings::fromConfig(new Config(['admin' => ['middleware' => $declared]]));

        self::assertSame([LoopbackOnlyMiddleware::class], $settings->effectiveMiddleware(), 'the strict gate, and only it — never open, never the half that loads');
        self::assertSame('fallback', $settings->gateKind());
        self::assertSame($unresolved, $settings->unresolvedMiddleware());
        self::assertSame($malformed, $settings->malformed());
        self::assertSame($malformed ? 'rejected' : 'config', $settings->sources()['middleware'], 'a list with a bad entry was declared; a non-list was rejected whole');
        if (\is_array($declared) && array_is_list($declared)) {
            self::assertSame($declared, $settings->middleware, 'the declaration is kept exactly as written');
        }
        if ($malformed) {
            self::assertSame(get_debug_type($declared), $settings->rejected()['middleware']);
        }
    }

    public function testThePositiveControlsALiterallyEmptyListOpensAndARealMiddlewareIsCarried(): void
    {
        $open = AdminSettings::fromConfig(new Config(['admin' => ['middleware' => []]]));
        self::assertSame('open', $open->gateKind());
        self::assertSame([], $open->effectiveMiddleware(), 'the one declaration that opens: a literally empty list');
        self::assertSame([], $open->unresolvedMiddleware());
        self::assertFalse($open->malformed());
        self::assertSame('config', $open->sources()['middleware']);

        $custom = AdminSettings::fromConfig(new Config(['admin' => ['middleware' => [AllowAllMiddleware::class]]]));
        self::assertSame('custom', $custom->gateKind());
        self::assertSame([AllowAllMiddleware::class], $custom->effectiveMiddleware(), 'a real PSR-15 class is carried as declared');
        self::assertSame([], $custom->unresolvedMiddleware());

        $stacked = AdminSettings::fromConfig(new Config(['admin' => ['middleware' => [LoopbackOnlyMiddleware::class, AllowAllMiddleware::class]]]));
        self::assertSame('custom', $stacked->gateKind(), 'loopback plus something else is the app\'s own stack');
        self::assertSame([LoopbackOnlyMiddleware::class, AllowAllMiddleware::class], $stacked->effectiveMiddleware());

        self::assertSame('loopback', AdminSettings::fromConfig(null)->gateKind());
        self::assertSame([LoopbackOnlyMiddleware::class], AdminSettings::fromConfig(null)->effectiveMiddleware());

        self::assertNull(AdminSettings::middlewareDefect(AllowAllMiddleware::class));
        self::assertNull(AdminSettings::middlewareDefect(LoopbackOnlyMiddleware::class));
        self::assertSame('Acme\\Nope (class does not exist)', AdminSettings::middlewareDefect('Acme\\Nope'));
    }

    public function testTheRuleHoldsHoweverTheSettingsWereBuilt(): void
    {
        $typo = new AdminSettings(middleware: ['Acme\\Nope']);
        self::assertSame([LoopbackOnlyMiddleware::class], $typo->effectiveMiddleware());
        self::assertSame('fallback', $typo->gateKind());
        self::assertFalse($typo->malformed());

        $map = new AdminSettings(middleware: ['a' => AllowAllMiddleware::class]);
        self::assertTrue($map->malformed(), 'a map is not a list, however it got here');
        self::assertSame(['array (not a list)'], $map->unresolvedMiddleware());
        self::assertSame([LoopbackOnlyMiddleware::class], $map->effectiveMiddleware());
        self::assertSame('fallback', $map->gateKind());

        $scalar = new AdminSettings(middleware: [], rejected: ['middleware' => 'string']);
        self::assertTrue($scalar->malformed(), 'an empty list with the middleware key rejected is not an open panel');
        self::assertSame(['string (not a list)'], $scalar->unresolvedMiddleware());
        self::assertSame([LoopbackOnlyMiddleware::class], $scalar->effectiveMiddleware());
        self::assertSame('rejected', $scalar->sources()['middleware']);

        $instance = new AdminSettings(middleware: [new AllowAllMiddleware()]);
        self::assertSame([AllowAllMiddleware::class . ' (not a class name)'], $instance->unresolvedMiddleware(), 'an instance is not a class name');
        self::assertSame([LoopbackOnlyMiddleware::class], $instance->effectiveMiddleware());
    }
}
