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
        @rmdir($this->root);
    }

    /** The two rows that identify the app, the rest as a count, and a link to where the rest lives. */
    public function testTheFooterNamesTheFoundationTheRuntimeAndThePanelAndCountsTheRest(): void
    {
        $this->lock([
            ['name' => 'milpa/framework', 'version' => 'v0.46.0'],
            ['name' => 'milpa/app-runtime', 'version' => 'v0.149.0'],
            ['name' => 'milpa/admin', 'version' => 'v0.24.0'],
            ['name' => 'milpa/live', 'version' => 'v0.25.0'],
            ['name' => 'psr/log', 'version' => 'v3.0.0'],
        ]);

        $nav = self::sidebar($this->render());

        self::assertStringContainsString('mui-sidebar__footer', $nav);
        self::assertStringContainsString('>milpa/framework</span><span class="mui-sidebar__version-value">v0.46.0<', $nav);
        self::assertStringContainsString('>milpa/admin</span><span class="mui-sidebar__version-value">v0.24.0<', $nav);
        self::assertStringContainsString('>milpa/app-runtime</span><span class="mui-sidebar__version-value">v0.149.0<', $nav);
        self::assertStringContainsString('+1 more milpa packages', $nav, 'the rest is a count — psr/log is not milpa\'s to report');
        self::assertStringContainsString('href="/milpa/admin/s/house"', $nav, 'and it links to the section that lists every one');
    }

    /**
     * 🚨 A PACKAGE THE LOCK DOES NOT CARRY IS DROPPED, NOT DASHED, and `milpa/framework` is the case
     * that made it matter.
     *
     * Measured on a fresh `composer create-project milpa/framework` app: the framework is the ROOT
     * package, so its files ARE the app's files and the lock does not carry its version at all. A
     * founded app cannot say which framework version founded it — a real gap, and a footer that
     * printed a dash there would answer it with a lie.
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
        self::assertSame('v2.0.0', InstalledPackages::version($this->root, 'milpa/alpha'));
        self::assertNull(InstalledPackages::version($this->root, 'milpa/nope'), 'absent is null, never «?»');
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
