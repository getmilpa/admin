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

use Milpa\Admin\View\AdminPage;
use PHPUnit\Framework\TestCase;

/**
 * {@see AdminPage::wrap()}'s two CSS paths: resolved (inlines every `@milpa/design` stylesheet,
 * dev/dogfood) and the structure-only fallback (a {@see \RuntimeException} from
 * {@see \Milpa\Live\Support\MilpaDesign::cssFiles()} when neither `MILPA_DESIGN_PATH` nor
 * `node_modules/@milpa/design` resolve).
 *
 * {@see \Tests\Integration\Plugins\MilpaAdminPlugin\MilpaAdminShellTest} deliberately forces only
 * the fallback path (its docblock explains why: the real CSS contains "toggle" and would break
 * that test's "no toggle in the response" assertion for reasons unrelated to the shell markup
 * itself) — this test proves the CSS-resolved branch is not dead code.
 */
final class AdminPageTest extends TestCase
{
    private string|false $previousDesignPath = false;

    protected function setUp(): void
    {
        $this->previousDesignPath = getenv('MILPA_DESIGN_PATH');
    }

    protected function tearDown(): void
    {
        if ($this->previousDesignPath === false) {
            putenv('MILPA_DESIGN_PATH');
        } else {
            putenv('MILPA_DESIGN_PATH=' . $this->previousDesignPath);
        }
    }

    public function test_wrap_inlines_milpa_design_css_when_the_sibling_checkout_resolves(): void
    {
        $siblingDesignPath = dirname(__DIR__, 6) . '/milpa-design';
        if (!is_dir($siblingDesignPath)) {
            self::markTestSkipped('No sibling ../milpa-design checkout on this machine.');
        }

        putenv('MILPA_DESIGN_PATH=' . $siblingDesignPath);

        $page = AdminPage::wrap('<p>fragment</p>');

        self::assertStringContainsString('<style>', $page);
        self::assertStringContainsString('<p>fragment</p>', $page);
    }

    public function test_wrap_falls_back_to_structure_only_when_milpa_design_does_not_resolve(): void
    {
        putenv('MILPA_DESIGN_PATH=' . sys_get_temp_dir() . '/milpa-design-does-not-exist');

        $page = AdminPage::wrap('<p>fragment</p>');

        // Ninguna CSS de @milpa/design se inlinea; la única <style> presente es la regla de
        // reveal host-inline (ADR#5), que se emite SIEMPRE sin importar si el design system
        // resuelve — ver AdminPage::wrap().
        self::assertSame(1, substr_count($page, '<style>'));
        self::assertStringContainsString('html:not(.milpa-js) .mui-topbar__nav-toggle', $page);
        self::assertStringContainsString('<!doctype html>', $page);
        self::assertStringContainsString('<p>fragment</p>', $page);
    }

    public function test_wrap_emits_deferred_scripts_and_the_js_only_reveal_rule(): void
    {
        $html = AdminPage::wrap('<main>x</main>', ['/milpa-live.js', '/vendor/alpine.min.js']);

        self::assertStringContainsString('<script src="/milpa-live.js" defer></script>', $html);
        self::assertStringContainsString('<script src="/vendor/alpine.min.js" defer></script>', $html);
        // El toggle JS-only queda oculto salvo cuando el runtime marca <html class="milpa-js">.
        self::assertStringContainsString('html:not(.milpa-js) .mui-topbar__nav-toggle', $html);
        self::assertStringContainsString('<main>x</main>', $html);
    }

    public function test_wrap_emits_no_scripts_by_default_and_stays_valid(): void
    {
        $html = AdminPage::wrap('<main>x</main>');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('<main>x</main>', $html);
    }
}
