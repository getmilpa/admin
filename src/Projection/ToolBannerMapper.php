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

use Milpa\ToolRuntime\ToolResult;

/**
 * Proyecta un `ToolResult` fallido al aviso form-level seguro. La regla dura del spec: JAMÁS
 * `$result->error` crudo al HTML — puede cargar detalle interno (paths, SQL, razones de policy).
 * El código original viaja en `FormBanner->code`; el mensaje es fijo y seguro por código.
 */
final class ToolBannerMapper
{
    /** Traduce el resultado de una herramienta al banner que la persona lee. */
    public function map(ToolResult $result): FormBanner
    {
        $code = (string) ($result->meta['code'] ?? 'ERROR');

        return match ($code) {
            ToolResult::FORBIDDEN => new FormBanner($code, BannerTone::Danger, 'No tienes permiso para ejecutar esta operación.'),
            ToolResult::RATE_LIMITED => new FormBanner($code, BannerTone::Warning, $this->rateLimitedMessage($result)),
            ToolResult::INTERNAL_ERROR => new FormBanner($code, BannerTone::Danger, 'La operación falló. Intenta de nuevo; si el problema persiste, revisa los registros del servidor.'),
            default => new FormBanner($code, BannerTone::Danger, "La operación no pudo completarse (código: {$code})."),
        };
    }

    private function rateLimitedMessage(ToolResult $result): string
    {
        $retry = $result->meta['retry_after_seconds'] ?? null;
        if (\is_int($retry) || \is_float($retry)) {
            return \sprintf('Demasiados intentos. Espera %d segundos e intenta de nuevo.', (int) $retry);
        }

        return 'Demasiados intentos. Espera un momento e intenta de nuevo.';
    }
}
