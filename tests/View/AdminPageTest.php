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
        self::assertStringContainsString('<title>Casa</title>', $page->render('', ''));
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
