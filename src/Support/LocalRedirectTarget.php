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

namespace Milpa\Admin\Support;

/**
 * Valida un destino de redirect declarado por el usuario (el `next` del gate). Acepta ÚNICAMENTE
 * una ruta local absoluta; cualquier intento de open-redirect (protocol-relative, URL absoluta,
 * backslash, o codificado) cae al destino seguro por defecto. Fail-closed.
 */
final class LocalRedirectTarget
{
    /** Un destino de redirección LOCAL y seguro, o el default. Fail-closed ante open-redirect. */
    public static function resolve(?string $candidate, string $default): string
    {
        if ($candidate === null || $candidate === '') {
            return $default;
        }

        // Decodificar ANTES de validar: PHP auto-decodifica los query params, así que %2f, %5c y
        // %09 llegan aquí como bytes crudos ('/', '\', tab). Validamos sobre la forma decodificada.
        $decoded = rawurldecode($candidate);

        // Rechazar backslashes (no son parte de rutas legítimas) y CUALQUIER carácter de control C0
        // (tab 0x09, CR 0x0D, LF 0x0A, etc.): el navegador los elimina de una URL antes de parsear,
        // así que `/\t/evil.com` en un header Location se convierte en `//evil.com` (open-redirect),
        // y CR/LF es vector de header-injection. Fail-closed.
        if (str_contains($decoded, '\\') || preg_match('/[\x00-\x1f]/', $decoded) === 1) {
            return $default;
        }

        // Debe empezar con '/' y NO con '//' (protocol-relative).
        if (! str_starts_with($decoded, '/') || str_starts_with($decoded, '//')) {
            return $default;
        }

        // Parsear como URL: no debe traer scheme ni host (una ruta local no los tiene).
        $parts = parse_url($decoded);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return $default;
        }

        // Devolver el CANDIDATO ORIGINAL (no el normalizado) para preservar el query intacto,
        // ya validado como local.
        return $candidate;
    }
}
