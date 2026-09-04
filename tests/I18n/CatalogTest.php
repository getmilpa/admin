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

namespace Milpa\Admin\Tests\I18n;

use Milpa\Admin\I18n\Catalog;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    public function testEnglishIsTheDefaultAndSpanishIsAnOption(): void
    {
        self::assertSame('Routes', (new Catalog())->tr('nav.routes'));
        self::assertSame('Rutas', (new Catalog('es'))->tr('nav.routes'));
        self::assertSame('en', (new Catalog('klingon'))->locale(), 'an unknown locale falls back to English');
        self::assertSame(['en', 'es'], Catalog::locales());
    }

    public function testUnknownKeysComeBackAsThemselvesAndArgumentsAreApplied(): void
    {
        $catalog = new Catalog();

        self::assertSame('a.literal.title', $catalog->tr('a.literal.title'));
        self::assertFalse($catalog->has('a.literal.title'));
        self::assertTrue($catalog->has('nav.plugins'));
        self::assertSame('No section is named «ghost».', $catalog->tr('section.unknown', 'ghost'));
    }

    public function testEveryEnglishKeyHasASpanishTwin(): void
    {
        $en = new Catalog('en');
        $es = new Catalog('es');
        $reflection = new \ReflectionClassConstant(Catalog::class, 'MESSAGES');
        /** @var array<string, array<string, string>> $messages */
        $messages = $reflection->getValue();

        foreach (array_keys($messages['en']) as $key) {
            self::assertArrayHasKey($key, $messages['es'], "missing Spanish for {$key}");
            self::assertNotSame('', $es->tr($key));
            self::assertNotSame('', $en->tr($key));
        }
    }
}
