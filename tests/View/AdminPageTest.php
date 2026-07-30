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

    /**
     * La rama resuelta, medida sin depender de ninguna máquina: se sintetiza un checkout mínimo con
     * el layout que {@see \Milpa\Live\Support\MilpaDesign::cssFiles()} declara y se apunta
     * `MILPA_DESIGN_PATH` ahí.
     *
     * Esta prueba existe porque la que había **nunca corría**. Buscaba el checkout hermano con
     * `dirname(__DIR__, 6)`, que se pasa un nivel —el checkout está en `dirname(__DIR__, 5)`— así que
     * se saltaba en la única máquina que lo tiene, anunciando «no hay checkout en esta máquina»
     * cuando sí había. Y en `vendor/milpa/admin` la profundidad es otra, de modo que para cualquier
     * consumidor publicado se saltaría siempre. El resultado: **el inlining no se medía en ningún
     * lado**, ni en CI ni en dev.
     *
     * Se afirma la CUENTA, no la presencia. `assertStringContainsString('<style>')` pasaba con uno de
     * seis archivos inlineados —y con cero, porque la regla de reveal host-inline emite un `<style>`
     * siempre—. Contar es lo que distingue «se inlinearon todos» de «se inlineó algo».
     */
    public function test_wrap_inlines_every_design_stylesheet_when_the_path_resolves(): void
    {
        $raiz = sys_get_temp_dir() . '/milpa-design-' . uniqid();
        $hojas = [
            'dist/milpa-tokens.css' => '.mui-token{--a:1}',
            'motion/milpa-motion.css' => '.mui-motion{}',
            'primitives/milpa-primitives.css' => '.mui-prim{}',
            'components/milpa-components.css' => '.mui-comp{}',
            'artifacts/milpa-artifacts.css' => '.mui-art{}',
            'layouts/milpa-layouts.css' => '.mui-lay{}',
        ];
        foreach ($hojas as $rel => $css) {
            @mkdir($raiz . '/' . \dirname($rel), 0o777, true);
            file_put_contents($raiz . '/' . $rel, $css);
        }

        putenv('MILPA_DESIGN_PATH=' . $raiz);

        try {
            $page = AdminPage::wrap('<p>fragment</p>');

            foreach ($hojas as $rel => $css) {
                self::assertStringContainsString($css, $page, "no se inlineó {$rel}");
            }
            // Las seis hojas + la regla de reveal host-inline, que se emite siempre.
            self::assertSame(\count($hojas) + 1, substr_count($page, '<style>'));
            self::assertStringContainsString('<p>fragment</p>', $page);
        } finally {
            foreach (array_keys($hojas) as $rel) {
                @unlink($raiz . '/' . $rel);
                @rmdir($raiz . '/' . \dirname($rel));
            }
            @rmdir($raiz);
        }
    }

    /**
     * Y contra el checkout REAL cuando está, que es lo único que puede cazar una deriva de layout: si
     * `@milpa/design` mueve o renombra una hoja, la prueba de arriba sigue verde —su checkout
     * sintético usa el layout que el código espera, no el que el design system publica— y sólo esta
     * lo nota.
     *
     * El checkout se busca hacia arriba en vez de contarse: el conteo fijo es lo que rompió la
     * versión anterior, y la profundidad cambia entre el monorepo y `vendor/`. Cuando no está, el
     * mensaje dice **dónde se buscó** — un skip que no dice eso es indistinguible de un skip por un
     * error de ruta, que es exactamente lo que pasó aquí.
     */
    public function test_the_real_design_checkout_still_has_the_layout_the_code_expects(): void
    {
        $desde = __DIR__;
        $encontrado = null;
        for ($i = 0; $i < 8; ++$i) {
            $desde = \dirname($desde);
            if (is_dir($desde . '/milpa-design')) {
                $encontrado = $desde . '/milpa-design';
                break;
            }
        }
        if ($encontrado === null) {
            self::markTestSkipped('no se encontró milpa-design subiendo 8 niveles desde ' . __DIR__);
        }

        putenv('MILPA_DESIGN_PATH=' . $encontrado);

        $page = AdminPage::wrap('<p>fragment</p>');

        self::assertSame(
            7,
            substr_count($page, '<style>'),
            "el checkout real en {$encontrado} no aportó las 6 hojas que el código espera"
        );
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
