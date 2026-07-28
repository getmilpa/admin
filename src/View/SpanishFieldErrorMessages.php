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

namespace Milpa\Admin\View;

use Milpa\Live\Schema\FieldError;
use Milpa\Live\Schema\FormField;

/**
 * Traduce el primer error de validación de un campo a español mexicano para la superficie de Milpa
 * Admin. El `FormBinder` de milpa/live emite los mensajes en INGLÉS (paquete genérico,
 * render-target-agnostic), así que sin este resolver la UI española mezclaba idiomas: el label
 * traducido con el sufijo inglés del binder ("Nombre del sitio is required.").
 *
 * Se resuelve por el `code` estable del {@see FieldError} — un conjunto cerrado (required,
 * invalid_integer, invalid_number, invalid_boolean, below_minimum, above_maximum, too_short,
 * too_long, pattern_mismatch, not_in_enum) — nunca parseando el mensaje. Un `code` fuera del mapa
 * cae al `message` inglés del propio error: degrada, jamás peta. El adjetivo va en masculino (concuerda
 * con el sujeto implícito "el campo/el valor"), el default del español.
 *
 * Se inyecta en {@see \Milpa\Live\Rendering\SchemaFormHtmlRenderer::__construct()} como el
 * `messageResolver` — el host traduce en el borde de render, sin tocar el contrato congelado del binder.
 */
final class SpanishFieldErrorMessages
{
    /** @var array<string, string> `code` → plantilla con el marcador `{label}` */
    private const MESSAGES = [
        'required' => '{label} es obligatorio.',
        'too_short' => '{label} es demasiado corto.',
        'too_long' => '{label} es demasiado largo.',
        'pattern_mismatch' => '{label} tiene un formato inválido.',
        'not_in_enum' => '{label} no es una opción permitida.',
        'invalid_integer' => '{label} debe ser un entero válido.',
        'invalid_number' => '{label} debe ser un número válido.',
        'below_minimum' => '{label} está por debajo del mínimo.',
        'above_maximum' => '{label} está por encima del máximo.',
        'invalid_boolean' => '{label} debe ser un booleano.',
    ];

    public function __invoke(FieldError $error, FormField $field): string
    {
        $template = self::MESSAGES[$error->code] ?? null;
        if ($template === null) {
            return $error->message;
        }

        return str_replace('{label}', $field->label, $template);
    }
}
