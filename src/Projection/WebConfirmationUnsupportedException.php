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

namespace Milpa\Admin\Projection;

/**
 * Incompatibilidad de superficie (enmienda 2 del gate de P5.4): el tool es VÁLIDO — declara una
 * ceremonia de confirmación que ESTA superficie (el form web del admin) todavía no sabe
 * representar. Mañana la CLI sí sabrá, y el tool no habrá cambiado. No es error del usuario y no
 * se presenta como tal; es una excepción tipada de desarrollador de superficie.
 */
final class WebConfirmationUnsupportedException extends \LogicException
{
    public const CODE = 'MILPA_WEB_CONFIRMATION_UNSUPPORTED';

    /** Una herramienta que exige confirmación no tiene forma de pedirla en esta superficie. */
    public static function forTool(string $toolName): self
    {
        return new self(
            '[' . self::CODE . "] El tool '{$toolName}' requiere confirmación y esta superficie web "
            . 'no sabe representar esa ceremonia todavía. El tool es válido; la superficie es la que '
            . 'no soporta el confirm. Quita `confirm: true` del #[Tool] para esta superficie, o '
            . 'proyéctalo por una superficie que sí soporte confirmación (CLI/MCP).',
        );
    }
}
