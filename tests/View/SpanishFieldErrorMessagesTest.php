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

use Milpa\Live\Schema\FieldConstraints;
use Milpa\Live\Schema\FieldError;
use Milpa\Live\Schema\FieldType;
use Milpa\Live\Schema\FormField;
use Milpa\Admin\View\SpanishFieldErrorMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * {@see SpanishFieldErrorMessages} traduce el primer error de validación de un campo al español
 * mexicano por el `code` estable del {@see FieldError}, sustituyendo `{label}` por el label real —
 * el seam que cierra B1 (la UI ya no mezcla el label traducido con el sufijo inglés del binder).
 * Un `code` fuera del conjunto cerrado degrada al `message` inglés del propio error, nunca peta.
 */
final class SpanishFieldErrorMessagesTest extends TestCase
{
    private function field(string $label): FormField
    {
        return new FormField('siteName', FieldType::Text, $label, true, null, new FieldConstraints());
    }

    public function testRequiredResolvesToMexicanSpanishWithTheRealLabel(): void
    {
        $messages = new SpanishFieldErrorMessages();

        $result = $messages(new FieldError('required', 'Site name is required.'), $this->field('Nombre del sitio'));

        self::assertSame('Nombre del sitio es obligatorio.', $result);
    }

    /**
     * @return list<array{string, string}> code → fragmento español esperado
     */
    public static function closedSetProvider(): array
    {
        return [
            ['required', 'es obligatorio.'],
            ['too_short', 'es demasiado corto.'],
            ['too_long', 'es demasiado largo.'],
            ['pattern_mismatch', 'tiene un formato inválido.'],
            ['not_in_enum', 'no es una opción permitida.'],
            ['invalid_integer', 'debe ser un entero válido.'],
            ['invalid_number', 'debe ser un número válido.'],
            ['below_minimum', 'está por debajo del mínimo.'],
            ['above_maximum', 'está por encima del máximo.'],
            ['invalid_boolean', 'debe ser un booleano.'],
        ];
    }

    /**
     */
    #[DataProvider('closedSetProvider')]
    public function testEveryClosedSetCodeIsTranslatedAndCarriesNoEnglishSuffix(string $code, string $expectedFragment): void
    {
        $messages = new SpanishFieldErrorMessages();

        $result = $messages(new FieldError($code, 'Tema is not valid.'), $this->field('Tema'));

        self::assertStringStartsWith('Tema ', $result);
        self::assertStringContainsString($expectedFragment, $result);
        self::assertStringNotContainsString('is ', $result, 'ningún sufijo inglés del binder debe sobrevivir');
    }

    public function testUnknownCodeFallsBackToTheErrorsOwnMessage(): void
    {
        $messages = new SpanishFieldErrorMessages();

        $result = $messages(new FieldError('some_future_code', 'A brand new message.'), $this->field('Campo'));

        self::assertSame('A brand new message.', $result);
    }
}
