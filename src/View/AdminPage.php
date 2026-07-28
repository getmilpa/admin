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

use Milpa\Live\Support\MilpaDesign;

/**
 * Envuelve el fragmento del shell de Milpa Admin (la salida de `XhtmlComponentCompiler`) en una
 * página HTML completa, enlazando el CSS de `@milpa/design` cuando resuelve.
 *
 * DEUDA (spec §CSS): aquí se lee e inlinea el CSS desde su ruta resuelta en disco (dev/dogfood).
 * En build/deploy el asset de `@milpa/design` debe ir compilado/copiado al host — NUNCA una
 * ruta-local-absoluta como esta en producción.
 */
final class AdminPage
{
    /**
     * Envuelve el cuerpo en el documento completo del panel.
     *
     * @param array<int, string> $scripts URLs del runtime diferido (Alpine + Client Runtime Entry);
     *                                    cada una se emite como `<script src="..." defer>`.
     */
    public static function wrap(string $fragment, array $scripts = []): string
    {
        $styleTag = '';

        try {
            foreach (MilpaDesign::cssFiles() as $file) {
                // `is_file()` explícito en vez de `@file_get_contents`: el host lintea contra `@`
                // de supresión de errores; el guard documenta la misma tolerancia a un archivo
                // faltante sin silenciar errores reales de lectura.
                if (is_file($file)) {
                    $css = file_get_contents($file);
                    if ($css !== false) {
                        $styleTag .= '<style>' . $css . '</style>';
                    }
                }
            }
        } catch (\RuntimeException) {
            // @milpa/design no resuelto (MILPA_DESIGN_PATH sin setear y node_modules/@milpa/design
            // ausente): fallback estructural, sin estilos. La página sigue siendo válida y
            // navegable — el CSS nunca debe bloquear el cierre del shell.
            $styleTag = '';
        }

        // Reveal de controles JS-only (ADR#5): el toggle de navegación del topbar es JS-only, así
        // que se esconde salvo cuando milpa-live.js marca <html class="milpa-js"> (Alpine vivo).
        // Regla host-inline: SIEMPRE presente, independiente de que @milpa/design resuelva o no.
        // Nunca esconde contenido server-truth (ADR#8) — solo el control que sin JS no ejecuta.
        $jsRevealStyle = '<style>html:not(.milpa-js) .mui-topbar__nav-toggle{display:none!important}</style>';

        $scriptTags = '';
        foreach ($scripts as $src) {
            $scriptTags .= '<script src="' . htmlspecialchars((string) $src, \ENT_QUOTES, 'UTF-8') . '" defer></script>';
        }

        return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Milpa Admin</title>' . $styleTag . $jsRevealStyle . $scriptTags
            . '</head><body>' . $fragment . '</body></html>';
    }
}
