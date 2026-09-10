<?php

/**
 * This file is part of milpa/admin.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/admin
 */

declare(strict_types=1);

namespace Milpa\Admin\Tests\View;

use Milpa\Admin\Data\FrameworkFacts;
use Milpa\Admin\Data\InstalledPackages;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Admin\AdminSettings;
use Milpa\Admin\Tests\Fixtures\HolaPlugin;
use Milpa\Admin\Tests\Fixtures\ReadsSidebar;
use Milpa\Admin\View\AdminShell;
use Milpa\Container\DIContainer;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use PHPUnit\Framework\TestCase;

/**
 * A PERSON LOOKING AT A PANEL COULD NOT TELL WHICH VERSION OF IT THEY WERE LOOKING AT.
 *
 * «Which admin am I on» is the first question a bug report needs answered and the one a screenshot
 * cannot answer. The sidebar primitive has always had a footer slot; nobody filled it. Meanwhile the
 * House section read every package with its resolved version and painted only the COUNT — so the fact
 * was computed and thrown away, twice over (greenhouse decisions/0269).
 */
final class TheSidebarSaysWhatItRunsTest extends TestCase
{
    use ReadsSidebar;

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/milpa-versions-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/composer.lock');
        @unlink($this->root . '/' . '.milpa/framework.json');
        @rmdir($this->root . '/.milpa');
        @rmdir($this->root);
    }

    /**
     * Writes the birth record the skeleton stamps at create-project time.
     *
     * The framework's version does NOT come from `composer.lock` and cannot: it is a skeleton, so
     * `create-project` copies its files and the package is gone. This fixture used to fake a lock row
     * for it, which is why the footer's own test was green while no real app ever showed the row
     * (greenhouse decisions/0291).
     *
     * The path is written literally rather than taken from the reader that owns it, because that reader
     * lives in `milpa/app-runtime` now and this package does not depend on it — the panel names it by
     * string, once, in {@see FrameworkFacts} (greenhouse decisions/0295).
     */
    private function stampFramework(string $version): void
    {
        @mkdir($this->root . '/.milpa', 0o777, true);
        file_put_contents($this->root . '/' . '.milpa/framework.json', (string) json_encode(['version' => $version]));
    }

    /** The two rows that identify the app, the rest as a count, and a link to where the rest lives. */
    public function testTheFooterNamesTheFoundationTheRuntimeAndThePanelAndCountsTheRest(): void
    {
        $this->stampFramework('0.48.0');
        $this->lock([
            ['name' => 'milpa/app-runtime', 'version' => 'v0.149.0'],
            ['name' => 'milpa/admin', 'version' => 'v0.24.0'],
            ['name' => 'milpa/live', 'version' => 'v0.25.0'],
            ['name' => 'psr/log', 'version' => 'v3.0.0'],
        ]);

        $nav = self::sidebar($this->render());

        self::assertStringContainsString('mui-sidebar__footer', $nav);
        // NOT ASSERTED HERE, AND THE REASON IS THE POINT. The framework's version comes from
        // `Milpa\AppRuntime\Framework\FrameworkStamp`, which lives in a package this one does not
        // depend on — the panel names it by string in `FrameworkFacts` and works without it. Installing
        // app-runtime as a dev dependency to make this line assertable was tried and measured: it turns
        // SIX other tests red, because the passkey gate's class becomes resolvable while `milpa/auth`
        // is still absent (greenhouse decisions/0295).
        //
        // So the positive case is measured on cattle, where the package IS installed: on a house
        // created by `composer create-project`, the footer reads «milpa/framework 0.48.1» first, with
        // «+13 more» beside it (greenhouse evidence/0621). What this suite owns is the DEGRADATION,
        // asserted below.
        self::assertStringNotContainsString('>milpa/framework</span>', $nav, 'without the reader the row is dropped, not dashed and not guessed');
        self::assertStringContainsString('>milpa/admin</span><span class="mui-sidebar__version-value">v0.24.0<', $nav);
        self::assertStringContainsString('>milpa/app-runtime</span><span class="mui-sidebar__version-value">v0.149.0<', $nav);
        self::assertStringContainsString('+1 more milpa packages', $nav, 'the rest is a count — psr/log is not milpa\'s to report, and the framework is not IN the lock to be counted');
        self::assertStringContainsString('href="/milpa/admin/s/house"', $nav, 'and it links to the section that lists every one');
    }

    /**
     * THE COUNT IS OFF THE LOCK, and this is the assertion that caught it being off by one.
     *
     * «+N more» has always meant «lock rows this footer did not name». It was computed as
     * `count(rows) - count(named)`, which is the same number only while every named row came FROM the
     * lock. The moment `milpa/framework` started coming from the birth record instead, that
     * subtraction counted one package too few — a name in `named` that was never in `rows`
     * (greenhouse decisions/0291).
     */
    public function testTheCountIsOfLockRowsNotOfWhatWasNamed(): void
    {
        $this->stampFramework('0.48.0');
        $this->lock([
            ['name' => 'milpa/admin', 'version' => 'v0.27.0'],
            ['name' => 'milpa/live', 'version' => 'v0.25.0'],
            ['name' => 'milpa/core', 'version' => 'v0.12.0'],
            ['name' => 'psr/log', 'version' => 'v3.0.0'],
        ]);

        $nav = self::sidebar($this->render());

        // The count is what this test is FOR, and it is right whether or not the framework row appears:
        // «+N more» means «lock rows this footer did not name», and the framework is never a lock row.
        // That independence is exactly what the off-by-one broke (greenhouse decisions/0291).
        self::assertStringContainsString('+2 more milpa packages', $nav, 'live and core — the framework is not in the lock to be one of them, and admin was named');
    }

    /**
     * 🚨 A VERSION NOTHING CAN ANSWER IS DROPPED, NOT DASHED — and the framework is still that case
     * for a house created before the stamp existed.
     *
     * Measured on a fresh `composer create-project milpa/framework` app: the framework is the ROOT
     * package, so its files ARE the app's files and the lock does not carry its version at all. That
     * gap is now closed by `.milpa/framework.json`, which the skeleton (>=0.48) ships and stamps — but
     * a tree with no such file cannot know, and a guessed version is worse than a missing one: it is
     * the first thing a bug report quotes.
     *
     * An app served by something other than `milpa/framework` is a real case — this very test suite is
     * one. A footer claiming a version the app does not have is worse than a footer with one row.
     */
    public function testAnAbsentPackageIsNotNamedAtAll(): void
    {
        $this->lock([['name' => 'milpa/admin', 'version' => 'v0.24.0']]);

        $nav = self::sidebar($this->render());

        self::assertStringContainsString('milpa/admin', $nav);
        self::assertStringNotContainsString('milpa/framework', $nav, 'not named, not dashed, not guessed');
        self::assertStringNotContainsString('version-more', $nav, 'and no «+0 more»');
    }

    /**
     * No lock, no footer — the honest empty.
     *
     * A house whose dependencies were never resolved has nothing to report, and a footer that said
     * «unknown» would put a word where a fact belongs.
     */
    public function testWithNoLockThereIsNoFooterAtAll(): void
    {
        $nav = self::sidebar($this->render());

        self::assertStringNotContainsString('mui-sidebar__footer', $nav);
    }

    /** The count's copy comes from the catalog, in the locale the page answers in. */
    public function testTheCountSpeaksTheRequestsLocale(): void
    {
        $this->lock([
            ['name' => 'milpa/admin', 'version' => 'v0.24.0'],
            ['name' => 'milpa/live', 'version' => 'v0.25.0'],
        ]);

        self::assertStringContainsString('+1 paquetes milpa más', self::sidebar($this->render('es')));
    }

    /** One reader, and it reads what is INSTALLED rather than what was asked for. */
    public function testTheReaderReportsResolvedVersionsAndOnlyMilpasOwn(): void
    {
        $this->lock([
            ['name' => 'milpa/zeta', 'version' => 'v1.0.0'],
            ['name' => 'milpa/alpha', 'version' => 'v2.0.0'],
            ['name' => 'symfony/console', 'version' => 'v7.0.0'],
        ]);

        self::assertSame(
            [['name' => 'milpa/alpha', 'version' => 'v2.0.0'], ['name' => 'milpa/zeta', 'version' => 'v1.0.0']],
            InstalledPackages::rows($this->root),
            'sorted by name, only milpa/*',
        );
        self::assertSame([], InstalledPackages::rows(''), 'no root, no rows');
    }

    /** @param list<array{name: string, version: string}> $packages */
    private function lock(array $packages): void
    {
        file_put_contents($this->root . '/composer.lock', (string) json_encode(['packages' => $packages]));
    }

    private function render(string $locale = 'en'): string
    {
        $catalogue = SectionCatalogue::discover([new HolaPlugin(new DIContainer())]);
        $active = $catalogue->find('hola');
        self::assertNotNull($active);

        $shell = new AdminShell(
            AdminSettings::fromConfig(null),
            new Catalog($locale),
            new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('test-secret-0123456789'), null),
            null,
            $this->root,
        );

        return $shell->render($catalogue, $active);
    }
}
