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

use Milpa\Admin\AdminSettings;
use Milpa\Admin\I18n\Catalog;
use Milpa\Admin\View\AdminPage;
use PHPUnit\Framework\TestCase;

final class AdminPageTest extends TestCase
{
    public function testWrapsTheShellWithAssetsRuntimeAndLocale(): void
    {
        $page = new AdminPage(new AdminSettings(route: '/panel', locale: 'es', title: 'Casa'), new Catalog('es'));

        $html = $page->render('<div id="shell"></div>', 'Rutas');

        self::assertStringStartsWith('<!doctype html>', $html);
        self::assertStringContainsString('<html lang="es"', $html);
        self::assertStringContainsString('<title>Rutas · Casa</title>', $html);
        self::assertStringContainsString('href="/panel/assets/tokens.css"', $html);
        self::assertStringContainsString('href="/panel/assets/bundle.css"', $html);
        self::assertStringContainsString('<div id="shell"></div>', $html);
        self::assertStringContainsString('src="/panel/assets/milpa-live.js" defer', $html);
        self::assertStringContainsString('src="/panel/assets/alpine.min.js" defer', $html);
        self::assertStringContainsString('ROOT="/panel"', $html, 'the script knows the panel\'s route, so it knows which links are the panel\'s');
        self::assertStringContainsString('<title>Casa</title>', $page->render('', ''));

        $hostile = (new AdminPage(new AdminSettings(route: '/p"><script>alert(1)</script>'), new Catalog()))->render('');
        self::assertStringContainsString('ROOT="/p\u0022\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E"', $hostile, 'the route reaches the script as a JSON string that cannot close the tag');
        self::assertStringNotContainsString('ROOT="/p"><script>', $hostile);
    }

    public function testCarriesThePreferenceScriptsAndAnswersInAnotherCatalog(): void
    {
        $page = new AdminPage(AdminSettings::fromConfig(null), new Catalog());

        $html = $page->render('<div id="shell"></div>');

        $body = strpos($html, '<body');
        $early = strpos($html, '<script data-admin-prefs="early">');
        $shell = strpos($html, '<div id="shell"></div>');
        $delegated = strpos($html, '<script data-admin-prefs="delegated">');
        self::assertNotFalse($body);
        self::assertNotFalse($early);
        self::assertNotFalse($shell);
        self::assertNotFalse($delegated);
        self::assertTrue($body < $early && $early < $shell, 'the theme script runs before the shell is parsed — no flash');
        self::assertTrue($shell < $delegated, 'the delegated handlers come after the shell');
        self::assertStringContainsString('localStorage.getItem("milpa.admin.prefs")', $html, 'the early script reads the key');
        self::assertStringContainsString('var KEY="milpa.admin.prefs"', $html, 'the delegated one reads the same key');
        self::assertStringContainsString('document.documentElement.setAttribute("data-theme",t)', $html);
        self::assertStringContainsString('prefers-color-scheme: light', $html, 'system resolves to dark or light — the tokens want one of the two');
        self::assertStringContainsString('document.addEventListener("change"', $html);
        self::assertStringContainsString('querySelector(".mui-shell")', $html);
        self::assertStringContainsString('setAttribute("data-density"', $html);
        self::assertStringContainsString('searchParams.set("lang",code)', $html);
        self::assertStringContainsString('ROOT="/milpa/admin"', $html);
        self::assertStringContainsString('function inPanel(h){return h===ROOT||h.indexOf(ROOT+"/")===0||h.indexOf(ROOT+"?")===0;}', $html, 'a panel link is one whose href starts with the panel\'s route');
        self::assertStringContainsString('document.querySelector(".mui-shell")||document.body,links=root.querySelectorAll("a[href]")', $html, 'every anchor under the shell root, not only the sidebar\'s');
        self::assertStringContainsString('querySelector(".mui-sidebar__brand");if(b&&b.getAttribute("href")==="#"){b.setAttribute("href",ROOT);}', $html, 'the brand the sidebar paints as # is pointed at the panel root, so it is a panel link too');
        self::assertStringNotContainsString('.mui-sidebar a[href', $html, 'no sidebar-only rule');
        self::assertStringContainsString('localStorage.setItem(KEY', $html);
        self::assertStringContainsString('catch(e){}', $html, 'storage may be unavailable');
        self::assertStringNotContainsString('checkbox', $html, 'no remembered-filters control: nothing consumes it');
        self::assertStringNotContainsString('x-data', $html);
        self::assertStringContainsString('<html lang="en" data-theme="dark">', $html, 'the server still stamps a theme: the script only overrides it');

        self::assertStringContainsString('<html lang="es"', $page->withCatalog(new Catalog('es'))->render(''));
        self::assertStringContainsString('<html lang="en"', $page->render(''), 'the original is untouched');
    }

    public function testErrorDocumentEscapesAndLinksHome(): void
    {
        $page = new AdminPage(AdminSettings::fromConfig(null), new Catalog());

        $html = $page->error(404, 'No section is named «<x>».');

        self::assertStringContainsString('<h1 class="mui-h1">404</h1>', $html);
        self::assertStringContainsString('&lt;x&gt;', $html);
        self::assertStringContainsString('href="/milpa/admin"', $html);
        self::assertStringContainsString('<title>404 · Milpa Admin</title>', $html);
    }
}
